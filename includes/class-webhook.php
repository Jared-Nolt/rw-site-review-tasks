<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outbound webhook fired when a task's status is saved as Completed or Needs
 * Work, so another platform (Zapier, Make, n8n, a CRM, Slack) can react
 * without polling this site. One event, one POST, one payload shape — no
 * plugin changes needed on this end to add another destination; that's what
 * the receiving side (e.g. a Zap) is for.
 *
 * Entirely optional: stays inert until a URL is configured in Settings.
 */
class SRT_Webhook {

	const URL_OPT    = 'srt_webhook_url';
	const SECRET_OPT = 'srt_webhook_secret';

	/** Collected error message from the most recent send(). */
	private static $last_error = '';

	public static function init() {
		add_action( 'srt_task_status_changed', array( __CLASS__, 'handle_status_change' ), 10, 3 );
	}

	public static function handle_status_change( $task_id, $status, $old_status ) {
		self::send( self::build_payload( $task_id, $status, $old_status ) );
	}

	/**
	 * Builds the JSON-ready payload describing one status change.
	 */
	public static function build_payload( $task_id, $status, $old_status ) {
		$company_id = get_field( 'company', $task_id );

		return array(
			'event'           => 'task_status_changed',
			'task_id'         => $task_id,
			'status'          => $status,
			'status_label'    => srt_get_task_tag_label( $status ),
			'previous_status' => $old_status ? $old_status : null,
			'company_id'      => $company_id ? (int) $company_id : null,
			'company_name'    => $company_id ? get_the_title( $company_id ) : '',
			'period'          => get_field( 'period', $task_id ),
			'task_url'        => srt_task_page_url( $task_id ),
			'site'            => home_url( '/' ),
			'timestamp'       => current_time( 'c' ),
		);
	}

	/**
	 * POSTs a payload to the configured URL as JSON. No-ops (returns false)
	 * when no URL is set.
	 *
	 * @return bool Whether the receiving server accepted it (2xx response).
	 */
	public static function send( $payload ) {
		$url = trim( (string) get_option( self::URL_OPT, '' ) );

		if ( ! $url ) {
			return false;
		}

		self::$last_error = '';

		$body    = wp_json_encode( $payload );
		$secret  = trim( (string) get_option( self::SECRET_OPT, '' ) );
		$headers = array( 'Content-Type' => 'application/json' );

		if ( $secret ) {
			// Lets the receiving endpoint verify the request actually came from
			// this site — same idea as GitHub's or Stripe's webhook signatures.
			// Verify with: hash_hmac( 'sha256', $raw_request_body, $secret ).
			$headers['X-SRT-Signature'] = hash_hmac( 'sha256', $body, $secret );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::$last_error = $response->get_error_message();
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			self::$last_error = sprintf(
				/* translators: %d: HTTP status code */
				__( 'Receiving server responded with HTTP %d.', 'rw-site-review-tasks' ),
				$code
			);
			return false;
		}

		return true;
	}

	public static function last_error() {
		return self::$last_error;
	}
}

SRT_Webhook::init();
