<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRT_Site_Scanner {

	const META_KEY      = 'srt_scan_results';
	const CRON_HOOK     = 'srt_run_scan';
	const RESCAN_ACTION = 'srt_rescan_task';

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_post_' . self::RESCAN_ACTION, array( __CLASS__, 'handle_rescan' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_styles' ) );
	}

	/**
	 * Schedules the scan to run 60 seconds after task creation so it doesn't
	 * block the cron that created the task.
	 */
	public static function schedule( $task_id ) {
		wp_schedule_single_event( time() + 60, self::CRON_HOOK, array( $task_id ) );
	}

	// -------------------------------------------------------------------------
	// Main scan runner
	// -------------------------------------------------------------------------

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
				'html_errors'  => self::check_html( $url ),
				'accessibility'=> self::check_accessibility( $url ),
			);
		}

		// PageSpeed Insights runs once, on the homepage, when enabled for the company.
		if ( ! empty( $pages ) && get_field( 'psi_enabled', $company_id ) ) {
			$pages[0]['pagespeed'] = self::check_pagespeed( $pages[0]['url'], get_field( 'psi_strategy', $company_id ) );
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

	private static function check_links( $base_url ) {
		$response = wp_remote_get(
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

		foreach ( array_slice( $links, 0, 60 ) as $link ) {
			if ( time() - $start_time > 55 ) {
				break;
			}

			$check = wp_remote_request(
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
	// W3C Nu HTML validator
	// -------------------------------------------------------------------------

	private static function check_html( $url ) {
		$api_url  = 'https://validator.w3.org/nu/?doc=' . rawurlencode( $url ) . '&out=json';
		$response = wp_remote_get(
			$api_url,
			array(
				'timeout'    => 30,
				'user-agent' => 'RW-SiteReviewScanner/1.0',
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $data['messages'] ) ) {
			return array( 'error' => __( 'Unexpected response from W3C validator.', 'rw-site-review-tasks' ) );
		}

		$errors   = array();
		$warnings = array();

		foreach ( $data['messages'] as $msg ) {
			$item = array(
				'message' => isset( $msg['message'] ) ? $msg['message'] : '',
				'line'    => isset( $msg['lastLine'] ) ? (int) $msg['lastLine'] : null,
				'extract' => isset( $msg['extract'] ) ? substr( $msg['extract'], 0, 200 ) : '',
			);

			if ( isset( $msg['type'] ) && 'error' === $msg['type'] ) {
				$errors[] = $item;
			} else {
				$warnings[] = $item;
			}
		}

		return array(
			'errors'   => array_slice( $errors, 0, 30 ),
			'warnings' => array_slice( $warnings, 0, 20 ),
		);
	}

	// -------------------------------------------------------------------------
	// WAVE accessibility API
	// -------------------------------------------------------------------------

	private static function check_accessibility( $url ) {
		$key = get_option( 'srt_wave_api_key', '' );

		if ( ! $key ) {
			return array( 'skipped' => true );
		}

		$api_url = add_query_arg(
			array(
				'key'        => $key,
				'url'        => $url,
				'reporttype' => 2,
			),
			'https://wave.webaim.org/api/request'
		);

		$response = wp_remote_get( $api_url, array( 'timeout' => 30 ) );

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $data['status']['error'] ) ) {
			$msg = isset( $data['status']['description'] ) ? $data['status']['description'] : __( 'WAVE API error.', 'rw-site-review-tasks' );
			return array( 'error' => $msg );
		}

		if ( ! isset( $data['categories'] ) ) {
			return array( 'error' => __( 'Unexpected response from WAVE.', 'rw-site-review-tasks' ) );
		}

		return array( 'categories' => $data['categories'] );
	}

	// -------------------------------------------------------------------------
	// Google PageSpeed Insights (Lighthouse) API
	// -------------------------------------------------------------------------

	private static function check_pagespeed( $url, $strategy ) {
		$strategy = in_array( $strategy, array( 'mobile', 'desktop' ), true ) ? $strategy : 'mobile';

		$api_url = add_query_arg(
			array(
				'url'      => $url,
				'strategy' => $strategy,
				'category' => array( 'performance', 'accessibility', 'best-practices', 'seo' ),
			),
			'https://www.googleapis.com/pagespeedonline/v5/runPagespeed'
		);

		$key = get_option( 'srt_psi_api_key', '' );
		if ( $key ) {
			$api_url = add_query_arg( 'key', $key, $api_url );
		}

		$response = wp_remote_get( $api_url, array( 'timeout' => 60 ) );

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$msg = isset( $data['error']['message'] )
				? $data['error']['message']
				/* translators: %d: HTTP status code */
				: sprintf( __( 'PageSpeed API returned HTTP %d.', 'rw-site-review-tasks' ), $code );
			return array( 'error' => $msg );
		}

		if ( ! isset( $data['lighthouseResult'] ) ) {
			return array( 'error' => __( 'Unexpected response from PageSpeed Insights.', 'rw-site-review-tasks' ) );
		}

		$lh   = $data['lighthouseResult'];
		$cats = isset( $lh['categories'] ) ? $lh['categories'] : array();

		$scores = array();
		foreach ( array( 'performance', 'accessibility', 'best-practices', 'seo' ) as $cat ) {
			if ( isset( $cats[ $cat ]['score'] ) && null !== $cats[ $cat ]['score'] ) {
				$scores[ $cat ] = (int) round( $cats[ $cat ]['score'] * 100 );
			}
		}

		$audits      = isset( $lh['audits'] ) ? $lh['audits'] : array();
		$metric_keys = array(
			'first-contentful-paint'   => __( 'First Contentful Paint', 'rw-site-review-tasks' ),
			'largest-contentful-paint' => __( 'Largest Contentful Paint', 'rw-site-review-tasks' ),
			'total-blocking-time'      => __( 'Total Blocking Time', 'rw-site-review-tasks' ),
			'cumulative-layout-shift'  => __( 'Cumulative Layout Shift', 'rw-site-review-tasks' ),
			'speed-index'              => __( 'Speed Index', 'rw-site-review-tasks' ),
		);

		$metrics = array();
		foreach ( $metric_keys as $audit_key => $label ) {
			if ( isset( $audits[ $audit_key ]['displayValue'] ) ) {
				$metrics[] = array(
					'label' => $label,
					'value' => $audits[ $audit_key ]['displayValue'],
					'score' => isset( $audits[ $audit_key ]['score'] ) ? $audits[ $audit_key ]['score'] : null,
				);
			}
		}

		return array(
			'strategy' => $strategy,
			'scores'   => $scores,
			'metrics'  => $metrics,
		);
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

		$rescan_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => self::RESCAN_ACTION,
					'task_id' => $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			'srt_rescan_' . $post->ID
		);
		echo '<p><a href="' . esc_url( $rescan_url ) . '" class="button">' . esc_html__( 'Re-scan now', 'rw-site-review-tasks' ) . '</a></p>';
	}

	public static function handle_rescan() {
		$task_id = isset( $_GET['task_id'] ) ? absint( $_GET['task_id'] ) : 0;

		if ( ! $task_id || ! current_user_can( 'edit_post', $task_id ) ) {
			wp_die( esc_html__( 'Not authorized.', 'rw-site-review-tasks' ) );
		}

		check_admin_referer( 'srt_rescan_' . $task_id );

		self::run( $task_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post'       => $task_id,
					'action'     => 'edit',
					'srt_notice' => 'rescanned',
				),
				admin_url( 'post.php' )
			)
		);
		exit;
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
					'html_errors'  => isset( $results['html_errors'] ) ? $results['html_errors'] : array(),
					'accessibility'=> isset( $results['accessibility'] ) ? $results['accessibility'] : array(),
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
				<?php
				self::render_broken_links( $page, $collapse );
				self::render_html_issues( $page, $collapse );
				self::render_accessibility( $page, $collapse );
				self::render_pagespeed( $page, $collapse );
				?>
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
		$count   = count( $broken );

		$summary = $count > 0
			/* translators: 1: broken count, 2: total checked */
			? sprintf( _n( '%1$d broken of %2$d checked', '%1$d broken of %2$d checked', $count, 'rw-site-review-tasks' ), $count, $checked )
			/* translators: %d: total links checked */
			: sprintf( _n( 'All OK — %d link checked', 'All OK — %d links checked', $checked, 'rw-site-review-tasks' ), $checked );

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

	private static function render_html_issues( $results, $collapse = false ) {
		$data = isset( $results['html_errors'] ) ? $results['html_errors'] : array();

		if ( isset( $data['error'] ) ) {
			self::render_section_header( __( 'HTML Validation', 'rw-site-review-tasks' ), $data['error'], 'warn', ! $collapse );
			return;
		}

		$errors   = isset( $data['errors'] ) ? $data['errors'] : array();
		$warnings = isset( $data['warnings'] ) ? $data['warnings'] : array();
		$ec       = count( $errors );
		$wc       = count( $warnings );

		if ( $ec > 0 ) {
			/* translators: 1: error count, 2: warning count */
			$summary = sprintf( __( '%1$d error(s), %2$d warning(s)', 'rw-site-review-tasks' ), $ec, $wc );
			$level   = 'error';
		} elseif ( $wc > 0 ) {
			/* translators: %d: warning count */
			$summary = sprintf( _n( '%d warning', '%d warnings', $wc, 'rw-site-review-tasks' ), $wc );
			$level   = 'warn';
		} else {
			$summary = __( 'No issues found', 'rw-site-review-tasks' );
			$level   = 'ok';
		}

		?>
		<details class="srt-scan-section" <?php echo ( ! $collapse && $ec > 0 ) ? 'open' : ''; ?>>
			<summary class="srt-scan-summary">
				<span class="srt-scan-label"><?php esc_html_e( 'HTML Validation', 'rw-site-review-tasks' ); ?></span>
				<span class="srt-scan-badge srt-scan-badge-<?php echo esc_attr( $level ); ?>"><?php echo esc_html( $summary ); ?></span>
			</summary>
			<?php if ( $errors || $warnings ) : ?>
				<ul class="srt-scan-items">
					<?php foreach ( $errors as $item ) : ?>
						<li class="srt-scan-item srt-scan-item-error">
							<?php if ( $item['line'] ) : ?>
								<span class="srt-scan-status"><?php printf( esc_html__( 'Line %d', 'rw-site-review-tasks' ), (int) $item['line'] ); ?></span>
							<?php endif; ?>
							<span class="srt-scan-msg"><?php echo esc_html( $item['message'] ); ?></span>
							<?php if ( $item['extract'] ) : ?>
								<code class="srt-scan-extract"><?php echo esc_html( $item['extract'] ); ?></code>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
					<?php foreach ( $warnings as $item ) : ?>
						<li class="srt-scan-item srt-scan-item-warn">
							<?php if ( $item['line'] ) : ?>
								<span class="srt-scan-status"><?php printf( esc_html__( 'Line %d', 'rw-site-review-tasks' ), (int) $item['line'] ); ?></span>
							<?php endif; ?>
							<span class="srt-scan-msg"><?php echo esc_html( $item['message'] ); ?></span>
							<?php if ( $item['extract'] ) : ?>
								<code class="srt-scan-extract"><?php echo esc_html( $item['extract'] ); ?></code>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</details>
		<?php
	}

	private static function render_accessibility( $results, $collapse = false ) {
		$data = isset( $results['accessibility'] ) ? $results['accessibility'] : array();

		if ( ! empty( $data['skipped'] ) ) {
			?>
			<details class="srt-scan-section">
				<summary class="srt-scan-summary">
					<span class="srt-scan-label"><?php esc_html_e( 'Accessibility (WAVE)', 'rw-site-review-tasks' ); ?></span>
					<span class="srt-scan-badge srt-scan-badge-ok"><?php esc_html_e( 'No API key — skipped', 'rw-site-review-tasks' ); ?></span>
				</summary>
				<p class="srt-scan-skip-note">
					<?php
					printf(
						/* translators: %s: settings URL */
						esc_html__( 'Add a WAVE API key in %s to enable accessibility scanning.', 'rw-site-review-tasks' ),
						'<a href="' . esc_url( add_query_arg( array( 'post_type' => SRT_CPT_Company::POST_TYPE, 'page' => SRT_Admin_Settings::PAGE_SLUG ), admin_url( 'edit.php' ) ) ) . '">' . esc_html__( 'Settings', 'rw-site-review-tasks' ) . '</a>'
					);
					?>
				</p>
			</details>
			<?php
			return;
		}

		if ( isset( $data['error'] ) ) {
			self::render_section_header( __( 'Accessibility (WAVE)', 'rw-site-review-tasks' ), $data['error'], 'warn', ! $collapse );
			return;
		}

		$categories = isset( $data['categories'] ) ? $data['categories'] : array();

		// Only show error, contrast, alert — skip feature/structure/aria (those are good)
		$show_cats = array( 'error', 'contrast', 'alert' );
		$total_issues = 0;
		foreach ( $show_cats as $cat ) {
			if ( isset( $categories[ $cat ]['count'] ) ) {
				$total_issues += (int) $categories[ $cat ]['count'];
			}
		}

		$err_count  = isset( $categories['error']['count'] ) ? (int) $categories['error']['count'] : 0;
		$con_count  = isset( $categories['contrast']['count'] ) ? (int) $categories['contrast']['count'] : 0;
		$alrt_count = isset( $categories['alert']['count'] ) ? (int) $categories['alert']['count'] : 0;

		if ( $total_issues > 0 ) {
			$parts = array();
			if ( $err_count )  $parts[] = sprintf( _n( '%d error', '%d errors', $err_count, 'rw-site-review-tasks' ), $err_count );
			if ( $con_count )  $parts[] = sprintf( _n( '%d contrast issue', '%d contrast issues', $con_count, 'rw-site-review-tasks' ), $con_count );
			if ( $alrt_count ) $parts[] = sprintf( _n( '%d alert', '%d alerts', $alrt_count, 'rw-site-review-tasks' ), $alrt_count );
			$summary = implode( ', ', $parts );
			$level   = $err_count > 0 || $con_count > 0 ? 'error' : 'warn';
		} else {
			$summary = __( 'No issues found', 'rw-site-review-tasks' );
			$level   = 'ok';
		}

		?>
		<details class="srt-scan-section" <?php echo ( ! $collapse && $total_issues > 0 ) ? 'open' : ''; ?>>
			<summary class="srt-scan-summary">
				<span class="srt-scan-label"><?php esc_html_e( 'Accessibility (WAVE)', 'rw-site-review-tasks' ); ?></span>
				<span class="srt-scan-badge srt-scan-badge-<?php echo esc_attr( $level ); ?>"><?php echo esc_html( $summary ); ?></span>
			</summary>
			<?php if ( $total_issues > 0 ) : ?>
				<ul class="srt-scan-items">
					<?php
					foreach ( $show_cats as $cat ) :
						if ( empty( $categories[ $cat ]['items'] ) ) {
							continue;
						}
						$cat_label = isset( $categories[ $cat ]['description'] ) ? $categories[ $cat ]['description'] : $cat;
						$item_class = ( 'alert' === $cat ) ? 'srt-scan-item-warn' : 'srt-scan-item-error';
						foreach ( $categories[ $cat ]['items'] as $item ) :
					?>
						<li class="srt-scan-item <?php echo esc_attr( $item_class ); ?>">
							<span class="srt-scan-status"><?php echo esc_html( $cat_label ); ?></span>
							<span class="srt-scan-msg">
								<?php
								$desc  = isset( $item['description'] ) ? $item['description'] : ( isset( $item['id'] ) ? $item['id'] : '' );
								$count = isset( $item['count'] ) ? (int) $item['count'] : 0;
								echo esc_html( $desc );
								if ( $count > 1 ) {
									echo ' <em>(' . esc_html( sprintf( _n( '%d instance', '%d instances', $count, 'rw-site-review-tasks' ), $count ) ) . ')</em>';
								}
								?>
							</span>
						</li>
					<?php
						endforeach;
					endforeach;
					?>
				</ul>
			<?php endif; ?>
		</details>
		<?php
	}

	private static function render_pagespeed( $results, $collapse = false ) {
		if ( empty( $results['pagespeed'] ) ) {
			return; // Only the homepage carries PageSpeed data, and only when enabled.
		}

		$data = $results['pagespeed'];

		if ( isset( $data['error'] ) ) {
			self::render_section_header( __( 'PageSpeed (Lighthouse)', 'rw-site-review-tasks' ), $data['error'], 'warn', ! $collapse );
			return;
		}

		$scores   = isset( $data['scores'] ) ? $data['scores'] : array();
		$metrics  = isset( $data['metrics'] ) ? $data['metrics'] : array();
		$strategy = isset( $data['strategy'] ) ? $data['strategy'] : 'mobile';

		$perf = isset( $scores['performance'] ) ? $scores['performance'] : null;

		if ( null !== $perf ) {
			$level   = $perf >= 90 ? 'ok' : ( $perf >= 50 ? 'warn' : 'error' );
			$summary = sprintf(
				/* translators: 1: performance score 0-100, 2: strategy (Mobile/Desktop) */
				__( 'Performance %1$d/100 (%2$s)', 'rw-site-review-tasks' ),
				$perf,
				ucfirst( $strategy )
			);
		} else {
			$level   = 'warn';
			$summary = __( 'No score returned', 'rw-site-review-tasks' );
		}

		$cat_labels = array(
			'performance'    => __( 'Performance', 'rw-site-review-tasks' ),
			'accessibility'  => __( 'Accessibility', 'rw-site-review-tasks' ),
			'best-practices' => __( 'Best Practices', 'rw-site-review-tasks' ),
			'seo'            => __( 'SEO', 'rw-site-review-tasks' ),
		);
		?>
		<details class="srt-scan-section" <?php echo ( ! $collapse && null !== $perf && $perf < 90 ) ? 'open' : ''; ?>>
			<summary class="srt-scan-summary">
				<span class="srt-scan-label"><?php esc_html_e( 'PageSpeed (Lighthouse)', 'rw-site-review-tasks' ); ?></span>
				<span class="srt-scan-badge srt-scan-badge-<?php echo esc_attr( $level ); ?>"><?php echo esc_html( $summary ); ?></span>
			</summary>
			<ul class="srt-scan-items">
				<?php foreach ( $cat_labels as $key => $label ) : ?>
					<?php
					if ( ! isset( $scores[ $key ] ) ) {
						continue;
					}
					$score     = $scores[ $key ];
					$score_cls = $score >= 90 ? '' : ( $score >= 50 ? 'srt-scan-item-warn' : 'srt-scan-item-error' );
					?>
					<li class="srt-scan-item <?php echo esc_attr( $score_cls ); ?>">
						<span class="srt-scan-status"><?php echo esc_html( $label ); ?></span>
						<span class="srt-scan-msg"><?php echo esc_html( $score . '/100' ); ?></span>
					</li>
				<?php endforeach; ?>
				<?php foreach ( $metrics as $metric ) : ?>
					<?php
					$metric_cls = '';
					if ( isset( $metric['score'] ) && null !== $metric['score'] && $metric['score'] < 0.9 ) {
						$metric_cls = $metric['score'] < 0.5 ? 'srt-scan-item-error' : 'srt-scan-item-warn';
					}
					?>
					<li class="srt-scan-item <?php echo esc_attr( $metric_cls ); ?>">
						<span class="srt-scan-status"><?php echo esc_html( $metric['label'] ); ?></span>
						<span class="srt-scan-msg"><?php echo esc_html( $metric['value'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
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
