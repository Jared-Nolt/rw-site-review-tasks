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
		$next_due = srt_company_advance_due_date( $company_id, $due_date, $months );

		update_field( 'due_date', $next_due, $company_id );
	}

	private static function notify_maker( $company_id, $task_id ) {
		$maker_id = srt_get_assigned_user_id( 'maker', $company_id );

		if ( ! $maker_id ) {
			return;
		}

		$user = get_userdata( $maker_id );

		if ( ! $user || ! $user->user_email ) {
			return;
		}

		$company_name = get_the_title( $company_id );
		$period       = get_field( 'period', $task_id );

		$paragraphs = array(
			sprintf(
				/* translators: 1: recipient first name, 2: company name */
				__( 'Hi %1$s, a new website review task for %2$s is ready for you.', 'rw-site-review-tasks' ),
				self::greeting_name( $user ),
				$company_name
			),
		);

		if ( $period ) {
			$paragraphs[] = sprintf(
				/* translators: %s: review period date */
				__( 'Review period: %s', 'rw-site-review-tasks' ),
				$period
			);
		}

		$paragraphs[] = __( 'Work through the checklist on the task page, then set the status to Completed when you are finished.', 'rw-site-review-tasks' );

		SRT_Mailer::send(
			$user->user_email,
			sprintf(
				/* translators: %s: company name */
				__( '%s: website review task ready', 'rw-site-review-tasks' ),
				$company_name
			),
			sprintf(
				/* translators: %s: company name */
				__( 'Review task ready for %s', 'rw-site-review-tasks' ),
				$company_name
			),
			$paragraphs,
			srt_task_page_url( $task_id ),
			__( 'Open the review task', 'rw-site-review-tasks' )
		);
	}

	/**
	 * First name when we have one, display name otherwise — a named greeting reads
	 * less like bulk mail to both people and spam filters.
	 */
	private static function greeting_name( $user ) {
		return $user->first_name ? $user->first_name : $user->display_name;
	}

	/**
	 * Emails the assigned maker and marketing guide when a task's status is
	 * saved as Completed or Needs Work. Both statuses are treated the same way
	 * — same recipients, same mechanics — only the wording differs.
	 *
	 * @param int    $task_id The task post ID.
	 * @param string $status  'completed' or 'needs_work'.
	 */
	public static function notify_status_change( $task_id, $status ) {
		$company_id = get_field( 'company', $task_id );

		if ( ! $company_id ) {
			return;
		}

		$is_completed = 'completed' === $status;
		$company_name = get_the_title( $company_id );
		$link         = srt_task_page_url( $task_id );
		$period       = get_field( 'period', $task_id );

		$subject = sprintf(
			/* translators: %s: company name */
			$is_completed
				? __( '%s: website review task completed', 'rw-site-review-tasks' )
				: __( '%s: website review task needs work', 'rw-site-review-tasks' ),
			$company_name
		);
		$heading = sprintf(
			/* translators: %s: company name */
			$is_completed
				? __( 'Review task completed for %s', 'rw-site-review-tasks' )
				: __( 'Review task needs work for %s', 'rw-site-review-tasks' ),
			$company_name
		);

		$recipient_ids = array_unique(
			array_filter(
				array(
					srt_get_assigned_user_id( 'maker', $task_id ),
					srt_get_assigned_user_id( 'marketing_guide', $task_id ),
				)
			)
		);

		foreach ( $recipient_ids as $user_id ) {
			$user = get_userdata( $user_id );

			if ( ! $user || ! $user->user_email ) {
				continue;
			}

			$paragraphs = array(
				sprintf(
					/* translators: 1: recipient first name, 2: company name */
					$is_completed
						? __( 'Hi %1$s, the website review task for %2$s has been marked Completed.', 'rw-site-review-tasks' )
						: __( 'Hi %1$s, the website review task for %2$s has been marked Needs Work.', 'rw-site-review-tasks' ),
					self::greeting_name( $user ),
					$company_name
				),
			);

			if ( $period ) {
				$paragraphs[] = sprintf(
					/* translators: %s: review period date */
					__( 'Review period: %s', 'rw-site-review-tasks' ),
					$period
				);
			}

			$paragraphs[] = $is_completed
				? __( 'The full report, including the site scan results, is on the task page.', 'rw-site-review-tasks' )
				: __( 'See the task page for details on what still needs attention.', 'rw-site-review-tasks' );

			SRT_Mailer::send(
				$user->user_email,
				$subject,
				$heading,
				$paragraphs,
				$link,
				$is_completed
					? __( 'View the completed task', 'rw-site-review-tasks' )
					: __( 'View the task', 'rw-site-review-tasks' )
			);
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
