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
				try
				{
					self::CheckRule($aRuleData);

					// check scope
					$oSearch = DBObjectSearch::FromOQL($aRuleData['trigger_scope']);
					$oSearch->AddCondition('id', $oObject->GetKey(), '=');
					$oSet = new DBObjectSet($oSearch);
					if ($oSet->Count() > 0)
					{
							$this->ExecuteRule($oObject, $aRuleData);
					}
				}
				catch (Exception $e)
				{
					throw new Exception('rule #'.$aRuleData['id'].' - '.$e->getMessage());
				}
			}
		}
		catch (Exception $e)
		{
			IssueLog::Error('itop-stencils: '.$e->getMessage());
			//$aTrace = $e->getTrace();
			//IssueLog::Error('itop-stencils: '.print_r($aTrace, true));
		}
	}

	/**
	 * Checks the structure and logs errors if issues have been encountered
	 */
	public static function CheckRule($aRuleData)
	{
		if (($aRuleData['trigger_class'] != '') && !MetaModel::IsValidClass($aRuleData['trigger_class']))
		{
			throw new Exception('Parameter trigger_class: "'.$aRuleData['trigger_class'].'" is not a valid class');
		}
		if (!isset($aRuleData['trigger_scope']))
		{
			throw new Exception('Missing parameter "trigger_scope"');
		}
		try
		{
			$oTest = DBObjectSearch::FromOQL($aRuleData['trigger_scope']);
		}
		catch (Exception $e)
		{
			throw new Exception('Parameter trigger_scope: '.$e->getMessage());
		}
		if (!MetaModel::IsSameFamilyBranch($oTest->GetClass(), $aRuleData['trigger_class']))
		{
			throw new Exception('Parameters trigger_class "'.$aRuleData['trigger_class'].'" and trigger_scope "'.$aRuleData['trigger_scope'].'" are not compatible (class mismatch)');
		}
		if (!isset($aRuleData['trigger_state']))
		{
			throw new Exception('Missing parameter "trigger_state"');
		}
		if (!isset($aRuleData['templates']))
		{
			throw new Exception('Missing parameter "templates"');
		}
		try
		{
			$oTest = DBObjectSearch::FromOQL($aRuleData['templates']);
		}
		catch (Exception $e)
		{
			throw new Exception('Parameter templates: '.$e->getMessage());
		}
		$sTemplateClass = $oTest->GetClass();
		if (($aRuleData['copy_class'] != '') && !MetaModel::IsValidClass($aRuleData['copy_class']))
		{
			throw new Exception('Parameter copy_class: "'.$aRuleData['copy_class'].'" is not a valid class');
		}
		if (!is_array($aRuleData['copy_actions']))
		{
			throw new Exception('Parameter copy_actions: must be an array');
		}
		if (isset($aRuleData['retrofit_from_copy']))
		{
			if (!is_array($aRuleData['retrofit_from_copy']))
			{
				throw new Exception('Parameter retrofit_from_copy: must be an array');
			}
		}
		if (!is_array($aRuleData['retrofit']))
		{
			throw new Exception('Parameter retrofit: must be an array');
		}
		if (isset($aRuleData['copy_hierarchy']))
		{
			if (!is_array($aRuleData['copy_hierarchy']))
			{
				throw new Exception('Parameter: copy_hierarchy must be an array');
			}
			if (!isset($aRuleData['copy_hierarchy']['template_parent_attcode']))
			{
				throw new Exception('Missing parameter "copy_hierarchy/template_parent_attcode"');
			}
			if (!MetaModel::IsValidAttCode($sTemplateClass, $aRuleData['copy_hierarchy']['template_parent_attcode']))
			{
				throw new Exception('Parameter copy_hierarchy/template_parent_attcode: '.$aRuleData['copy_hierarchy']['template_parent_attcode'].' is not a valid attribute for class '.$sTemplateClass);
			}
			if (!isset($aRuleData['copy_hierarchy']['copy_parent_attcode']))
			{
				throw new Exception('Missing parameter "copy_hierarchy/copy_parent_attcode"');
			}
			if (!MetaModel::IsValidAttCode($aRuleData['copy_class'], $aRuleData['copy_hierarchy']['copy_parent_attcode']))
			{
				throw new Exception('Parameter copy_hierarchy/copy_parent_attcode: '.$aRuleData['copy_hierarchy']['copy_parent_attcode'].' is not a valid attribute for class '.$aRuleData['copy_class']);
			}
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
			foreach ($aRawRules as $iRule => $aRuleData)
			{
				$aRuleData['id'] = $iRule;
				$sTriggerClass = $aRuleData['trigger_class'];
				if (!empty($aRuleData['trigger_state']))
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
		// Workaround bugs found in DBObject::ToArgs and fixed in iTop > 2.1.0
		// Reload the object so as to reset the cache of ToArgs (not invalidated as expected)
		$oObject = MetaModel::GetObject(get_class($oObject), $oObject->GetKey());

		$oSearch = DBObjectSearch::FromOQL($aRuleData['templates']);
		$aQueryArgs = $oObject->ToArgs('trigger');
		$oTemplates = new DBObjectSet($oSearch, array(), $aQueryArgs);
		if ($oTemplates->Count() > 0)
		{
			while ($oTemplate = $oTemplates->Fetch())
			{
				$oCopy = $this->CopyTemplate($oObject, $aRuleData, $oTemplate);
				iTopObjectCopier::ExecActions($aRuleData['retrofit_from_copy'], $oCopy, $oObject);
			}

			iTopObjectCopier::ExecActions($aRuleData['retrofit'], $oObject, $oObject);
			$oObject->DBUpdate();

			$sMessage = self::FormatMessage($aRuleData, 'report_label');
			if (strlen(trim($sMessage)) > 0)
			{
				cmdbAbstractObject::SetSessionMessage(get_class($oObject), $oObject->GetKey(), 'stencils'.$aRuleData['id'], $sMessage, 'info', 0, true /* must not exist */);
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

		$aHierarchyData = isset($aRuleData['copy_hierarchy']) ? $aRuleData['copy_hierarchy'] : null;

		if (!is_null($oParentCopy))
		{
			if (!is_null($aHierarchyData))
			{
				$sCopyParentAttCode = $aHierarchyData['copy_parent_attcode'];
				if (empty($sCopyParentAttCode))
				{
					throw new Exception('Missing copy_parent_attcode');
				}
				$oCopy->Set($sCopyParentAttCode, $oParentCopy->GetKey());
			}
		}

		$oCopy->DBInsert();

		if (!is_null($aHierarchyData))
		{
			// Recurse on the templates below the current one
			//
			$sTemplateParentAttCode = $aHierarchyData['template_parent_attcode'];
			if (empty($sTemplateParentAttCode))
			{
				throw new Exception('Missing template_parent_attcode');
			}
			$oSubSearch = new DBObjectSearch(get_class($oTemplate));
			$oSubSearch->AddCondition($sTemplateParentAttCode, $oTemplate->GetKey());
			$oSubset = new DBObjectSet($oSubSearch);
			while ($oSubItem = $oSubset->Fetch())
			{
				$this->CopyTemplate($oObject, $aRuleData, $oSubItem, $oCopy);
			}
		}

		return $oCopy;
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
