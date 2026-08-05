<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kinsta API integration — Security Overview (plugin/theme/core/PHP update
 * status) and the Backups date, both pulled onto the task and used to
 * auto-fill matching Security Overview checklist answers.
 *
 * Confirmed against api-docs.kinsta.com (OpenAPI spec, fetched directly)
 * while building this:
 *   - Auth: `Authorization: Bearer <key>`, base https://api.kinsta.com/v2.
 *   - GET /sites/{site_id}/environments → { site: { environments: [...] } },
 *     each environment carrying wordpress_version and
 *     container_info.php_engine_version (e.g. "php8.3"). There is no
 *     single "get one environment" endpoint — only this site-scoped list —
 *     so a company's Kinsta Site ID resolves to its "live" environment here
 *     and that environment's id is what the env-scoped endpoints below need.
 *   - GET /sites/environments/{env_id}/wp-plugins and .../wp-themes → each
 *     item carries update: "available" | (anything else). Counted client-side.
 *   - GET /sites/environments/{env_id}/backups → { environment: { backups: [
 *     { created_at: <epoch ms>, type, ... } ] } }.
 *
 * NOT built: Bot Protection. Kinsta's public REST API (as of this writing)
 * has no Bot Protection / traffic-analytics endpoint at all — it isn't in
 * their OpenAPI index — so there's nothing to call. The Bot Protection
 * numbers on the MG PDF are a manual-entry stopgap (see the task edit
 * screen) until Kinsta ships that endpoint; swap in a real fetch then.
 *
 * Rendering is independent of the checklist. Earlier this data only showed up
 * beside a matching Security Overview / Backup Verification checklist item —
 * so a task with no checklist items (or one whose checklist skipped those
 * labels) showed nothing at all, on either PDF. Security Overview, Backups,
 * and Bot Protection are now their own "Kinsta Data" block, shown on the
 * front-end task page and both PDFs regardless of what's in the checklist.
 */
class SRT_Kinsta {

	const META_KEY               = 'srt_kinsta_results';
	const CRON_HOOK               = 'srt_run_kinsta';
	const REFRESH_SECURITY_ACTION = 'srt_refresh_kinsta_security';
	const REFRESH_BACKUPS_ACTION  = 'srt_refresh_kinsta_backups';

	const API_KEY_OPT  = 'srt_kinsta_api_key';
	const PHP_REC_OPT  = 'srt_kinsta_recommended_php';
	const API_BASE     = 'https://api.kinsta.com/v2';

