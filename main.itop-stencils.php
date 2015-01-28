<?php

// Copyright (C) 2015 Combodo SARL
//
//   This file is part of iTop.
//
//   iTop is free software; you can redistribute it and/or modify	
//   it under the terms of the GNU Affero General Public License as published by
//   the Free Software Foundation, either version 3 of the License, or
//   (at your option) any later version.
//
//   iTop is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU Affero General Public License for more details.
//
//   You should have received a copy of the GNU Affero General Public License
//   along with iTop. If not, see <http://www.gnu.org/licenses/>


class iTopStencils implements iApplicationObjectExtension
{
	//////////////////////////////////////////////////
	// Implementation of iApplicationObjectExtension
	//////////////////////////////////////////////////

	public function OnIsModified($oObject)
	{
		return false;
	}

	public function OnCheckToWrite($oObject)
	{
		$sStateAttCode = MetaModel::GetStateAttributeCode(get_class($oObject));
		if (!is_null($sStateAttCode))
		{
			$aChanges = $oObject->ListChanges();
			if (array_key_exists($sStateAttCode, $aChanges))
			{
				$oObject->_stencils_reaching_state = $aChanges[$sStateAttCode];
			}
		}
	}

	public function OnCheckToDelete($oObject)
	{
	}

	public function OnDBUpdate($oObject, $oChange = null)
	{
		if (isset($oObject->_stencils_reaching_state))
		{
			$sReachedState = $oObject->_stencils_reaching_state;
			unset($oObject->_stencils_reaching_state); // Prevent the rentrance
			$this->OnReachingState($oObject, $sReachedState);
		}
	}

	public function OnDBInsert($oObject, $oChange = null)
	{
		$this->OnReachingState($oObject, null);

		if (isset($oObject->_stencils_reaching_state))
		{
			$sReachedState = $oObject->_stencils_reaching_state;
			unset($oObject->_stencils_reaching_state); // Prevent the rentrance
			$this->OnReachingState($oObject, $sReachedState);
		}
	}

	public function OnDBDelete($oObject, $oChange = null)
	{
	}

	//////////////////////////////////////////////////
	// Helpers
	//////////////////////////////////////////////////

	/**
	 * Get the rule for a given class (still requires additional filtering)
	 * 	 	 
	 * @param object oObject
	 * @param string $sReachedState The new state. Null means "just created" (does not require a lifecycle)
	 */	 	
	protected function OnReachingState($oObject, $sReachedState = null)
	{
		try
		{
			$aRules = $this->GetRules(get_class($oObject), $sReachedState);
			foreach ($aRules as $aRuleData)
			{
				// check scope
				$oSearch = DBObjectSearch::FromOQL($aRuleData['trigger_scope']);
				$oSearch->AddCondition('id', $oObject->GetKey(), '=');
				$oSet = new DBObjectSet($oSearch);
				if ($oSet->Count() > 0)
				{
					$this->ExecuteRule($oObject, $aRuleData);
				}
			}
		}
		catch (Exception $e)
		{
			IssueLog::Error('itop-stencils: '.$e->getMessage());
			$aTrace = $e->getTrace();
			IssueLog::Error('itop-stencils: '.print_r($aTrace, true));
		}
	}

	/**
	 * Get the rule for a given class (still requires additional filtering)
	 * 	 	 
	 * @param string sClass
	 * @param string $sReachedState The new state. Null means "just created" (does not require a lifecycle)
	 */	 	
	protected function GetRules($sClass, $sReachedState = null)
	{
		static $aRules = null;
		if (is_null($aRules))
		{
			$aRawRules = MetaModel::GetModuleSetting('itop-stencils', 'rules', array());
			$aRules = array();
			foreach ($aRawRules as $aRuleData)
			{
				$sTriggerClass = $aRuleData['trigger_class'];
				if (isset($aRuleData['trigger_state']))
				{
					$sTriggerState = $aRuleData['trigger_state'];
					$aRules[$sTriggerClass.'/'.$sTriggerState][] = $aRuleData;
				}
				else
				{
					$aRules[$sTriggerClass][] = $aRuleData;
				}
			}
		}
		$sRuleKey = is_null($sReachedState) ? $sClass : $sClass.'/'.$sReachedState;
		if (array_key_exists($sRuleKey, $aRules))
		{
			return $aRules[$sRuleKey];
		}
		else
		{
			return array();
		}
	}

