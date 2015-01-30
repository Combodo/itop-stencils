<?php
//
// iTop module definition file
//

SetupWebPage::AddModule(
	__FILE__, // Path to the current file, all other file names are relative to the directory containing this file
	'itop-stencils/0.1.0',
	array(
		// Identification
		//
		'label' => 'Stencils',
		'category' => 'tooling',

		// Setup
		//
		'dependencies' => array(
			'itop-object-copier/1.1.0'
		),
		'mandatory' => false,
		'visible' => true,

		// Components
		//
		'datamodel' => array(
			'main.itop-stencils.php'
		),
		'webservice' => array(
			
		),
		'data.struct' => array(
			// add your 'structure' definition XML files here,
		),
		'data.sample' => array(
			// add your sample data XML files here,
		),
		
		// Documentation
		//
		'doc.manual_setup' => '', // hyperlink to manual setup documentation, if any
		'doc.more_information' => '', // hyperlink to more information, if any 

		// Default settings
		//
		'settings' => array(
			'rules' => array(
				array(
					'name' => 'irrealistic test',
					'trigger_class' => 'UserRequest',
					'trigger_scope' => 'SELECT UserRequest WHERE org_id = 1',
					'trigger_state' => 'assigned', // triggered when reaching this state
					'report_label' => 'A task list has been created for the ticket', // Label or dictionary entry
					'report_label/FR FR' => 'Une liste de tâche a été créée pour ce ticket',
					'templates' => 'SELECT Organization WHERE id = :trigger->org_id', // A query to define how to look for the templates
					'copy_class' => 'WorkOrder', // Class of the copied templates
					'copy_actions' => array( // Series of actions to preset the object
						'set(name,Test bizarre)',
						'set(ticket_id,$trigger->id$)',
						'set(team_id,$trigger->team_id$)',
						'set(agent_id,$trigger->agent_id$)',
						'set(description,$this->code$)',
					),
					'copy_hierarchy' => array(
						'template_parent_attcode' => 'parent_id',
						'copy_parent_attcode' => 'workorder_parent_id'
					),
					'retrofit' => array( // Series of actions to retrofit some information from the created object to the source object
						'set(private_log,On a fait des choses\\, on verra bien ce qu\'il se passe)',
						'apply_stimulus(ev_pending)',
					),
				),
			),
		),
	)
);


?>
