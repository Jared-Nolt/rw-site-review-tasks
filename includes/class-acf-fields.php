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
 * Capability map shared by all three post types, pinning every primitive
 * capability to `edit_others_posts` — the lowest capability an Editor has and an
 * Author or Contributor does not. The plugin's admin screens are therefore
 * invisible below Editor level, while the front-end dashboard and task page stay
 * open to assigned makers and marketing guides through srt_user_can_access_task()
 * regardless of their role.
 *
 * Only primitive capabilities belong here. Do NOT add the meta capabilities
 * `edit_post`, `read_post` or `delete_post`: register_post_type() feeds those
 * through _post_type_meta_capabilities(), which registers the mapping globally as
 * $post_type_meta_caps[ <custom> ] = <meta cap>. Aliasing them to a real
 * primitive would make map_meta_cap() reroute every site-wide
 * current_user_can( 'edit_others_posts' ) call — core's Posts and Pages screens
 * included — into a post-specific meta check with no post ID, which resolves to
 * `do_not_allow` and silently denies the capability everywhere.
 *
 * Leaving them out keeps the stock `capability_type => 'post'` meta caps, and
 * map_meta_cap() resolves those against the overridden primitives below.
 */
function srt_editor_post_type_capabilities() {
	$cap = 'edit_others_posts';

	return array(
		'edit_posts'             => $cap,
		'edit_others_posts'      => $cap,
		'edit_published_posts'   => $cap,
		'edit_private_posts'     => $cap,
		'publish_posts'          => $cap,
		'read_private_posts'     => $cap,
		'delete_posts'           => $cap,
		'delete_others_posts'    => $cap,
		'delete_published_posts' => $cap,
		'delete_private_posts'   => $cap,
		'create_posts'           => $cap,
	);
}

/**
 * Reads an ACF user field as a single user ID.
 *
 * The fields are configured for one user, but ACF hands back an array if the
 * value was ever saved as multiple — and `(int) array( 5 )` evaluates to 1,
 * which would lock the real assignee out and silently match user ID 1 instead.
 *
 * @return int User ID, or 0 when unset.
 */
function srt_get_assigned_user_id( $field, $post_id ) {
	$value = get_field( $field, $post_id );

	if ( is_array( $value ) ) {
		$value = reset( $value );
	}

	if ( is_object( $value ) && isset( $value->ID ) ) {
		$value = $value->ID;
	}

	return is_scalar( $value ) ? (int) $value : 0;
}

/**
 * Whether the current logged-in user may view/edit a given task: the assigned
 * maker or marketing guide, an admin, or — when the restrict-to-maker setting is
 * off — an Editor and above. Callers must check is_user_logged_in() first.
 */
function srt_user_can_access_task( $task_id ) {
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	$user_id = get_current_user_id();

	if (
		$user_id && (
			$user_id === srt_get_assigned_user_id( 'maker', $task_id ) ||
			$user_id === srt_get_assigned_user_id( 'marketing_guide', $task_id )
		)
	) {
		return true;
	}

	// The restrict-to-maker setting exists so a manager can see the full picture,
	// but the task page is an editable form — so switching it off opens the task
	// to Editors and above, not to every logged-in user. Otherwise any Subscriber
	// could rewrite a checklist, mark the task Completed and trigger its emails.
	return ! get_option( 'srt_restrict_to_maker', 1 ) && current_user_can( 'edit_others_posts' );
}

/**
 * Front-end /task/?task_id=# URL for a task, or '' when no task detail page is
 * configured in the plugin settings.
 */
function srt_task_page_url( $task_id ) {
	$task_page_id = (int) get_option( 'srt_task_page_id' );

	if ( ! $task_page_id ) {
		return '';
	}

	return add_query_arg( 'task_id', (int) $task_id, get_permalink( $task_page_id ) );
}

/**
 * "Maker: X | Marketing Guide: Y" line for a task, shared by the task page and the PDF views.
 */