	protected function ExecuteRule($oObject, $aRuleData)
	{
		$oSearch = DBObjectSearch::FromOQL($aRuleData['templates']);
		$aQueryArgs = $oObject->ToArgs('trigger');
		$oTemplates = new DBObjectSet($oSearch, array(), $aQueryArgs);
		if ($oTemplates->Count() > 0)
		{
			while ($oTemplate = $oTemplates->Fetch())
			{
				$this->CopyTemplate($oObject, $aRuleData, $oTemplate);
			}

			iTopObjectCopier::ExecActions($aRuleData['retrofit'], $oObject, $oObject);
			$oObject->DBUpdate();

			$sMessage = self::FormatMessage($aRuleData, 'report_label');
			if (strlen(trim($sMessage)) > 0)
			{
				cmdbAbstractObject::SetSessionMessage(get_class($oObject), $oObject->GetKey(), 'stencils', $sMessage, 'info', 0, true /* must not exist */);
			}
		}
	}

	/**
	 * Instantiate a template, recursing if necessary
	 */	 	
	protected function CopyTemplate($oObject, $aRuleData, $oTemplate, $oParentCopy = null)
	{
		$oCopy = MetaModel::NewObject($aRuleData['copy_class']);
		iTopObjectCopier::AddExecContextObject($oObject, 'trigger');
		iTopObjectCopier::ExecActions($aRuleData['copy_actions'], $oTemplate, $oCopy);

		if (!is_null($oParentCopy))
		{
			if ($aRuleData['copy_hierarchy'])
			{
				$sCopyParentAttCode = self::GetParentAttCode($aRuleData['copy_class']);
				if (is_null($sCopyParentAttCode))
				{
					throw new Exception('copy_hierarchy cannot be enabled because '.$aRuleData['copy_class']. ' does not have any hierarchical key');
				}
			}
			$oCopy->Set($sCopyParentAttCode, $oParentCopy->GetKey());
		}

		$oCopy->DBInsert();

		if ($aRuleData['copy_hierarchy'])
		{
			// Recurse on the templates below the current one
			//
			$sTemplateParentAttCode = self::GetParentAttCode(get_class($oTemplate));
			if (is_null($sTemplateParentAttCode))
			{
				throw new Exception('copy_hierarchy cannot be enabled because '.get_class($oTemplate). ' does not have any hierarchical key');
			}

			$oSubSearch = new DBObjectSearch(get_class($oTemplate));
			$oSubSearch->AddCondition($sTemplateParentAttCode, $oTemplate->GetKey());
			$oSubset = new DBObjectSet($oSubSearch);
			while ($oSubItem = $oSubset->Fetch())
			{
				$this->CopyTemplate($oObject, $aRuleData, $oSubItem, $oCopy);
			}
		}
	}

	protected static function GetParentAttCode($sClass)
	{
		static $aParentAttCodes = array();
		if (!array_key_exists($sClass, $aParentAttCodes))
		{
			$aParentAttCodes[$sClass] = null;
			foreach (MetaModel::ListAttributeDefs($sClass) as $sAttCode => $oAttDef)
			{
				if ($oAttDef instanceof AttributeHierarchicalKey)
				{
					$aParentAttCodes[$sClass] = $sAttCode;
					break;
				} 
			}
		}
		return $aParentAttCodes[$sClass];
	}

	/**
	 * Format the labels depending on the rule settings, and defaulting to dictionary entries
	 * @param aRuleData Rule settings
	 * @param sMsgCode The code in the rule settings and default dictionary (e.g. menu_label, defaulting to stencils:menu_label:default)
	 * @param oSourceObject Optional: the source object	 	 	 
	 */	 	
	public static function FormatMessage($aRuleData, $sMsgCode, $oSourceObject = null)
	{
		$sLangCode = Dict::GetUserLanguage();
		$sCodeWithLang = $sMsgCode.'/'.$sLangCode;
		if (isset($aRuleData[$sCodeWithLang]) && strlen($aRuleData[$sCodeWithLang]) > 0)
		{
			if ($oSourceObject)
			{
				$sRet = sprintf($aRuleData[$sCodeWithLang], $oSourceObject->GetHyperlink());
			}
			else
			{
				$sRet = $aRuleData[$sCodeWithLang];
			}
		}
		else
		{
			if (isset($aRuleData[$sMsgCode]) && strlen($aRuleData[$sMsgCode]) > 0)
			{
				$sDictEntry = $aRuleData[$sMsgCode];
			}
			else
			{
				$sDictEntry = 'stencils:'.$sMsgCode.':default';
			}
			if ($oSourceObject)
			{
				// The format function does not format if the string is not a dictionary entry
				// so we do it ourselves here
				$sFormat = Dict::S($sDictEntry);
				$sRet = sprintf($sFormat, $oSourceObject->GetHyperlink());
			}
			else
			{
				$sRet = Dict::S($sDictEntry);
			}
		}
		return $sRet;
	}
}
