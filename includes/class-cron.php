<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRT_Cron {

	const HOOK    = 'srt_daily_check';
	const LOG_OPT = 'srt_run_log';
	const LOG_CAP = 200;

	// Default Run log retention in days; overridable via the srt_log_retention_days option. 0 = keep until the LOG_CAP count limit.
	const LOG_RETENTION_DEFAULT = 90;

	const INTERVAL_MONTHS = array(
		'monthly'       => 1,
		'quarterly'     => 3,
		'semi_annually' => 6,
		'annually'      => 12,
	);

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_daily_check' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$time = get_option( 'srt_cron_time', '08:00' );
			wp_schedule_event( self::next_run_timestamp( $time ), 'daily', self::HOOK );
		}
	}

	public static function reschedule() {
		self::unschedule();
		self::schedule();
	}

	private static function next_run_timestamp( $time_of_day ) {
		$tz       = wp_timezone();
		$now      = new DateTime( 'now', $tz );
		$run_time = DateTime::createFromFormat( 'Y-m-d H:i', $now->format( 'Y-m-d' ) . ' ' . $time_of_day, $tz );

		if ( ! $run_time ) {
			return time() + DAY_IN_SECONDS;
		}

		if ( $run_time <= $now ) {
			$run_time->modify( '+1 day' );
		}

		return $run_time->getTimestamp();
	}

	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * Creates a review task for every active, scheduled company whose due date
	 * (minus its lead days) has arrived. $force ignores that timing and creates
	 * one immediately for any active company that doesn't already have a task
	 * for its current due date (used by the "Run now" admin button).
	 *
	 * @return array{created:int,skipped:int} summary
	 */
	public static function run_daily_check( $force = false ) {
		$companies = get_posts(
			array(
				'post_type'      => SRT_CPT_Company::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'meta_query'     => array(
					array(
						'key'   => 'active',
						'value' => '1',
					),
				),
			)
		);

		$created = 0;
		$skipped = 0;

		foreach ( $companies as $company ) {
			$result = self::run_for_company( $company->ID, $force );

			if ( 'created' === $result['result'] ) {
				$created++;
			} elseif ( 'skipped_exists' === $result['result'] ) {
				$skipped++;
			}
		}

		return array( 'created' => $created, 'skipped' => $skipped, 'ran' => true );
	}

	/**
	 * Runs the task-creation check for a single company and returns the outcome.
	 * $force ignores the active flag and the due/lead-day timing — used by the
	 * manual "Create review task now" button on the company edit screen.
	 *
	 * @return array{result:string,task_id?:int} result is one of:
	 *   created | skipped_exists | not_scheduled | not_active | too_early | error
	 */
	public static function run_for_company( $company_id, $force = false ) {
		$due_date = get_field( 'due_date', $company_id );

		if ( ! $due_date ) {
			return array( 'result' => 'not_scheduled' );
		}

		if ( ! $force ) {
			if ( ! get_field( 'active', $company_id ) ) {
				return array( 'result' => 'not_active' );
			}

			$lead_days = (int) get_field( 'lead_days', $company_id );
			$create_on = date( 'Y-m-d', strtotime( "{$due_date} -{$lead_days} days" ) );

			if ( current_time( 'Y-m-d' ) < $create_on ) {
				return array( 'result' => 'too_early' );
			}
		}

		if ( self::task_exists_for_due( $company_id, $due_date ) ) {
			return array( 'result' => 'skipped_exists' );
		}

		$task_id = self::create_task( $company_id, $due_date );

		if ( ! $task_id ) {
			return array( 'result' => 'error' );
		}

		self::log( $company_id, $task_id, $due_date, 'created' );
		self::advance_due_date( $company_id, $due_date );
		self::notify_maker( $company_id, $task_id );
		SRT_Site_Scanner::schedule( $task_id );

		return array( 'result' => 'created', 'task_id' => $task_id );
	}

	private static function task_exists_for_due( $company_id, $due_date ) {
		$existing = get_posts(
			array(
				'post_type'      => SRT_CPT_Task::POST_TYPE,
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => 'company',
						'value' => $company_id,
					),
					array(
						'key'   => 'period',
						'value' => $due_date,
					),
				),
			)
		);

		return ! empty( $existing );
	}

	private static function create_task( $company_id, $due_date ) {
		$maker_id           = get_field( 'maker', $company_id );
		$marketing_guide_id = get_field( 'marketing_guide', $company_id );
		$template_id        = get_field( 'checklist_template', $company_id );

		$task_id = wp_insert_post(
			array(
				'post_type'   => SRT_CPT_Task::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => sprintf( '%s — %s', get_the_title( $company_id ), $due_date ),
			)
		);

		if ( is_wp_error( $task_id ) || ! $task_id ) {
			return 0;
		}

		update_field( 'company', $company_id, $task_id );
		update_field( 'maker', $maker_id, $task_id );
		update_field( 'marketing_guide', $marketing_guide_id, $task_id );
		update_field( 'period', $due_date, $task_id );
		update_field( 'status', '', $task_id );
		update_field( 'executive_summary', srt_default_executive_summary(), $task_id );

		$items = $template_id ? get_field( 'items', $template_id ) : array();
		$rows  = array();

		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				$rows[] = array(
					'section'    => isset( $item['section'] ) ? $item['section'] : '',
					'field_type' => $item['field_type'],
					'label'      => $item['label'],
					'choices'    => $item['choices'],
					'answer'     => '',
				);
			}
		}

		update_field( 'checklist', $rows, $task_id );

		return $task_id;
	}

	private static function advance_due_date( $company_id, $due_date ) {
		$interval = get_field( 'interval', $company_id );
		$months   = isset( self::INTERVAL_MONTHS[ $interval ] ) ? self::INTERVAL_MONTHS[ $interval ] : 1;
		$next_due = date( 'Y-m-d', strtotime( "{$due_date} +{$months} months" ) );

		update_field( 'due_date', $next_due, $company_id );
	}

	private static function notify_maker( $company_id, $task_id ) {
		$maker_id = (int) get_field( 'maker', $company_id );

		if ( ! $maker_id ) {
			return;
		}

		$user = get_userdata( $maker_id );

		if ( ! $user || ! $user->user_email ) {
			return;
		}

		$task_page_id = (int) get_option( 'srt_task_page_id' );
		$link         = $task_page_id ? add_query_arg( 'task_id', $task_id, get_permalink( $task_page_id ) ) : '';
		$company_name = get_the_title( $company_id );

		$subject = sprintf( __( '%s: review task ready', 'rw-site-review-tasks' ), $company_name );
		$message = sprintf(
			/* translators: 1: company name, 2: task link */
			__( "A new website review task for %1\$s is ready.\n\n%2\$s", 'rw-site-review-tasks' ),
			$company_name,
			$link
		);

		wp_mail( $user->user_email, $subject, $message );
	}

	/**
	 * Emails the assigned maker and marketing guide when a task is saved as Completed.
	 */
	public static function notify_completed( $task_id ) {
		$company_id = get_field( 'company', $task_id );

		if ( ! $company_id ) {
			return;
		}

		$company_name = get_the_title( $company_id );
		$task_page_id = (int) get_option( 'srt_task_page_id' );
		$link         = $task_page_id ? add_query_arg( 'task_id', $task_id, get_permalink( $task_page_id ) ) : '';

		$subject = sprintf( __( '%s: review task completed', 'rw-site-review-tasks' ), $company_name );
		$message = sprintf(
			/* translators: 1: company name, 2: task link */
			__( "The website review task for %1\$s has been marked Completed.\n\n%2\$s", 'rw-site-review-tasks' ),
			$company_name,
			$link
		);

		$recipient_ids = array_unique(
			array_filter(
				array(
					(int) get_field( 'maker', $task_id ),
					(int) get_field( 'marketing_guide', $task_id ),
				)
			)
		);

		foreach ( $recipient_ids as $user_id ) {
			$user = get_userdata( $user_id );

			if ( $user && $user->user_email ) {
				wp_mail( $user->user_email, $subject, $message );
			}
		}
	}

	private static function log( $company_id, $task_id, $due_date, $result ) {
		$log   = get_option( self::LOG_OPT, array() );
		$log[] = array(
			'time'       => current_time( 'mysql' ),
			'company'    => get_the_title( $company_id ),
			'company_id' => $company_id,
			'task_id'    => $task_id,
			'due'        => $due_date,
			'result'     => $result,
		);

		// Prune entries older than the configured retention window.
		$retention_days = (int) get_option( 'srt_log_retention_days', self::LOG_RETENTION_DEFAULT );
		if ( $retention_days > 0 ) {
			$cutoff = current_time( 'timestamp' ) - $retention_days * DAY_IN_SECONDS;
			$kept   = array();
			foreach ( $log as $entry ) {
				$entry_time = isset( $entry['time'] ) ? strtotime( $entry['time'] ) : 0;
				if ( $entry_time >= $cutoff ) {
					$kept[] = $entry;
				}
			}
			$log = $kept;
		}

		// Hard backstop so the option can never grow without bound.
		if ( count( $log ) > self::LOG_CAP ) {
			$log = array_slice( $log, -self::LOG_CAP );
		}

		update_option( self::LOG_OPT, $log );
	}
}

SRT_Cron::init();
