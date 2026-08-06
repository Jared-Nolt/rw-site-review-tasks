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
	// Display label only — the stored value/slug stays 'needs_work' everywhere
	// (meta value, webhook payload, tag slug) so existing data, integrations,
	// and the overdue/ready logic in srt_get_task_tag_slug() are unaffected.
	$labels = array(
		'completed'  => __( 'Completed', 'rw-site-review-tasks' ),
		'needs_work' => __( 'Save Draft', 'rw-site-review-tasks' ),
		'overdue'    => __( 'Overdue', 'rw-site-review-tasks' ),
		'ready'      => __( 'Ready', 'rw-site-review-tasks' ),
	);

	return isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug;
}

function srt_get_task_tag( $task_id ) {
	return srt_get_task_tag_label( srt_get_task_tag_slug( $task_id ) );
}

/**
 * Checklist storage — a plain post meta array, not an ACF field. Each row is
 * a plain associative array: { section, label, answer }. Used for both a
 * Company's template checklist and a Task's own per-review snapshot.
 *
 * This deliberately bypasses ACF's repeater field type. ACF's repeater
 * stores each row's cells as their own separate wp_postmeta rows (e.g. a
 * 5-row, 5-column repeater is dozens of individual database writes per
 * save) — that many-small-writes shape was the root cause of a real,
 * observed bug where a single cell's value silently failed to persist while
 * the rest of the row saved fine. Storing the whole checklist as one PHP
 * array under one meta key makes a save a single atomic database write,
 * which removes that entire failure mode. See instructions.md.
 */
function srt_get_checklist( $post_id ) {
	$rows = get_post_meta( $post_id, 'srt_checklist', true );
	return is_array( $rows ) ? array_values( $rows ) : array();
}

/**
 * Saves a post's checklist. Every row is normalized to exactly { section,
 * label, answer } so nothing downstream ever has to guess at the shape or
 * defend against a missing key.
 */
function srt_update_checklist( $post_id, $rows ) {
	$clean = array();

	foreach ( (array) $rows as $row ) {
		$clean[] = array(
			'section' => isset( $row['section'] ) ? (string) $row['section'] : '',
			'label'   => isset( $row['label'] ) ? (string) $row['label'] : '',
			'answer'  => isset( $row['answer'] ) ? (string) $row['answer'] : '',
		);
	}

	update_post_meta( $post_id, 'srt_checklist', $clean );
}

/**
 * One-time migration from the old ACF repeater 'checklist' field to the
 * plain `srt_checklist` meta key above. Reads the OLD data directly via
 * get_post_meta() (the repeater's actual on-disk shape: a `checklist` count
 * plus `checklist_{i}_{subfield}` cells) rather than get_field(), since this
 * must work regardless of whether the old ACF field definition is still
 * registered. `field_type`/`choices` are intentionally dropped — the
 * checklist is Section + Label + Answer only now — but every row's Section,
 * Label, and Answer (including old radio/checkbox answers, kept as plain
 * text) are preserved so no historical data is lost. Runs once per post
 * (skipped if that post already has the new key) and once overall, guarded
 * by the `srt_checklist_migrated` option.
 */
function srt_migrate_checklist_data() {
	if ( get_option( 'srt_checklist_migrated' ) ) {
		return;
	}

	$post_ids = get_posts(
		array(
			'post_type'      => array( 'rwsrt_company', 'rwsrt_task' ),
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'fields'         => 'ids',
		)
	);

	foreach ( $post_ids as $post_id ) {
		if ( metadata_exists( 'post', $post_id, 'srt_checklist' ) ) {
			continue;
		}

		$count = (int) get_post_meta( $post_id, 'checklist', true );
		$rows  = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$rows[] = array(
				'section' => (string) get_post_meta( $post_id, "checklist_{$i}_section", true ),
				'label'   => (string) get_post_meta( $post_id, "checklist_{$i}_label", true ),
				'answer'  => (string) get_post_meta( $post_id, "checklist_{$i}_answer", true ),
			);
		}

		srt_update_checklist( $post_id, $rows );
	}

	update_option( 'srt_checklist_migrated', 1 );
}
add_action( 'admin_init', 'srt_migrate_checklist_data' );

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
 * Whether the current user sees every company and task rather than only their own
 * assignments: admins always, plus Editors and above once the restrict-to-maker
 * setting is switched off.
 *
 * The setting exists so a manager can see the full picture, but the task page is
 * an editable form — so switching it off opens things to Editors and above, not
 * to every logged-in user. Otherwise any Subscriber could rewrite a checklist,
 * mark a task Completed and trigger its emails.
 *
 * Shared by the task page and the dashboard so both apply the same rule.
 */
