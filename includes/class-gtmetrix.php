<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GTmetrix integration — the Page Load Speed block, replacing the old Google
 * PageSpeed Insights check. Confirmed directly against GTmetrix's published
 * API v2.0 docs (gtmetrix.com/api/docs/2.0/) while building this:
 *
 *   - Auth: HTTP Basic, API key as username, blank password.
 *   - Base URL: https://gtmetrix.com/api/2.0
 *   - POST /tests {data:{type:test,attributes:{url}}} → 202 Accepted, body
 *     data.id is the test id; only `url` is required (location/browser fall
 *     back to the account's default Analysis Options).
 *   - GET /tests/{id} → state queued|started|error|completed. WordPress's
 *     HTTP API follows the 303-to-report redirect on completion
 *     automatically, so a finished test's GET lands directly on the report
 *     body — no separate "fetch the report" call needed here.
 *   - Report fields used (all under data.attributes): gtmetrix_grade,
 *     performance_score, structure_score, largest_contentful_paint (ms),
 *     total_blocking_time (ms), cumulative_layout_shift.
 *   - Recommended poll interval is 3s; this polls every 15s instead (a
 *     WP-Cron event per attempt, capped at MAX_POLL_ATTEMPTS) since Kinsta's
 *     and the site scanner's own schedules already assume "occasional",
 *     not "every few seconds".
 *
 * Async, two-step flow — same shape as the site scan and Kinsta pull:
 * start_test() kicks the test off and schedules the first poll; poll_test()
 * re-schedules itself while the test is still running, and applies the
 * finished report (current vs. previous, plus the Page Load Speed checklist
 * item) once it lands.
 */
class SRT_GTmetrix {

	const META_KEY   = 'srt_gtmetrix_results';
	const START_HOOK = 'srt_gtmetrix_start';
	const POLL_HOOK  = 'srt_gtmetrix_poll';

	const REFRESH_ACTION = 'srt_refresh_gtmetrix';
	const API_KEY_OPT    = 'srt_gtmetrix_api_key';
	const API_BASE        = 'https://gtmetrix.com/api/2.0';

	/** Seconds between polls, and the hard cap on attempts (20 × 15s = 5 minutes). */
	const POLL_INTERVAL      = 15;
	const MAX_POLL_ATTEMPTS  = 20;

	public static function init() {
		add_action( self::START_HOOK, array( __CLASS__, 'start_test' ) );
		add_action( self::POLL_HOOK, array( __CLASS__, 'poll_test' ), 10, 3 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_post_' . self::REFRESH_ACTION, array( __CLASS__, 'handle_refresh' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_notice' ) );
	}

	/**
	 * Schedules the test to start. Same delay contract as
	 * SRT_Site_Scanner::schedule() / SRT_Kinsta::schedule() — 60s default for
	 * a single manual run, a larger per-company delay from the daily batch.
	 */
	public static function schedule( $task_id, $delay = 60 ) {
		wp_schedule_single_event( time() + max( 1, (int) $delay ), self::START_HOOK, array( $task_id ) );
	}

	// -------------------------------------------------------------------------
	// Start + poll
	// -------------------------------------------------------------------------

	/**
	 * URL list for a company: homepage first, then up to 5 manually added
	 * "Additional Pages to Scan" (the same `scan_pages` field the broken-link
	 * scanner uses) — same homepage-plus-5, 6-max contract as
	 * SRT_Site_Scanner::run().
	 */
	private static function scan_urls( $company_id ) {
		$base_url = get_field( 'website_url', $company_id );

		if ( ! $base_url ) {
			return array();
		}

		$urls        = array( $base_url );
		$extra_pages = get_field( 'scan_pages', $company_id );

		if ( is_array( $extra_pages ) ) {
			foreach ( $extra_pages as $page ) {
				if ( ! empty( $page['page_url'] ) ) {
					$urls[] = $page['page_url'];
				}
			}
		}

		return array_slice( array_unique( $urls ), 0, 6 ); // homepage + max 5
	}

	/**
	 * Starts one GTmetrix test per page (homepage plus any Additional Pages
	 * to Scan). Each page is tracked independently under $results['pages'][i]
	 * so one page's failure or slow poll never blocks the others.
	 */
	public static function start_test( $task_id ) {
		$company_id = get_field( 'company', $task_id );
		$urls       = $company_id ? self::scan_urls( $company_id ) : array();

		if ( ! $urls ) {
			self::store_page_error( $task_id, 0, '', __( 'No website URL set on the company.', 'rw-site-review-tasks' ) );
			return;
		}

		$key = self::api_key();

		if ( ! $key ) {
			foreach ( $urls as $index => $url ) {
				self::store_page_error( $task_id, $index, $url, __( 'No GTmetrix API key set in Settings.', 'rw-site-review-tasks' ) );
			}
			return;
		}

		foreach ( $urls as $index => $url ) {
			self::start_page_test( $task_id, $index, $url, $key );
		}
	}

	private static function start_page_test( $task_id, $index, $url, $key ) {
		$response = wp_remote_post(
			self::API_BASE . '/tests',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $key . ':' ),
					'Content-Type'  => 'application/vnd.api+json',
					'Accept'        => 'application/vnd.api+json',
				),
				'body'    => wp_json_encode(
					array(
						'data' => array(
							'type'       => 'test',
							'attributes' => array( 'url' => $url ),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::store_page_error( $task_id, $index, $url, $response->get_error_message() );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 202 !== $code ) {
			$msg = isset( $data['errors'][0]['detail'] )
				? $data['errors'][0]['detail']
				/* translators: %d: HTTP status code */
				: sprintf( __( 'GTmetrix returned HTTP %d.', 'rw-site-review-tasks' ), $code );
			self::store_page_error( $task_id, $index, $url, $msg );
			return;
		}

		$test_id = isset( $data['data']['id'] ) ? $data['data']['id'] : '';

		if ( ! $test_id ) {
			self::store_page_error( $task_id, $index, $url, __( 'Unexpected response from GTmetrix.', 'rw-site-review-tasks' ) );
			return;
		}

		self::update_page(
			$task_id,
			$index,
			array(
				'url'     => $url,
				'status'  => 'pending',
				'test_id' => $test_id,
			)
		);

		wp_schedule_single_event( time() + self::POLL_INTERVAL, self::POLL_HOOK, array( $task_id, $index, 1 ) );
	}

	public static function poll_test( $task_id, $page_index, $attempt = 1 ) {
		$results = self::get_results( $task_id );
		$page    = isset( $results['pages'][ $page_index ] ) ? $results['pages'][ $page_index ] : array();
		$test_id = isset( $page['test_id'] ) ? $page['test_id'] : '';
		$url     = isset( $page['url'] ) ? $page['url'] : '';

		if ( ! $test_id ) {
			return; // Nothing to poll for (e.g. a fresh start error already handled it).
		}

		$key      = self::api_key();
		$response = wp_remote_get(
			self::API_BASE . '/tests/' . rawurlencode( $test_id ),
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $key . ':' ),
					'Accept'        => 'application/vnd.api+json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::store_page_error( $task_id, $page_index, $url, $response->get_error_message() );
			return;
		}

		$data  = json_decode( wp_remote_retrieve_body( $response ), true );
		$state = isset( $data['data']['attributes']['state'] ) ? $data['data']['attributes']['state'] : '';

		if ( in_array( $state, array( 'queued', 'started' ), true ) ) {
			if ( $attempt >= self::MAX_POLL_ATTEMPTS ) {
				self::store_page_error( $task_id, $page_index, $url, __( 'GTmetrix test timed out waiting for a result.', 'rw-site-review-tasks' ) );
				return;
			}
			wp_schedule_single_event( time() + self::POLL_INTERVAL, self::POLL_HOOK, array( $task_id, $page_index, $attempt + 1 ) );
			return;
		}

		if ( 'error' === $state ) {
			$msg = isset( $data['data']['attributes']['error'] ) ? $data['data']['attributes']['error'] : __( 'GTmetrix test failed.', 'rw-site-review-tasks' );
			self::store_page_error( $task_id, $page_index, $url, $msg );
			return;
		}

		// Not queued/started/error: WordPress followed the 303-to-report
		// redirect automatically, so this response body is the finished report.
		$attrs = isset( $data['data']['attributes'] ) ? $data['data']['attributes'] : array();

		if ( ! isset( $attrs['performance_score'] ) && ! isset( $attrs['gtmetrix_grade'] ) ) {
			self::store_page_error( $task_id, $page_index, $url, __( 'Unexpected response from GTmetrix.', 'rw-site-review-tasks' ) );
			return;
		}

		self::apply_page_report( $task_id, $page_index, $url, $attrs );
	}

	private static function apply_page_report( $task_id, $page_index, $url, $attrs ) {
		$new = array(
			'grade'       => isset( $attrs['gtmetrix_grade'] ) ? $attrs['gtmetrix_grade'] : '',
			'performance' => isset( $attrs['performance_score'] ) ? (int) $attrs['performance_score'] : null,
			'structure'   => isset( $attrs['structure_score'] ) ? (int) $attrs['structure_score'] : null,
			'lcp_ms'      => isset( $attrs['largest_contentful_paint'] ) ? (int) $attrs['largest_contentful_paint'] : null,
			'tbt_ms'      => isset( $attrs['total_blocking_time'] ) ? (int) $attrs['total_blocking_time'] : null,
			'cls'         => isset( $attrs['cumulative_layout_shift'] ) ? (float) $attrs['cumulative_layout_shift'] : null,
			'tested_at'   => current_time( 'mysql' ),
		);

		$results = self::get_results( $task_id );
		$page    = isset( $results['pages'][ $page_index ] ) ? $results['pages'][ $page_index ] : array();

		// Only promote to "previous" if there was a genuinely completed prior
		// run on this page — not a stale pending/error state left over from a
		// failed test.
		if ( ! empty( $page['current'] ) && isset( $page['status'] ) && 'ready' === $page['status'] ) {
			$page['previous'] = $page['current'];
		}

		$page['url']     = $url;
		$page['current'] = $new;
		$page['status']  = 'ready';
		unset( $page['error'], $page['test_id'] );

		self::update_page( $task_id, $page_index, $page );

		// The checklist's single "Page Load Speed" answer still auto-fills
		// from the homepage only — per-page detail is for the report tables.
		if ( 0 === $page_index ) {
			self::apply_to_checklist( $task_id, $new );
		}
	}

	private static function store_page_error( $task_id, $page_index, $url, $message ) {
		$results = self::get_results( $task_id );
		$page    = isset( $results['pages'][ $page_index ] ) ? $results['pages'][ $page_index ] : array();

		if ( $url ) {
			$page['url'] = $url;
		}

		$page['status'] = 'error';
		$page['error']  = $message;
		unset( $page['test_id'] );

		self::update_page( $task_id, $page_index, $page );
	}

	/**
	 * Merges $data into $results['pages'][$page_index] and persists — the one
	 * write path every page update goes through, so updating one page never
	 * clobbers its siblings' entries.
	 */
	private static function update_page( $task_id, $page_index, $data ) {
		$results = self::get_results( $task_id );

		if ( ! isset( $results['pages'] ) || ! is_array( $results['pages'] ) ) {
			$results['pages'] = array();
		}

		$existing                        = isset( $results['pages'][ $page_index ] ) ? $results['pages'][ $page_index ] : array();
		$results['pages'][ $page_index ] = array_merge( $existing, $data );
		ksort( $results['pages'] );

		update_post_meta( $task_id, self::META_KEY, $results );
	}

	/**
	 * Raw stored results for a task: { status, current, previous, error, test_id }.
	 * Public — the front-end task page reads this directly to mirror the
	 * admin meta box's not-tested/pending/error/ready states.
	 */
	public static function get_results( $task_id ) {
		$results = get_post_meta( $task_id, self::META_KEY, true );
		return is_array( $results ) ? $results : array();
	}

	/**
	 * @param int    $task_id
	 * @param string $return_url Front-end page to redirect back to after the
	 *                           refresh; omit for the wp-admin default.
	 */
	public static function refresh_url( $task_id, $return_url = '' ) {
		$args = array(
			'action'  => self::REFRESH_ACTION,
			'task_id' => $task_id,
		);
		if ( $return_url ) {
			$args['srt_return'] = $return_url;
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'srt_refresh_gtmetrix_' . $task_id );
	}

	private static function api_key() {
		return trim( (string) get_option( self::API_KEY_OPT, '' ) );
	}

	// -------------------------------------------------------------------------
	// Checklist auto-fill (Page Load Speed)
	// -------------------------------------------------------------------------

	/**
	 * Sets the "Page Load Speed" checklist item's answer from the fetched
	 * GTmetrix performance score — same "auto-fill only if it matches one of
	 * the item's own configured choices" contract as
	 * SRT_Kinsta::apply_security_to_checklist().
	 */
	private static function apply_to_checklist( $task_id, $current ) {
		if ( null === $current['performance'] ) {
			return;
		}

		$rows = get_field( 'checklist', $task_id );

		if ( ! is_array( $rows ) ) {
			return;
		}

		$computed = $current['performance'] >= 90
			? __( 'Good', 'rw-site-review-tasks' )
			: __( 'Needs attention', 'rw-site-review-tasks' );

		$changed = false;

		foreach ( $rows as &$row ) {
			if ( 'radio' !== $row['field_type'] || 'page load speed' !== strtolower( trim( (string) $row['label'] ) ) ) {
				continue;
			}

			$choices = srt_lines_to_array( $row['choices'] );

			if ( in_array( $computed, $choices, true ) ) {
				$row['answer'] = $computed;
				$changed       = true;
			}
		}
		unset( $row );

		if ( $changed ) {
			update_field( 'checklist', $rows, $task_id );
		}
	}

	// -------------------------------------------------------------------------
	// Admin: meta box + refresh
	// -------------------------------------------------------------------------

	public static function handle_refresh() {
		$task_id = isset( $_GET['task_id'] ) ? absint( $_GET['task_id'] ) : 0;

		if ( ! $task_id || ! is_user_logged_in() || ! srt_user_can_manage_task( $task_id ) ) {
			wp_die( esc_html__( 'Not authorized.', 'rw-site-review-tasks' ) );
		}

		check_admin_referer( 'srt_refresh_gtmetrix_' . $task_id );

		self::start_test( $task_id );

		srt_redirect_after_action( $task_id, 'srt_gtmetrix', 'refresh_started' );
	}

	public static function add_meta_box() {
		add_meta_box(
			'srt_gtmetrix_data',
			__( 'Page Load Speed (GTmetrix)', 'rw-site-review-tasks' ),
			array( __CLASS__, 'render_admin_meta_box' ),
			SRT_CPT_Task::POST_TYPE,
			'normal',
			'low'
		);
	}

	public static function render_admin_meta_box( $post ) {
		$company_id = get_field( 'company', $post->ID );
		$url        = $company_id ? get_field( 'website_url', $company_id ) : '';

		if ( ! $url ) {
			echo '<p>' . esc_html__( 'No website URL set on the company — add one to enable this.', 'rw-site-review-tasks' ) . '</p>';
			return;
		}

		$results = self::get_results( $post->ID );

		if ( empty( $results['pages'] ) ) {
			echo '<p>' . esc_html__( 'Not tested yet — runs automatically alongside the site scan.', 'rw-site-review-tasks' ) . '</p>';
		} else {
			echo self::render_table_html( $results ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<p><a href="' . esc_url( self::refresh_url( $post->ID ) ) . '" class="button">' . esc_html__( 'Refresh Page Load Speed', 'rw-site-review-tasks' ) . '</a></p>';
	}

	public static function maybe_show_notice() {
		if ( empty( $_GET['srt_gtmetrix'] ) || 'refresh_started' !== $_GET['srt_gtmetrix'] ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' .
			esc_html__( 'GTmetrix test started — results will appear here in about a minute.', 'rw-site-review-tasks' ) .
			'</p></div>';
	}

	// -------------------------------------------------------------------------
	// Rendering helpers shared by front-end task page + both PDFs
	// -------------------------------------------------------------------------

	private static function format_lcp( $ms ) {
		return null === $ms ? '—' : round( $ms / 1000, 1 ) . 's';
	}

	private static function format_tbt( $ms ) {
		return null === $ms ? '—' : $ms . 'ms';
	}

	private static function format_cls( $value ) {
		return null === $value ? '—' : ( rtrim( rtrim( number_format( $value, 2 ), '0' ), '.' ) ?: '0' );
	}

	/**
	 * The Page Load Speed report — one block per scanned page (homepage plus
	 * any Additional Pages to Scan), each with its own not-tested/pending/
	 * error/ready state and previous-vs-current comparison table.
	 *
	 * @param array|null $results    Full results array; fetched from $task_id if omitted.
	 * @param int        $task_id
	 * @param bool       $ready_only When true, pages that aren't 'ready' are skipped
	 *                               entirely and '' is returned if none are ready —
	 *                               used by the PDFs, which only show completed runs.
	 */
	public static function render_table_html( $results = null, $task_id = 0, $ready_only = false ) {
		if ( null === $results ) {
			$results = self::get_results( $task_id );
		}

		$pages = isset( $results['pages'] ) && is_array( $results['pages'] ) ? $results['pages'] : array();

		if ( ! $pages ) {
			return '';
		}

		$blocks = array();

		foreach ( $pages as $index => $page ) {
			$status = isset( $page['status'] ) ? $page['status'] : '';

			if ( $ready_only && 'ready' !== $status ) {
				continue;
			}

			$blocks[] = self::render_page_block( $index, $page );
		}

		if ( ! $blocks ) {
			return '';
		}

		return '<div class="srt-gtmetrix-results">' . implode( '', $blocks ) . '</div>';
	}

	private static function render_page_block( $index, $page ) {
		$is_homepage = ( 0 === $index );
		$status      = isset( $page['status'] ) ? $page['status'] : '';
		$url         = isset( $page['url'] ) ? $page['url'] : '';

		ob_start();
		?>
		<div class="srt-scan-page">
			<h4 class="srt-scan-page-title">
				<?php echo $is_homepage ? esc_html__( 'Homepage', 'rw-site-review-tasks' ) : esc_html__( 'Page', 'rw-site-review-tasks' ); ?>
				<?php if ( $url ) : ?>
					&mdash;
					<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" class="srt-scan-page-url"><?php echo esc_html( $url ); ?></a>
				<?php endif; ?>
			</h4>
			<?php if ( '' === $status ) : ?>
				<p class="srt-scan-meta"><?php esc_html_e( 'Not tested yet.', 'rw-site-review-tasks' ); ?></p>
			<?php elseif ( 'pending' === $status ) : ?>
				<p class="srt-scan-meta"><?php esc_html_e( 'Test running — GTmetrix tests typically take under a minute. Reload shortly.', 'rw-site-review-tasks' ); ?></p>
			<?php elseif ( 'error' === $status ) : ?>
				<p class="srt-scan-meta"><?php echo esc_html( isset( $page['error'] ) ? $page['error'] : __( 'Unknown error.', 'rw-site-review-tasks' ) ); ?></p>
			<?php else : ?>
				<?php echo self::render_metrics_table( $page ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * The Previous/Current comparison table for a single page's report.
	 */
	private static function render_metrics_table( $page ) {
		$current  = isset( $page['current'] ) ? $page['current'] : array();
		$previous = isset( $page['previous'] ) ? $page['previous'] : null;

		if ( empty( $current ) ) {
			return '';
		}

		$rows = array(
			array( __( 'Grade', 'rw-site-review-tasks' ), $current['grade'], $previous ? $previous['grade'] : '' ),
			array( __( 'Performance', 'rw-site-review-tasks' ), null === $current['performance'] ? '—' : $current['performance'] . '/100', $previous && null !== $previous['performance'] ? $previous['performance'] . '/100' : '' ),
			array( __( 'Structure', 'rw-site-review-tasks' ), null === $current['structure'] ? '—' : $current['structure'] . '/100', $previous && null !== $previous['structure'] ? $previous['structure'] . '/100' : '' ),
			array( __( 'LCP', 'rw-site-review-tasks' ), self::format_lcp( $current['lcp_ms'] ), $previous ? self::format_lcp( $previous['lcp_ms'] ) : '' ),
			array( __( 'TBT', 'rw-site-review-tasks' ), self::format_tbt( $current['tbt_ms'] ), $previous ? self::format_tbt( $previous['tbt_ms'] ) : '' ),
			array( __( 'CLS', 'rw-site-review-tasks' ), self::format_cls( $current['cls'] ), $previous ? self::format_cls( $previous['cls'] ) : '' ),
		);

		ob_start();
		?>
		<table class="rw-gtmetrix-table srt-gtmetrix-table">
			<thead>
				<tr>
					<th></th>
					<th><?php esc_html_e( 'Previous', 'rw-site-review-tasks' ); ?></th>
					<th><?php esc_html_e( 'Current', 'rw-site-review-tasks' ); ?></th>
				</tr>
				<tr class="rw-gtmetrix-dates">
					<th></th>
					<th><?php echo $previous ? esc_html( $previous['tested_at'] ) : '&#8212;'; ?></th>
					<th><?php echo esc_html( $current['tested_at'] ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row[0] ); ?></td>
						<td><?php echo esc_html( '' !== $row[2] ? $row[2] : '&#8212;' ); ?></td>
						<td><?php echo esc_html( $row[1] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		return ob_get_clean();
	}
}

SRT_GTmetrix::init();