	/** Checklist item labels this integration auto-fills, matched case-insensitively. */
	const MANAGED_LABELS = array( 'plugins', 'theme', 'core', 'php version' );

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_post_' . self::REFRESH_SECURITY_ACTION, array( __CLASS__, 'handle_refresh_security' ) );
		add_action( 'admin_post_' . self::REFRESH_BACKUPS_ACTION, array( __CLASS__, 'handle_refresh_backups' ) );
		add_action( 'admin_post_srt_save_bot_protection', array( __CLASS__, 'handle_save_bot_protection' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_notice' ) );
	}

	/**
	 * Schedules the Kinsta data pull to run alongside the site scan. Same
	 * delay contract as SRT_Site_Scanner::schedule() — 60s default for a
	 * single manual run, a larger per-company delay from the daily batch.
	 */
	public static function schedule( $task_id, $delay = 60 ) {
		wp_schedule_single_event( time() + max( 1, (int) $delay ), self::CRON_HOOK, array( $task_id ) );
	}

	// -------------------------------------------------------------------------
	// Main runner
	// -------------------------------------------------------------------------

	public static function run( $task_id ) {
		self::refresh_security( $task_id, false );
		self::refresh_backups( $task_id, false );
	}

	/**
	 * Re-fetches only the Security Overview data (plugins/themes/core/PHP)
	 * and re-applies it to the task's checklist answers, without touching the
	 * company's Due Date Rule or the Backups data.
	 *
	 * @param bool $redirect Whether to redirect back after the fetch (true for
	 *                       the "Refresh" button — wp-admin or front-end;
	 *                       false for the scheduled run, which has no request
	 *                       to redirect).
	 */
	public static function refresh_security( $task_id, $redirect = true ) {
		$company_id = get_field( 'company', $task_id );
		$site_id    = $company_id ? trim( (string) get_field( 'kinsta_site_id', $company_id ) ) : '';

		$security = $site_id ? self::fetch_security( $site_id ) : array( 'error' => __( 'No Kinsta Site ID set on this task\'s company.', 'rw-site-review-tasks' ) );

		$results               = self::get_results( $task_id );
		$results['security']   = $security;
		$results['fetched_at'] = current_time( 'mysql' );
		update_post_meta( $task_id, self::META_KEY, $results );

		if ( ! isset( $security['error'] ) ) {
			self::apply_security_to_checklist( $task_id, $security );
		}

		if ( $redirect ) {
			srt_redirect_after_action( $task_id, 'srt_kinsta', 'security_refreshed' );
		}
	}

	/**
	 * Re-fetches only the Backups data — same "refresh this section only"
	 * contract as refresh_security().
	 */
	public static function refresh_backups( $task_id, $redirect = true ) {
		$company_id = get_field( 'company', $task_id );
		$site_id    = $company_id ? trim( (string) get_field( 'kinsta_site_id', $company_id ) ) : '';

		$backups = $site_id ? self::fetch_backups( $site_id ) : array( 'error' => __( 'No Kinsta Site ID set on this task\'s company.', 'rw-site-review-tasks' ) );

		$results               = self::get_results( $task_id );
		$results['backups']    = $backups;
		$results['fetched_at'] = current_time( 'mysql' );
		update_post_meta( $task_id, self::META_KEY, $results );

		if ( $redirect ) {
			srt_redirect_after_action( $task_id, 'srt_kinsta', 'backups_refreshed' );
		}
	}

	/**
	 * Raw stored results for a task: { security, backups, bot_protection, fetched_at }.
	 * Public — the front-end task page and both PDFs read this directly to
	 * decide what to show, same as the admin meta box.
	 */
	public static function get_results( $task_id ) {
		$results = get_post_meta( $task_id, self::META_KEY, true );
		return is_array( $results ) ? $results : array();
	}

	// -------------------------------------------------------------------------
	// URL builders — shared by the admin meta box and the front-end task page
	// -------------------------------------------------------------------------

	/**
	 * @param int    $task_id
	 * @param string $return_url Front-end page to redirect back to after the
	 *                           refresh; omit for the wp-admin default.
	 */
	public static function security_refresh_url( $task_id, $return_url = '' ) {
		$args = array(
			'action'  => self::REFRESH_SECURITY_ACTION,
			'task_id' => $task_id,
		);
		if ( $return_url ) {
			$args['srt_return'] = $return_url;
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'srt_refresh_kinsta_security_' . $task_id );
	}

	public static function backups_refresh_url( $task_id, $return_url = '' ) {
		$args = array(
			'action'  => self::REFRESH_BACKUPS_ACTION,
			'task_id' => $task_id,
		);
		if ( $return_url ) {
			$args['srt_return'] = $return_url;
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'srt_refresh_kinsta_backups_' . $task_id );
	}

	// -------------------------------------------------------------------------
	// Kinsta API client
	// -------------------------------------------------------------------------

	private static function api_key() {
		return trim( (string) get_option( self::API_KEY_OPT, '' ) );
	}

	private static function recommended_php() {
		$value = trim( (string) get_option( self::PHP_REC_OPT, '' ) );
		return '' !== $value ? $value : '8.3';
	}

	/**
	 * One authenticated call to the Kinsta API. Returns the decoded body on
	 * success, or an array with an 'error' key — never both.
	 */
	private static function request( $path, $method = 'GET' ) {
		$key = self::api_key();

		if ( ! $key ) {
			return array( 'error' => __( 'No Kinsta API key set in Settings.', 'rw-site-review-tasks' ) );
		}

		$response = wp_remote_request(
			self::API_BASE . $path,
			array(
				'method'  => $method,
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$msg = isset( $data['message'] )
				? $data['message']
				/* translators: %d: HTTP status code */
				: sprintf( __( 'Kinsta API returned HTTP %d.', 'rw-site-review-tasks' ), $code );
			return array( 'error' => $msg );
		}

		return is_array( $data ) ? $data : array( 'error' => __( 'Unexpected response from Kinsta.', 'rw-site-review-tasks' ) );
	}

	/**
	 * Resolves a company's Kinsta Site ID to its "live" environment's ID —
	 * the env-scoped endpoints (plugins/themes/backups) need this, not the
	 * site ID. Falls back to the first environment if none is named "live"
	 * (e.g. a plain/staging-only site).
	 *
	 * @return array{id:string,wordpress_version:string,php:string}|array{error:string}
	 */
	private static function resolve_live_environment( $site_id ) {
		$data = self::request( '/sites/' . rawurlencode( $site_id ) . '/environments' );

		if ( isset( $data['error'] ) ) {
			return $data;
		}

		$environments = isset( $data['site']['environments'] ) ? $data['site']['environments'] : array();

		if ( ! $environments ) {
			return array( 'error' => __( 'Kinsta returned no environments for this Site ID.', 'rw-site-review-tasks' ) );
		}

		$env = null;
		foreach ( $environments as $candidate ) {
			if ( isset( $candidate['name'] ) && 'live' === $candidate['name'] ) {
				$env = $candidate;
				break;
			}
		}
		if ( ! $env ) {
			$env = $environments[0];
		}

		return array(
			'id'                => isset( $env['id'] ) ? $env['id'] : '',
			'wordpress_version' => isset( $env['wordpress_version'] ) ? $env['wordpress_version'] : '',
			'php'               => isset( $env['container_info']['php_engine_version'] )
				? preg_replace( '/^php/i', '', $env['container_info']['php_engine_version'] )
				: '',
		);
	}

	/**
	 * The latest stable WordPress core version, from the free public
	 * WordPress.org API (no key, no Kinsta involvement) — used to tell
	 * whether an environment's wordpress_version is current.
	 */
	private static function latest_wp_version() {
		$response = wp_remote_get( 'https://api.wordpress.org/core/version-check/1.7/', array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$data  = json_decode( wp_remote_retrieve_body( $response ), true );
		$offer = isset( $data['offers'][0]['version'] ) ? $data['offers'][0]['version'] : '';

		return $offer;
	}

	/**
	 * Fetches and computes Security Overview: Core, Plugins, Theme, PHP
	 * Version — each as {status: 'ok'|'attention', detail: <human string>}.
	 */
	private static function fetch_security( $site_id ) {
		$env = self::resolve_live_environment( $site_id );

		if ( isset( $env['error'] ) ) {
			return $env;
		}

		if ( ! $env['id'] ) {
			return array( 'error' => __( 'Could not resolve a Kinsta environment for this Site ID.', 'rw-site-review-tasks' ) );
		}

		$plugins_data = self::request( '/sites/environments/' . rawurlencode( $env['id'] ) . '/wp-plugins' );
		$themes_data  = self::request( '/sites/environments/' . rawurlencode( $env['id'] ) . '/wp-themes' );

		$plugins = self::summarize_updates( $plugins_data, 'plugins' );
		$themes  = self::summarize_updates( $themes_data, 'themes' );

		// Core
		$latest = self::latest_wp_version();
		$core   = array( 'status' => 'ok', 'detail' => $env['wordpress_version'] ? 'WordPress ' . $env['wordpress_version'] : __( 'Unknown', 'rw-site-review-tasks' ) );
		if ( $env['wordpress_version'] && $latest && $env['wordpress_version'] !== $latest ) {
			$core['status'] = 'attention';
			$core['detail'] = sprintf(
				/* translators: 1: current WP version, 2: latest available WP version */
				__( 'WordPress %1$s (%2$s available)', 'rw-site-review-tasks' ),
				$env['wordpress_version'],
				$latest
			);
		}

		// PHP
		$recommended = self::recommended_php();
		$php         = array( 'status' => 'ok', 'detail' => $env['php'] ? 'PHP ' . $env['php'] : __( 'Unknown', 'rw-site-review-tasks' ) );
		if ( $env['php'] && version_compare( $env['php'], $recommended, '<' ) ) {
			$php['status'] = 'attention';
			$php['detail'] = sprintf(
				/* translators: 1: current PHP version, 2: recommended PHP version */
				__( 'PHP %1$s (%2$s recommended)', 'rw-site-review-tasks' ),
				$env['php'],
				$recommended
			);
		}

		return array(
			'environment_id' => $env['id'],
			'core'           => $core,
			'php'            => $php,
			'plugins'        => $plugins,
			'theme'          => $themes,
		);
	}

	/**
	 * Shared plugin/theme update-count summarizer — same response shape for
	 * both wp-plugins and wp-themes.
	 */
	private static function summarize_updates( $data, $kind ) {
		if ( isset( $data['error'] ) ) {
			return $data;
		}

		$items = isset( $data['environment'][ $kind ]['items'] ) ? $data['environment'][ $kind ]['items'] : null;

		if ( null === $items ) {
			return array( 'error' => __( 'Unexpected response from Kinsta.', 'rw-site-review-tasks' ) );
		}

		$updatable = array_values(
			array_filter(
				$items,
				function ( $item ) {
					return isset( $item['update'] ) && 'available' === $item['update'];
				}
			)
		);
		$count = count( $updatable );

		if ( 0 === $count ) {
			return array( 'status' => 'ok', 'detail' => __( 'Up to date', 'rw-site-review-tasks' ) );
		}

		$names = wp_list_pluck( $updatable, 'title' );

		return array(
			'status' => 'attention',
			'detail' => sprintf(
				/* translators: 1: number of updates available, 2: comma-separated names */
				_n( '%1$d update available (%2$s)', '%1$d updates available (%2$s)', $count, 'rw-site-review-tasks' ),
				$count,
				implode( ', ', array_slice( $names, 0, 5 ) ) . ( $count > 5 ? '…' : '' )
			),
		);
	}

	/**
	 * Fetches the backups list and returns the most recent backup's date.
	 */
	private static function fetch_backups( $site_id ) {
		$env = self::resolve_live_environment( $site_id );

		if ( isset( $env['error'] ) ) {
			return $env;
		}

		if ( ! $env['id'] ) {
			return array( 'error' => __( 'Could not resolve a Kinsta environment for this Site ID.', 'rw-site-review-tasks' ) );
		}

		$data = self::request( '/sites/environments/' . rawurlencode( $env['id'] ) . '/backups' );

		if ( isset( $data['error'] ) ) {
			return $data;
		}

		$backups = isset( $data['environment']['backups'] ) ? $data['environment']['backups'] : array();

		if ( ! $backups ) {
			return array( 'last_backup' => '', 'note' => __( 'No backups found.', 'rw-site-review-tasks' ) );
		}

		$latest_ms = 0;
		foreach ( $backups as $backup ) {
			$created = isset( $backup['created_at'] ) ? (int) $backup['created_at'] : 0;
			if ( $created > $latest_ms ) {
				$latest_ms = $created;
			}
		}

		return array(
			'last_backup' => $latest_ms ? wp_date( 'Y-m-d g:i A', (int) round( $latest_ms / 1000 ) ) : '',
		);
	}

	// -------------------------------------------------------------------------
	// Checklist auto-fill (Security Overview)
	// -------------------------------------------------------------------------

	/**
	 * Sets the Security Overview checklist items' answers from fetched Kinsta
	 * data — the checkbox stays the maker's manual confirmation, this just
	 * pre-fills it from the fetched value so they're not looking it up by
	 * hand. Only overwrites an item if the computed label ("Up to date" /
	 * "Needs attention") is actually one of that item's configured choices,
	 * so a company that customized the wording doesn't get a mismatched value
	 * forced in.
	 */
	private static function apply_security_to_checklist( $task_id, $security ) {
		$rows = get_field( 'checklist', $task_id );

		if ( ! is_array( $rows ) ) {
			return;
		}

		$map = array(
			'plugins'     => isset( $security['plugins'] ) ? $security['plugins'] : null,
			'theme'       => isset( $security['theme'] ) ? $security['theme'] : null,
			'core'        => isset( $security['core'] ) ? $security['core'] : null,
			'php version' => isset( $security['php'] ) ? $security['php'] : null,
		);

		$changed = false;

		foreach ( $rows as &$row ) {
			if ( 'radio' !== $row['field_type'] ) {
				continue;
			}

			$label = strtolower( trim( (string) $row['label'] ) );

			if ( ! isset( $map[ $label ] ) || ! $map[ $label ] || isset( $map[ $label ]['error'] ) ) {
				continue;
			}

			$computed = 'attention' === $map[ $label ]['status']
				? __( 'Needs attention', 'rw-site-review-tasks' )
				: __( 'Up to date', 'rw-site-review-tasks' );

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
	// Bot Protection — manual entry stopgap (see class comment)
	// -------------------------------------------------------------------------

	public static function handle_save_bot_protection() {
		$task_id = isset( $_POST['task_id'] ) ? absint( $_POST['task_id'] ) : 0;

		if ( ! $task_id || ! is_user_logged_in() || ! srt_user_can_manage_task( $task_id ) ) {
			wp_die( esc_html__( 'Not authorized.', 'rw-site-review-tasks' ) );
		}

		check_admin_referer( 'srt_bot_protection_' . $task_id );

		$results = self::get_results( $task_id );

		$results['bot_protection'] = array(
			'allowed'    => isset( $_POST['srt_bot_allowed'] ) ? max( 0, (int) $_POST['srt_bot_allowed'] ) : 0,
			'challenged' => isset( $_POST['srt_bot_challenged'] ) ? max( 0, (int) $_POST['srt_bot_challenged'] ) : 0,
			'blocked'    => isset( $_POST['srt_bot_blocked'] ) ? max( 0, (int) $_POST['srt_bot_blocked'] ) : 0,
		);

		update_post_meta( $task_id, self::META_KEY, $results );

		srt_redirect_after_action( $task_id, 'srt_kinsta', 'bot_protection_saved' );
	}

	// -------------------------------------------------------------------------
	// Admin: meta box
	// -------------------------------------------------------------------------

	public static function handle_refresh_security() {
		$task_id = isset( $_GET['task_id'] ) ? absint( $_GET['task_id'] ) : 0;

		if ( ! $task_id || ! is_user_logged_in() || ! srt_user_can_manage_task( $task_id ) ) {
			wp_die( esc_html__( 'Not authorized.', 'rw-site-review-tasks' ) );
		}

		check_admin_referer( 'srt_refresh_kinsta_security_' . $task_id );

		self::refresh_security( $task_id, true );
	}

	public static function handle_refresh_backups() {
		$task_id = isset( $_GET['task_id'] ) ? absint( $_GET['task_id'] ) : 0;

		if ( ! $task_id || ! is_user_logged_in() || ! srt_user_can_manage_task( $task_id ) ) {
			wp_die( esc_html__( 'Not authorized.', 'rw-site-review-tasks' ) );
		}

		check_admin_referer( 'srt_refresh_kinsta_backups_' . $task_id );

		self::refresh_backups( $task_id, true );
	}

	public static function add_meta_box() {
		add_meta_box(
			'srt_kinsta_data',
			__( 'Kinsta Data', 'rw-site-review-tasks' ),
			array( __CLASS__, 'render_admin_meta_box' ),
			SRT_CPT_Task::POST_TYPE,
			'normal',
			'low'
		);
	}

	public static function render_admin_meta_box( $post ) {
		$company_id = get_field( 'company', $post->ID );
		$site_id    = $company_id ? get_field( 'kinsta_site_id', $company_id ) : '';

		if ( ! $site_id ) {
			echo '<p>' . esc_html__( 'No Kinsta Site ID set on the company — add one to enable this.', 'rw-site-review-tasks' ) . '</p>';
			return;
		}

		$results  = self::get_results( $post->ID );
		$security = isset( $results['security'] ) ? $results['security'] : array();
		$backups  = isset( $results['backups'] ) ? $results['backups'] : array();
		$bot      = isset( $results['bot_protection'] ) ? $results['bot_protection'] : array();

		echo '<h4>' . esc_html__( 'Security Overview', 'rw-site-review-tasks' ) . '</h4>';
		if ( empty( $security ) ) {
			echo '<p>' . esc_html__( 'Not fetched yet — runs automatically alongside the site scan.', 'rw-site-review-tasks' ) . '</p>';
		} elseif ( isset( $security['error'] ) ) {
			echo '<p class="srt-scan-meta">' . esc_html( $security['error'] ) . '</p>';
		} else {
			echo self::render_security_overview_html( 0, $security ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '<p><a href="' . esc_url( self::security_refresh_url( $post->ID ) ) . '" class="button">' . esc_html__( 'Refresh Security Overview', 'rw-site-review-tasks' ) . '</a></p>';

		echo '<hr />';
		echo '<h4>' . esc_html__( 'Backups', 'rw-site-review-tasks' ) . '</h4>';
		if ( empty( $backups ) ) {
			echo '<p>' . esc_html__( 'Not fetched yet — runs automatically alongside the site scan.', 'rw-site-review-tasks' ) . '</p>';
		} elseif ( isset( $backups['error'] ) ) {
			echo '<p class="srt-scan-meta">' . esc_html( $backups['error'] ) . '</p>';
		} else {
			echo '<p>' . esc_html(
				! empty( $backups['last_backup'] )
					/* translators: %s: last backup date */
					? sprintf( __( 'Last backup: %s', 'rw-site-review-tasks' ), $backups['last_backup'] )
					: __( 'No backups found.', 'rw-site-review-tasks' )
			) . '</p>';
		}
		echo '<p><a href="' . esc_url( self::backups_refresh_url( $post->ID ) ) . '" class="button">' . esc_html__( 'Refresh Backups', 'rw-site-review-tasks' ) . '</a></p>';

		echo '<hr />';
		echo '<h4>' . esc_html__( 'Bot Protection (Marketing Guide PDF only)', 'rw-site-review-tasks' ) . '</h4>';
		echo '<p class="description">' . esc_html__( 'Kinsta doesn\'t expose Bot Protection analytics via its API yet, so enter today\'s Allowed/Challenged/Blocked totals from the MyKinsta dashboard by hand.', 'rw-site-review-tasks' ) . '</p>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="srt_save_bot_protection" />
			<input type="hidden" name="task_id" value="<?php echo esc_attr( $post->ID ); ?>" />
			<?php wp_nonce_field( 'srt_bot_protection_' . $post->ID ); ?>
			<p>
				<label><?php esc_html_e( 'Allowed', 'rw-site-review-tasks' ); ?>
					<input type="number" min="0" name="srt_bot_allowed" value="<?php echo esc_attr( isset( $bot['allowed'] ) ? $bot['allowed'] : '' ); ?>" class="small-text" />
				</label>
				&nbsp;
				<label><?php esc_html_e( 'Challenged', 'rw-site-review-tasks' ); ?>
					<input type="number" min="0" name="srt_bot_challenged" value="<?php echo esc_attr( isset( $bot['challenged'] ) ? $bot['challenged'] : '' ); ?>" class="small-text" />
				</label>
				&nbsp;
				<label><?php esc_html_e( 'Blocked', 'rw-site-review-tasks' ); ?>
					<input type="number" min="0" name="srt_bot_blocked" value="<?php echo esc_attr( isset( $bot['blocked'] ) ? $bot['blocked'] : '' ); ?>" class="small-text" />
				</label>
			</p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Save Bot Protection numbers', 'rw-site-review-tasks' ); ?></button></p>
		</form>
		<?php
	}

	public static function maybe_show_notice() {
		if ( empty( $_GET['srt_kinsta'] ) ) {
			return;
		}

		$messages = array(
			'security_refreshed'     => __( 'Security Overview refreshed.', 'rw-site-review-tasks' ),
			'backups_refreshed'      => __( 'Backups refreshed.', 'rw-site-review-tasks' ),
			'bot_protection_saved'   => __( 'Bot Protection numbers saved.', 'rw-site-review-tasks' ),
		);

		$key = sanitize_key( wp_unslash( $_GET['srt_kinsta'] ) );

		if ( ! isset( $messages[ $key ] ) ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
	}

	// -------------------------------------------------------------------------
	// Rendering helpers shared by admin meta box + front-end task page + both PDFs
	// -------------------------------------------------------------------------

	/**
	 * The Security Overview list — Core / Plugins / Theme / PHP Version, each
	 * with its computed status. Returns '' when there's nothing ready to show
	 * (no data yet, or the last fetch errored) so callers can skip the
	 * section entirely rather than print an empty list.
	 *
	 * @param int        $task_id  Ignored when $security is passed directly.
	 * @param array|null $security Pass the already-fetched security array to
	 *                             avoid a second get_results() lookup (the
	 *                             admin meta box does this); omit to fetch it.
	 */
	public static function render_security_overview_html( $task_id, $security = null ) {
		if ( null === $security ) {
			$results  = self::get_results( $task_id );
			$security = isset( $results['security'] ) ? $results['security'] : array();
		}

		if ( empty( $security ) || isset( $security['error'] ) ) {
			return '';
		}

		$labels = array(
			'core'    => __( 'Core', 'rw-site-review-tasks' ),
			'plugins' => __( 'Plugins', 'rw-site-review-tasks' ),
			'theme'   => __( 'Theme', 'rw-site-review-tasks' ),
			'php'     => __( 'PHP Version', 'rw-site-review-tasks' ),
		);

		ob_start();
		echo '<ul class="srt-scan-items">';
		foreach ( $labels as $key => $label ) {
			if ( empty( $security[ $key ] ) ) {
				continue;
			}
			$cls = 'attention' === $security[ $key ]['status'] ? 'srt-scan-item-error' : '';
			printf(
				'<li class="srt-scan-item %1$s"><span class="srt-scan-status">%2$s</span> <span class="srt-scan-msg">%3$s</span></li>',
				esc_attr( $cls ),
				esc_html( $label ),
				esc_html( $security[ $key ]['detail'] )
			);
		}
		echo '</ul>';
		return ob_get_clean();
	}

	/**
	 * The Backups line — "Last backup: <date>". Returns '' when there's
	 * nothing to show yet (not fetched, errored, or no backups found).
	 *
	 * @param int        $task_id Ignored when $backups is passed directly.
	 * @param array|null $backups Pass the already-fetched backups array to
	 *                            avoid a second get_results() lookup; omit to
	 *                            fetch it.
	 */
	public static function render_backups_html( $task_id, $backups = null ) {
		if ( null === $backups ) {
			$results = self::get_results( $task_id );
			$backups = isset( $results['backups'] ) ? $results['backups'] : array();
		}

		if ( empty( $backups ) || isset( $backups['error'] ) || empty( $backups['last_backup'] ) ) {
			return '';
		}

		return '<p class="rw-answer">' . sprintf(
			/* translators: %s: last backup date */
			esc_html__( 'Last backup: %s', 'rw-site-review-tasks' ),
			esc_html( $backups['last_backup'] )
		) . '</p>';
	}

	/**
	 * Bot Protection block HTML (MG-PDF/MG-context only per spec — callers
	 * are responsible for only calling this where that's appropriate).
	 */
	public static function render_bot_protection_html( $task_id ) {
		$results = self::get_results( $task_id );
		$bot     = isset( $results['bot_protection'] ) ? $results['bot_protection'] : array();

		if ( empty( $bot ) ) {
			return '';
		}

		$total = (int) $bot['allowed'] + (int) $bot['challenged'] + (int) $bot['blocked'];

		ob_start();
		?>
		<p class="rw-answer">
			<?php
			printf(
				/* translators: 1: allowed count, 2: challenged count, 3: blocked count, 4: total */
				esc_html__( 'Allowed: %1$d — Challenged: %2$d — Blocked: %3$d (%4$d total requests)', 'rw-site-review-tasks' ),
				(int) $bot['allowed'],
				(int) $bot['challenged'],
				(int) $bot['blocked'],
				$total
			);
			?>
		</p>
		<?php
		return ob_get_clean();
	}
}

SRT_Kinsta::init();
