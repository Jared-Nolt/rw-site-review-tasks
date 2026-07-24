<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the computed tag slug for a task: completed | needs_work | overdue | ready.
 * status is a manual choice (completed/needs_work); overdue/ready are derived from the period date.
 */
function srt_get_task_tag_slug( $task_id ) {
	$status = get_field( 'status', $task_id );

	if ( 'completed' === $status ) {
		return 'completed';
	}

	if ( 'needs_work' === $status ) {
		return 'needs_work';
	}

	$period = get_field( 'period', $task_id );
	$today  = current_time( 'Y-m-d' );

	if ( $period && $period < $today ) {
		return 'overdue';
	}

	return 'ready';
}

function srt_get_task_tag_label( $slug ) {
	$labels = array(
		'completed'  => __( 'Completed', 'rw-site-review-tasks' ),
		'needs_work' => __( 'Needs Work', 'rw-site-review-tasks' ),
		'overdue'    => __( 'Overdue', 'rw-site-review-tasks' ),
		'ready'      => __( 'Ready', 'rw-site-review-tasks' ),
	);

	return isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug;
}

function srt_get_task_tag( $task_id ) {
	return srt_get_task_tag_label( srt_get_task_tag_slug( $task_id ) );
}

/**
 * Splits a "one per line" textarea value into a clean array of choices.
 */
function srt_lines_to_array( $text ) {
	$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
	return array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
}

/**
 * Whether the current logged-in user may view/edit a given task: the assigned
 * maker, an admin, or anyone (if the restrict-to-maker setting is off).
 * Callers must check is_user_logged_in() themselves first.
 */
function srt_user_can_access_task( $task_id ) {
	if ( ! get_option( 'srt_restrict_to_maker', 1 ) || current_user_can( 'manage_options' ) ) {
		return true;
	}

	$user_id          = get_current_user_id();
	$maker_id         = (int) get_field( 'maker', $task_id );
	$marketing_guide_id = (int) get_field( 'marketing_guide', $task_id );

	return $user_id === $maker_id || $user_id === $marketing_guide_id;
}

/**
 * "Maker: X | Marketing Guide: Y" line for a task, shared by the task page and the PDF views.
 */
function srt_format_people_line( $task_id ) {
	$maker_id           = get_field( 'maker', $task_id );
	$marketing_guide_id = get_field( 'marketing_guide', $task_id );

	$maker_user = $maker_id ? get_userdata( $maker_id ) : false;
	$mg_user    = $marketing_guide_id ? get_userdata( $marketing_guide_id ) : false;

	return sprintf(
		/* translators: 1: maker name, 2: marketing guide name */
		esc_html__( 'Maker: %1$s | Marketing Guide: %2$s', 'rw-site-review-tasks' ),
		$maker_user ? esc_html( $maker_user->display_name ) : '&#8212;',
		$mg_user ? esc_html( $mg_user->display_name ) : '&#8212;'
	);
}

/**
 * Default Executive Summary prefilled on each new review task. Shown at the top
 * of the report and editable per client afterwards.
 */
function srt_default_executive_summary() {
	return __( "This monthly review confirms the site's stability, security, and optimal performance. Key updates and scans were conducted, and the overall website health is in good standing. Minor issues were resolved as detailed below.", 'rw-site-review-tasks' );
}

/**
 * Local ACF field groups, shipped with the plugin so the schema is portable
 * and doesn't depend on manual ACF UI setup.
 */