function srt_user_can_see_all_tasks() {
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	return ! get_option( 'srt_restrict_to_maker', 1 ) && current_user_can( 'edit_others_posts' );
}

/**
 * Whether a user is named as either the maker or the marketing guide on a given
 * company or task.
 */
function srt_user_is_assigned_to( $user_id, $post_id ) {
	$user_id = (int) $user_id;

	if ( ! $user_id || ! $post_id ) {
		return false;
	}

	return $user_id === srt_get_assigned_user_id( 'maker', $post_id )
		|| $user_id === srt_get_assigned_user_id( 'marketing_guide', $post_id );
}

/**
 * Whether the current logged-in user may view/edit a given task: named on the
 * task, named on the task's company, or covered by srt_user_can_see_all_tasks().
 * Callers must check is_user_logged_in() first.
 *
 * Assignment is what grants access, not role — makers and marketing guides are
 * ordinarily Subscribers with no capabilities beyond `read`.
 *
 * The fall-through to the company matters because a task snapshots its maker and
 * marketing guide at creation time. Tasks created before someone was assigned
 * carry no marketing guide at all, and reassigning a company leaves its existing
 * tasks pointing at the previous person — so without this, the current marketing
 * guide could not open their own client's earlier reports. It also keeps the
 * dashboard honest: that query is company-level, so it would otherwise list a
 * company whose task the user is then denied.
 */
function srt_user_can_access_task( $task_id ) {
	if ( srt_user_can_see_all_tasks() ) {
		return true;
	}

	$user_id = get_current_user_id();

	if ( srt_user_is_assigned_to( $user_id, $task_id ) ) {
		return true;
	}

	return srt_user_is_assigned_to( $user_id, (int) get_field( 'company', $task_id ) );
}

/**
 * Whether the current user may trigger a rescan/refresh action on a task —
 * either through wp-admin (edit_post, i.e. Editor and above) or as the
 * maker/marketing guide accessing it via the front-end task page. Makers and
 * marketing guides are ordinarily Subscribers with no edit_post capability at
 * all, so the site scan/Kinsta/GTmetrix "re-run" buttons shown on the
 * front-end task page would otherwise 403 for the very people using that
 * page. Callers must check is_user_logged_in() first (same contract as
 * srt_user_can_access_task()).
 */
function srt_user_can_manage_task( $task_id ) {
	return current_user_can( 'edit_post', $task_id ) || srt_user_can_access_task( $task_id );
}

/**
 * Redirects back after a rescan/refresh/save admin-post action. Admin-side
 * links (built without a $return_url) redirect to the wp-admin edit screen as
 * before; front-end links pass their own page URL as 'srt_return' so a maker
 * or marketing guide — who usually can't open wp-admin at all — lands back on
 * the task page instead of hitting an access-denied wall.
 *
 * @param int    $task_id      Task post ID, used only for the wp-admin fallback.
 * @param string $notice_key   Query arg name for the notice (e.g. 'srt_kinsta').
 * @param string $notice_value Query arg value (e.g. 'security_refreshed').
 */
function srt_redirect_after_action( $task_id, $notice_key, $notice_value ) {
	$return = isset( $_REQUEST['srt_return'] ) ? esc_url_raw( wp_unslash( $_REQUEST['srt_return'] ) ) : '';

	if ( $return ) {
		wp_safe_redirect( add_query_arg( $notice_key, $notice_value, $return ) );
		exit;
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'post'      => $task_id,
				'action'    => 'edit',
				$notice_key => $notice_value,
			),
			admin_url( 'post.php' )
		)
	);
	exit;
}

/**
 * meta_query matching companies or tasks assigned to a user as either the maker
 * or the marketing guide. Both roles get the same visibility over the records
 * they're named on.
 */