function srt_format_people_line( $task_id ) {
	$maker_id           = srt_get_assigned_user_id( 'maker', $task_id );
	$marketing_guide_id = srt_get_assigned_user_id( 'marketing_guide', $task_id );

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
 * Week-of-month choices for the weekday due-date rule.
 */
function srt_week_of_month_choices() {
	return array(
		'first'  => 'First',
		'second' => 'Second',
		'third'  => 'Third',
		'fourth' => 'Fourth',
		'last'   => 'Last',
	);
}

/**
 * Weekday choices for the weekday due-date rule. Keys double as the strtotime
 * day names used by srt_weekday_date_in_month().
 */
function srt_weekday_choices() {
	return array(
		'monday'    => 'Monday',
		'tuesday'   => 'Tuesday',
		'wednesday' => 'Wednesday',
		'thursday'  => 'Thursday',
		'friday'    => 'Friday',
		'saturday'  => 'Saturday',
		'sunday'    => 'Sunday',
	);
}

/**
 * Resolves a week/weekday rule to a concrete date inside a given month, e.g.
 * ('last', 'wednesday', '2026-08') => '2026-08-26'. Returns '' on bad input.
 *
 * @param string $week      first|second|third|fourth|last
 * @param string $weekday   monday..sunday
 * @param string $year_month 'Y-m'
 */
function srt_weekday_date_in_month( $week, $weekday, $year_month ) {
	if ( ! isset( srt_week_of_month_choices()[ $week ] ) || ! isset( srt_weekday_choices()[ $weekday ] ) ) {
		return '';
	}

	// PHP's relative format handles the month-boundary math, including "last"
	// landing on the 4th or 5th occurrence depending on the month.
	$timestamp = strtotime( "{$week} {$weekday} of {$year_month}-01" );

	return $timestamp ? date( 'Y-m-d', $timestamp ) : '';
}

/**
 * A company's due-date rule, normalized: mode plus the week/weekday when the
 * mode is 'weekday'. Falls back to fixed_date for companies saved before the
 * rule fields existed.
 *
 * @return array{mode:string,week:string,weekday:string}
 */
function srt_company_due_rule( $company_id ) {
	$mode    = (string) get_field( 'due_date_mode', $company_id );
	$week    = (string) get_field( 'due_week', $company_id );
	$weekday = (string) get_field( 'due_weekday', $company_id );

	if ( 'weekday' !== $mode || ! isset( srt_week_of_month_choices()[ $week ] ) || ! isset( srt_weekday_choices()[ $weekday ] ) ) {
		return array( 'mode' => 'fixed_date', 'week' => '', 'weekday' => '' );
	}

	return array( 'mode' => 'weekday', 'week' => $week, 'weekday' => $weekday );
}

/**
 * Snaps a date to a company's due-date rule without changing its month, so a
 * hand-picked Next Due Date lands on the configured weekday. Fixed-date
 * companies get the date back untouched.
 */
function srt_company_apply_due_rule( $company_id, $date ) {
	$rule = srt_company_due_rule( $company_id );

	if ( 'weekday' !== $rule['mode'] || ! $date ) {
		return $date;
	}

	$resolved = srt_weekday_date_in_month( $rule['week'], $rule['weekday'], substr( $date, 0, 7 ) );

	return $resolved ? $resolved : $date;
}

/**
 * The due date one Review Interval after $date for a company.
 *
 * Fixed-date companies keep the calendar day. Weekday companies move forward
 * the same number of months and then re-resolve the week/weekday rule, so
 * "last Wednesday" stays the last Wednesday rather than drifting.
 */
function srt_company_advance_due_date( $company_id, $date, $months ) {
	$months = max( 1, (int) $months );
	$rule   = srt_company_due_rule( $company_id );

	if ( 'weekday' === $rule['mode'] ) {
		// Step months from the 1st so a 5-week month can't overflow into the
		// month after next the way "2026-01-31 +1 month" does.
		$next_month = date( 'Y-m', strtotime( substr( $date, 0, 7 ) . "-01 +{$months} months" ) );
		$resolved   = srt_weekday_date_in_month( $rule['week'], $rule['weekday'], $next_month );

		if ( $resolved ) {
			return $resolved;
		}
	}

	return date( 'Y-m-d', strtotime( "{$date} +{$months} months" ) );
}

/**
 * Human-readable due-date rule for admin listings, e.g. "Last Wednesday".
 * Returns '' for fixed-date companies, which need no explanation.
 */
function srt_company_due_rule_label( $company_id ) {
	$rule = srt_company_due_rule( $company_id );

	if ( 'weekday' !== $rule['mode'] ) {
		return '';
	}

	return sprintf(
		/* translators: 1: week of month (e.g. Last), 2: weekday (e.g. Wednesday) */
		__( '%1$s %2$s', 'rw-site-review-tasks' ),
		srt_week_of_month_choices()[ $rule['week'] ],
		srt_weekday_choices()[ $rule['weekday'] ]
	);
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
					'key'           => 'field_srt_company_due_mode',
					'label'         => 'Due Date Rule',
					'name'          => 'due_date_mode',
					'type'          => 'select',
					'choices'       => array(
						'fixed_date' => 'Same date each interval (e.g. the 15th)',
						'weekday'    => 'Same weekday each interval (e.g. last Wednesday)',
					),
					'default_value' => 'fixed_date',
					'allow_null'    => 0,
					'instructions'  => 'How the Next Due Date advances after each task is created.',
				),
				array(
					'key'               => 'field_srt_company_due_week',
					'label'             => 'Week',
					'name'              => 'due_week',
					'type'              => 'select',
					'choices'           => srt_week_of_month_choices(),
					'default_value'     => 'last',
					'allow_null'        => 0,
					'conditional_logic' => array(
						array(
							array(
								'field'    => 'field_srt_company_due_mode',
								'operator' => '==',
								'value'    => 'weekday',
							),
						),
					),
				),
				array(
					'key'               => 'field_srt_company_due_weekday',
					'label'             => 'Weekday',
					'name'              => 'due_weekday',
					'type'              => 'select',
					'choices'           => srt_weekday_choices(),
					'default_value'     => 'wednesday',
					'allow_null'        => 0,
					'conditional_logic' => array(
						array(
							array(
								'field'    => 'field_srt_company_due_mode',
								'operator' => '==',
								'value'    => 'weekday',
							),
						),
					),
				),
				array(
					'key'            => 'field_srt_company_due_date',
					'label'          => 'Next Due Date',
					'name'           => 'due_date',
					'type'           => 'date_picker',
					'display_format' => 'Y-m-d',
					'return_format'  => 'Y-m-d',
					'instructions'   => 'The plugin advances this automatically by one Review Interval each time a task is created. When the Due Date Rule is set to a weekday, the date you save here is snapped to the matching weekday in that same month.',
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