function srt_register_acf_fields() {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_local_field_group(
		array(
			'key'      => 'group_srt_company',
			'title'    => 'Company Details',
			'fields'   => array(
				array(
					'key'          => 'field_srt_company_website_url',
					'label'        => 'Website URL',
					'name'         => 'website_url',
					'type'         => 'url',
					'instructions' => 'The company\'s website address (e.g. https://example.com).',
					'placeholder'  => 'https://',
				),
				array(
					'key'           => 'field_srt_company_psi_enabled',
					'label'         => 'Run PageSpeed scan',
					'name'          => 'psi_enabled',
					'type'          => 'true_false',
					'default_value' => 1,
					'ui'            => 1,
					'instructions'  => 'Include Google PageSpeed Insights (Lighthouse) results for the homepage in each site scan. Add a PageSpeed API key under Settings for higher quota.',
				),
				array(
					'key'               => 'field_srt_company_psi_strategy',
					'label'             => 'PageSpeed Strategy',
					'name'              => 'psi_strategy',
					'type'              => 'select',
					'choices'           => array(
						'mobile'  => 'Mobile',
						'desktop' => 'Desktop',
					),
					'default_value'     => 'mobile',
					'allow_null'        => 0,
					'conditional_logic' => array(
						array(
							array(
								'field'    => 'field_srt_company_psi_enabled',
								'operator' => '==',
								'value'    => '1',
							),
						),
					),
				),
				array(
					'key'           => 'field_srt_company_maker',
					'label'         => 'Maker',
					'name'          => 'maker',
					'type'          => 'user',
					'return_format' => 'id',
					'role'          => '',
					'allow_null'    => 0,
					'multiple'      => 0,
				),
				array(
					'key'           => 'field_srt_company_marketing_guide',
					'label'         => 'Marketing Guide',
					'name'          => 'marketing_guide',
					'type'          => 'user',
					'return_format' => 'id',
					'role'          => '',
					'allow_null'    => 1,
					'multiple'      => 0,
					'instructions'  => 'The marketing guide (MG) assigned to this company.',
				),
				array(
					'key'           => 'field_srt_company_active',
					'label'         => 'Active',
					'name'          => 'active',
					'type'          => 'true_false',
					'default_value' => 1,
					'ui'            => 1,
					'instructions'  => 'Inactive companies are skipped by the daily task-creation check.',
				),
				array(
					'key'           => 'field_srt_company_checklist_template',
					'label'         => 'Checklist',
					'name'          => 'checklist_template',
					'type'          => 'post_object',
					'post_type'     => array( 'rwsrt_checklist' ),
					'return_format' => 'id',
					'multiple'      => 0,
					'ui'            => 1,
					'instructions'  => 'Copied onto each new review task when it is created.',
				),
				array(
					'key'           => 'field_srt_company_interval',
					'label'         => 'Review Interval',
					'name'          => 'interval',
					'type'          => 'select',
					'choices'       => array(
						'monthly'       => 'Monthly',
						'quarterly'     => 'Quarterly',
						'semi_annually' => 'Semi-Annually',
						'annually'      => 'Annually',
					),
					'default_value' => 'monthly',
					'allow_null'    => 0,
				),
				array(
					'key'            => 'field_srt_company_due_date',
					'label'          => 'Next Due Date',
					'name'           => 'due_date',
					'type'           => 'date_picker',
					'display_format' => 'Y-m-d',
					'return_format'  => 'Y-m-d',
					'instructions'   => 'The plugin advances this automatically by one Review Interval each time a task is created.',
				),
				array(
					'key'           => 'field_srt_company_lead_days',
					'label'         => 'Lead Days',
					'name'          => 'lead_days',
					'type'          => 'number',
					'default_value' => 0,
					'min'           => 0,
					'instructions'  => 'Create the review task this many days before the due date.',
				),
				array(
					'key'          => 'field_srt_company_scan_pages',
					'label'        => 'Additional Pages to Scan',
					'name'         => 'scan_pages',
					'type'         => 'repeater',
					'max'          => 5,
					'layout'       => 'table',
					'button_label' => 'Add Page',
					'instructions' => 'Up to 5 extra pages to include in the scan (e.g. /about, /contact). The homepage is always scanned first.',
					'sub_fields'   => array(
						array(
							'key'         => 'field_srt_scan_page_url',
							'label'       => 'Page URL',
							'name'        => 'page_url',
							'type'        => 'url',
							'placeholder' => 'https://',
						),
					),
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'rwsrt_company',
					),
				),
			),
		)
	);

	acf_add_local_field_group(
		array(
			'key'      => 'group_srt_checklist',
			'title'    => 'Checklist Items',
			'fields'   => array(
				array(
					'key'          => 'field_srt_checklist_items',
					'label'        => 'Items',
					'name'         => 'items',
					'type'         => 'repeater',
					'layout'       => 'table',
					'button_label' => 'Add Item',
					'sub_fields'   => array(
						array(
							'key'          => 'field_srt_checklist_item_section',
							'label'        => 'Section',
							'name'         => 'section',
							'type'         => 'text',
							'instructions' => 'Items sharing a Section are grouped under one heading on the report (e.g. "Security Overview"). Leave blank for no heading.',
						),
						array(
							'key'           => 'field_srt_checklist_item_type',
							'label'         => 'Type',
							'name'          => 'field_type',
							'type'          => 'select',
							'choices'       => array(
								'radio'    => 'Radio (custom choices, pick one)',
								'checkbox' => 'Checkbox (custom choices, pick any)',
								'textarea' => 'Textarea (free text)',
								'gallery'  => 'Gallery (image uploads)',
							),
							'default_value' => 'checkbox',
							'allow_null'    => 0,
						),
						array(
							'key'   => 'field_srt_checklist_item_label',
							'label' => 'Label',
							'name'  => 'label',
							'type'  => 'text',
						),
						array(
							'key'               => 'field_srt_checklist_item_choices',
							'label'             => 'Choices',
							'name'              => 'choices',
							'type'              => 'textarea',
							'rows'              => 3,
							'instructions'      => 'One choice per line.',
							'conditional_logic' => array(
								array(
									array(
										'field'    => 'field_srt_checklist_item_type',
										'operator' => '==',
										'value'    => 'radio',
									),
								),
								array(
									array(
										'field'    => 'field_srt_checklist_item_type',
										'operator' => '==',
										'value'    => 'checkbox',
									),
								),
							),
						),
					),
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'rwsrt_checklist',
					),
				),
			),
		)
	);

	acf_add_local_field_group(
		array(
			'key'      => 'group_srt_task',
			'title'    => 'Task Details',
			'fields'   => array(
				array(
					'key'           => 'field_srt_task_company',
					'label'         => 'Company',
					'name'          => 'company',
					'type'          => 'post_object',
					'post_type'     => array( 'rwsrt_company' ),
					'return_format' => 'id',
					'multiple'      => 0,
					'ui'            => 1,
				),
				array(
					'key'           => 'field_srt_task_maker',
					'label'         => 'Maker',
					'name'          => 'maker',
					'type'          => 'user',
					'return_format' => 'id',
					'multiple'      => 0,
				),
				array(
					'key'           => 'field_srt_task_marketing_guide',
					'label'         => 'Marketing Guide',
					'name'          => 'marketing_guide',
					'type'          => 'user',
					'return_format' => 'id',
					'multiple'      => 0,
				),
				array(
					'key'             => 'field_srt_task_period',
					'label'           => 'Period',
					'name'            => 'period',
					'type'            => 'date_picker',
					'display_format'  => 'Y-m-d',
					'return_format'   => 'Y-m-d',
					'instructions'    => 'The due date this review task is for; used to compute Ready/Overdue.',
				),
				array(
					'key'           => 'field_srt_task_executive_summary',
					'label'         => 'Executive Summary',
					'name'          => 'executive_summary',
					'type'          => 'textarea',
					'rows'          => 4,
					'default_value' => srt_default_executive_summary(),
					'instructions'  => 'Shown at the top of the report. Prefilled with a standard summary; edit per client as needed.',
				),
				array(
					'key'          => 'field_srt_task_checklist',
					'label'        => 'Checklist',
					'name'         => 'checklist',
					'type'         => 'repeater',
					'layout'       => 'table',
					'button_label' => 'Add Item',
					'instructions' => 'Snapshotted from the company\'s checklist when this task was created. Structure is locked; only Answer is editable here.',
					'sub_fields'   => array(
						array(
							'key'      => 'field_srt_task_checklist_section',
							'label'    => 'Section',
							'name'     => 'section',
							'type'     => 'text',
							'readonly' => 1,
						),
						array(
							'key'           => 'field_srt_task_checklist_type',
							'label'         => 'Type',
							'name'          => 'field_type',
							'type'          => 'select',
							'choices'       => array(
								'radio'    => 'Radio',
								'checkbox' => 'Checkbox',
								'textarea' => 'Textarea',
								'gallery'  => 'Gallery',
							),
							'readonly'      => 1,
						),
						array(
							'key'      => 'field_srt_task_checklist_label',
							'label'    => 'Label',
							'name'     => 'label',
							'type'     => 'text',
							'readonly' => 1,
						),
						array(
							'key'      => 'field_srt_task_checklist_choices',
							'label'    => 'Choices',
							'name'     => 'choices',
							'type'     => 'textarea',
							'rows'     => 2,
							'readonly' => 1,
						),
						array(
							'key'   => 'field_srt_task_checklist_answer',
							'label' => 'Answer',
							'name'  => 'answer',
							'type'  => 'text',
						),
					),
				),
				array(
					'key'          => 'field_srt_task_extra_notes',
					'label'        => 'Extra Notes',
					'name'         => 'extra_notes',
					'type'         => 'textarea',
					'rows'         => 4,
					'instructions' => 'A free-form summary the maker can add at the end of the review. Only included in the Marketing Guide PDF, not the standard one.',
				),
				array(
					'key'           => 'field_srt_task_status',
					'label'         => 'Status',
					'name'          => 'status',
					'type'          => 'radio',
					'choices'       => array(
						'completed'  => 'Completed',
						'needs_work' => 'Needs Work',
					),
					'allow_null'    => 1,
					'default_value' => '',
					'layout'        => 'horizontal',
					'instructions'  => 'Leave unset until the review is finished; the dashboard shows Ready/Overdue automatically until then.',
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'rwsrt_task',
					),
				),
			),
		)
	);
}
add_action( 'acf/init', 'srt_register_acf_fields' );
