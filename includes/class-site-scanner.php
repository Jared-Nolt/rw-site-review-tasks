<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRT_Site_Scanner {

	const META_KEY      = 'srt_scan_results';
	const CRON_HOOK      = 'srt_run_scan';
	const RESCAN_ACTION  = 'srt_rescan_task';

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_post_' . self::RESCAN_ACTION, array( __CLASS__, 'handle_rescan' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_styles' ) );
	}

	/**
	 * Schedules the scan to run some seconds after task creation so it doesn't
	 * block the cron that created the task.
	 *
	 * $delay defaults to 60s (a single manual "Create review task now" run).
	 * The daily batch check passes a larger, per-company delay so that when
	 * several companies share a due date, their scans don't all land on the
	 * same WP-Cron pass. See SRT_Cron::run_daily_check().
	 */
	public static function schedule( $task_id, $delay = 60 ) {
		wp_schedule_single_event( time() + max( 1, (int) $delay ), self::CRON_HOOK, array( $task_id ) );
	}

	// -------------------------------------------------------------------------
	// Main scan runner
	// -------------------------------------------------------------------------

	/**
	 * Broken-link check only. WAVE accessibility, W3C HTML validation, and
	 * Google PageSpeed Insights were removed from this scanner — page speed is
	 * now handled by GTmetrix (class-gtmetrix.php) and accessibility/HTML
	 * validation were dropped outright. See instructions.md.
	 */
	public static function run( $task_id ) {
		$company_id = get_field( 'company', $task_id );
		if ( ! $company_id ) {
			return;
		}

		$base_url = get_field( 'website_url', $company_id );
		if ( ! $base_url ) {
			return;
		}

		// Build URL list: homepage first, then up to 5 manually added pages.
		$urls        = array( $base_url );
		$extra_pages = get_field( 'scan_pages', $company_id );
		if ( is_array( $extra_pages ) ) {
			foreach ( $extra_pages as $page ) {
				if ( ! empty( $page['page_url'] ) ) {
					$urls[] = $page['page_url'];
				}
			}
		}
		$urls = array_slice( array_unique( $urls ), 0, 6 ); // homepage + max 5

		$pages = array();
		foreach ( $urls as $url ) {
			$pages[] = array(
				'url'          => $url,
				'broken_links' => self::check_links( $url ),
			);
		}

		$results = array(
			'scanned_at' => current_time( 'mysql' ),
			'pages'      => $pages,
		);

		update_post_meta( $task_id, self::META_KEY, $results );
	}

	// -------------------------------------------------------------------------
	// Broken link checker
	// -------------------------------------------------------------------------

	/**
	 * Whether a scan target must not be requested.
	 *
	 * wp_http_validate_url() — the check the wp_safe_remote_* wrappers apply —
	 * rejects loopback and RFC1918 hosts, resolving the name first so a DNS record
	 * aimed at a private range is caught too, and limits ports to 80/443/8080.
	 * Calling it directly lets a blocked link be counted as skipped rather than
	 * surfacing as a bogus "broken link" in the client's report.
	 *
	 * It does not cover 169.254.0.0/16, where cloud instance metadata
	 * (169.254.169.254) lives, so that range is filtered here as well.
	 */
	private static function is_unsafe_target( $url ) {
		if ( ! wp_http_validate_url( $url ) ) {
			return true;
		}

		$host = strtolower( trim( (string) wp_parse_url( $url, PHP_URL_HOST ), '.' ) );

		if ( '' === $host ) {
			return true;
		}

		$ip = filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? $host : gethostbyname( $host );

		return 0 === strpos( $ip, '169.254.' ) || 'fe80:' === strtolower( substr( $ip, 0, 5 ) );
	}

	private static function check_links( $base_url ) {
		$response = wp_safe_remote_get(
			$base_url,
			array(
				'timeout'    => 20,
				'user-agent' => 'RW-SiteReviewScanner/1.0 (link checker)',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$html  = wp_remote_retrieve_body( $response );
		$links = self::extract_links( $html, $base_url );
		$total = count( $links );

		$broken     = array();
		$start_time = time();

		$skipped = 0;

		foreach ( array_slice( $links, 0, 60 ) as $link ) {
			if ( time() - $start_time > 55 ) {
				break;
			}

			// These URLs come off the scanned page, so they are third-party input:
			// a link planted in a comment could otherwise point the server at an
			// internal address. Skipped targets are counted, never reported as
			// broken — a blocked link says nothing about the client's site.
			if ( self::is_unsafe_target( $link ) ) {
				$skipped++;
				continue;
			}

			$check = wp_safe_remote_request(
				$link,
				array(
					'method'      => 'HEAD',
					'timeout'     => 8,
					'redirection' => 5,
					'user-agent'  => 'RW-SiteReviewScanner/1.0',
				)
			);

			if ( is_wp_error( $check ) ) {
				$broken[] = array(
					'url'    => $link,
					'status' => 0,
					'label'  => $check->get_error_message(),
				);
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $check );
			if ( $code >= 400 ) {
				$broken[] = array(
					'url'    => $link,
					'status' => $code,
					'label'  => self::http_label( $code ),
				);
			}
		}

		return array(
			'checked' => $total,
			'broken'  => $broken,
			'skipped' => $skipped,
		);
	}

	private static function extract_links( $html, $base_url ) {
		if ( ! $html ) {
			return array();
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();

		$parts  = wp_parse_url( $base_url );
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = isset( $parts['host'] ) ? $parts['host'] : '';

		$links = array();

		foreach ( $dom->getElementsByTagName( 'a' ) as $anchor ) {
			$href = trim( $anchor->getAttribute( 'href' ) );

			if ( ! $href ) {
				continue;
			}

			// Skip non-navigational hrefs
			if (
				'#' === $href[0] ||
				0 === strpos( $href, 'mailto:' ) ||
				0 === strpos( $href, 'tel:' ) ||
				0 === strpos( $href, 'javascript:' ) ||
				0 === strpos( $href, 'data:' )
			) {
				continue;
			}

			// Make absolute
			if ( 0 === strpos( $href, '//' ) ) {
				$href = $scheme . ':' . $href;
			} elseif ( '/' === $href[0] ) {
				$href = $scheme . '://' . $host . $href;
			} elseif ( ! preg_match( '/^https?:\/\//i', $href ) ) {
				continue;
			}

			// Strip fragment
			$href = preg_replace( '/#.*$/', '', $href );

			if ( $href ) {
				$links[] = $href;
			}
		}

		return array_values( array_unique( $links ) );
	}

	private static function http_label( $code ) {
		$labels = array(
			400 => 'Bad Request',
			401 => 'Unauthorized',
			403 => 'Forbidden',
			404 => 'Not Found',
			405 => 'Method Not Allowed',
			410 => 'Gone',
			429 => 'Too Many Requests',
			500 => 'Server Error',
			502 => 'Bad Gateway',
			503 => 'Service Unavailable',
		);
		return isset( $labels[ $code ] ) ? $code . ' ' . $labels[ $code ] : (string) $code;
	}

	// -------------------------------------------------------------------------
	// Admin: meta box
	// -------------------------------------------------------------------------

	public static function add_meta_box() {
		add_meta_box(
			'srt_scan_results',
			__( 'Site Scan Results', 'rw-site-review-tasks' ),
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
			echo '<p>' . esc_html__( 'No website URL set on the company — add one to enable scanning.', 'rw-site-review-tasks' ) . '</p>';
			return;
		}

		$results = get_post_meta( $post->ID, self::META_KEY, true );

		if ( $results ) {
			echo '<p class="srt-scan-meta">' . esc_html(
				sprintf(
					/* translators: %s: timestamp */
					__( 'Last scanned: %s', 'rw-site-review-tasks' ),
					$results['scanned_at']
				)
			) . '</p>';
			echo self::render_results_html( $results ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '<p>' . esc_html__( 'Scan pending — runs automatically ~60 seconds after task creation.', 'rw-site-review-tasks' ) . '</p>';
		}

		echo '<p><a href="' . esc_url( self::rescan_url( $post->ID ) ) . '" class="button">' . esc_html__( 'Re-scan now', 'rw-site-review-tasks' ) . '</a></p>';
	}

	/**
	 * @param int    $task_id
	 * @param string $return_url Front-end page to redirect back to after the
	 *                           rescan; omit for the wp-admin default.
	 */
	public static function rescan_url( $task_id, $return_url = '' ) {
		$args = array(
			'action'  => self::RESCAN_ACTION,
			'task_id' => $task_id,
		);
		if ( $return_url ) {
			$args['srt_return'] = $return_url;
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'srt_rescan_' . $task_id );
	}

	public static function handle_rescan() {
		$task_id = isset( $_GET['task_id'] ) ? absint( $_GET['task_id'] ) : 0;

		if ( ! $task_id || ! is_user_logged_in() || ! srt_user_can_manage_task( $task_id ) ) {
			wp_die( esc_html__( 'Not authorized.', 'rw-site-review-tasks' ) );
		}

		check_admin_referer( 'srt_rescan_' . $task_id );

		self::run( $task_id );

		srt_redirect_after_action( $task_id, 'srt_notice', 'rescanned' );
	}

	public static function maybe_show_notice() {
		if ( isset( $_GET['srt_notice'] ) && 'rescanned' === $_GET['srt_notice'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'Site scan complete. Results updated below.', 'rw-site-review-tasks' ) .
				'</p></div>';
		}
	}

	public static function maybe_enqueue_styles( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		$post_type = isset( $_GET['post'] ) ? get_post_type( (int) $_GET['post'] ) : ( isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '' );
		if ( SRT_CPT_Task::POST_TYPE === $post_type ) {
			wp_enqueue_style( 'srt-dashboard' );
		}
	}

	// -------------------------------------------------------------------------
	// Shared results renderer (admin meta box + frontend task view)
	// -------------------------------------------------------------------------

	public static function render_results_html( $results, $collapse = false ) {
		// Legacy single-page format: wrap into pages structure for unified rendering.
		if ( isset( $results['url'] ) && ! isset( $results['pages'] ) ) {
			$results['pages'] = array(
				array(
					'url'          => $results['url'],
					'broken_links' => isset( $results['broken_links'] ) ? $results['broken_links'] : array(),
				),
			);
		}

		$pages = isset( $results['pages'] ) ? $results['pages'] : array();

		ob_start();
		echo '<div class="srt-scan-results">';
		foreach ( $pages as $index => $page ) {
			$is_homepage = ( 0 === $index );
			?>
			<div class="srt-scan-page">
				<h4 class="srt-scan-page-title">
					<?php echo $is_homepage ? esc_html__( 'Homepage', 'rw-site-review-tasks' ) : esc_html__( 'Page', 'rw-site-review-tasks' ); ?>
					&mdash;
					<a href="<?php echo esc_url( $page['url'] ); ?>" target="_blank" rel="noopener" class="srt-scan-page-url"><?php echo esc_html( $page['url'] ); ?></a>
				</h4>
				<?php self::render_broken_links( $page, $collapse ); ?>
			</div>
			<?php
		}
		echo '</div>';
		return ob_get_clean();
	}

	private static function render_broken_links( $results, $collapse = false ) {
		$data = isset( $results['broken_links'] ) ? $results['broken_links'] : array();

		if ( isset( $data['error'] ) ) {
			self::render_section_header( __( 'Broken Links', 'rw-site-review-tasks' ), $data['error'], 'warn', ! $collapse );
			return;
		}

		$broken  = isset( $data['broken'] ) ? $data['broken'] : array();
		$checked = isset( $data['checked'] ) ? (int) $data['checked'] : 0;
		$skipped = isset( $data['skipped'] ) ? (int) $data['skipped'] : 0;
		$count   = count( $broken );

		$summary = $count > 0
			/* translators: 1: broken count, 2: total checked */
			? sprintf( _n( '%1$d broken of %2$d checked', '%1$d broken of %2$d checked', $count, 'rw-site-review-tasks' ), $count, $checked )
			/* translators: %d: total links checked */
			: sprintf( _n( 'All OK — %d link checked', 'All OK — %d links checked', $checked, 'rw-site-review-tasks' ), $checked );

		// Never let a reduced-coverage scan read as a clean one.
		if ( $skipped > 0 ) {
			$summary .= sprintf(
				/* translators: %d: number of links not requested because they pointed at an internal address */
				_n( ', %d skipped as unsafe', ', %d skipped as unsafe', $skipped, 'rw-site-review-tasks' ),
				$skipped
			);
		}

		$level = $count > 0 ? 'error' : 'ok';

		?>
		<details class="srt-scan-section" <?php echo ( ! $collapse && $count > 0 ) ? 'open' : ''; ?>>
			<summary class="srt-scan-summary">
				<span class="srt-scan-label"><?php esc_html_e( 'Broken Links', 'rw-site-review-tasks' ); ?></span>
				<span class="srt-scan-badge srt-scan-badge-<?php echo esc_attr( $level ); ?>"><?php echo esc_html( $summary ); ?></span>
			</summary>
			<?php if ( $count > 0 ) : ?>
				<ul class="srt-scan-items">
					<?php foreach ( $broken as $item ) : ?>
						<li class="srt-scan-item srt-scan-item-error">
							<span class="srt-scan-status"><?php echo esc_html( $item['label'] ); ?></span>
							<a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener" class="srt-scan-url"><?php echo esc_html( $item['url'] ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</details>
		<?php
	}

	private static function render_section_header( $label, $message, $level, $open = false ) {
		?>
		<details class="srt-scan-section" <?php echo $open ? 'open' : ''; ?>>
			<summary class="srt-scan-summary">
				<span class="srt-scan-label"><?php echo esc_html( $label ); ?></span>
				<span class="srt-scan-badge srt-scan-badge-<?php echo esc_attr( $level ); ?>"><?php echo esc_html( $message ); ?></span>
			</summary>
		</details>
		<?php
	}
}

SRT_Site_Scanner::init();