function srt_assigned_to_user_meta_query( $user_id ) {
	return array(
		'relation' => 'OR',
		array(
			'key'   => 'maker',
			'value' => $user_id,
		),
		array(
			'key'   => 'marketing_guide',
			'value' => $user_id,
		),
	);
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
 * Default checklist rows seeded onto a company the first time it's saved with
 * no checklist stored at all (see SRT_CPT_Company::save_checklist()). Mirrors
 * the standard sections every review covers; edit or add to per company —
 * this only supplies the starting point.
 *
 * Every item is a plain Section + Label + free-text Answer. Security
 * Overview / Page Load Speed / Backups status used to be separate
 * radio-choice items here, auto-filled from Kinsta/GTmetrix — that data now
 * shows on its own in the independent "Kinsta Data" / "Page Load Speed
 * (GTmetrix)" sections regardless of the checklist (see class-kinsta.php /
 * class-gtmetrix.php), so each of those three sections keeps only a
 * free-text Summary item here for the maker's own notes.
 */
function srt_default_checklist_items() {
	return array(
		array( 'section' => 'Security Overview', 'label' => 'Security Overview Summary', 'answer' => '' ),
		array( 'section' => 'Content and User Experience', 'label' => 'Content Check', 'answer' => '' ),
		array( 'section' => 'Content and User Experience', 'label' => 'Mobile Responsiveness', 'answer' => '' ),
		array( 'section' => 'Content and User Experience', 'label' => 'Form Functionality', 'answer' => '' ),
		array( 'section' => 'Content and User Experience', 'label' => 'Cart & Checkout Functionality', 'answer' => '' ),
		array( 'section' => 'Content and User Experience', 'label' => 'Content and User Experience Summary', 'answer' => '' ),
		array( 'section' => 'Performance and Speed Optimization', 'label' => 'Performance and Speed Optimization Summary', 'answer' => '' ),
		array( 'section' => 'Backups', 'label' => 'Backups Summary', 'answer' => '' ),
	);
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
					'key'          => 'field_srt_company_kinsta_site_id',
					'label'        => 'Kinsta Site ID',
					'name'         => 'kinsta_site_id',
					'type'         => 'text',
					'instructions' => 'Optional. Enables the Security Overview and Backups data pulled from Kinsta. In MyKinsta, open the site and click into its environment (e.g. "Live") — the ID is the first UUID in the browser\'s address bar right after /sites/details/ (e.g. my.kinsta.com/sites/details/THIS-PART/...). Leave blank to skip.',
				),
				array(
					'key'           => 'field_srt_company_executive_summary',
					'label'         => 'Executive Summary',
					'name'          => 'executive_summary',
					'type'          => 'textarea',
					'rows'          => 4,
					'default_value' => srt_default_executive_summary(),
					'instructions'  => 'The default Executive Summary copied onto each new review task for this company. Edit here to customize this company\'s boilerplate; edit on the task itself to customize a single report.',
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
					'key'          => 'field_srt_task_kinsta_security_notes',
					'label'        => 'Security Overview Notes',
					'name'         => 'kinsta_security_notes',
					'type'         => 'textarea',
					'rows'         => 3,
					'instructions' => 'Optional commentary shown below the Kinsta Data → Security Overview block. Hidden entirely on both PDFs when left blank.',
				),
				array(
					'key'          => 'field_srt_task_kinsta_backups_notes',
					'label'        => 'Backups Notes',
					'name'         => 'kinsta_backups_notes',
					'type'         => 'textarea',
					'rows'         => 3,
					'instructions' => 'Optional commentary shown below the Kinsta Data → Backups block. Hidden entirely on both PDFs when left blank.',
				),
				array(
					'key'          => 'field_srt_task_kinsta_bot_protection_notes',
					'label'        => 'Bot Protection Notes',
					'name'         => 'kinsta_bot_protection_notes',
					'type'         => 'textarea',
					'rows'         => 3,
					'instructions' => 'Optional commentary shown below the Bot Protection block. Marketing Guide PDF only, like Bot Protection itself. Hidden entirely when left blank.',
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
						'needs_work' => 'Save Draft',
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
