<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRT_Task_Print {

	const QUERY_VAR    = 'srt_print_task';
	const QUERY_VAR_MG = 'srt_print_task_mg';

	public static function init() {
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
	}

	public static function add_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::QUERY_VAR_MG;
		return $vars;
	}

	public static function url( $task_id ) {
		return add_query_arg( self::QUERY_VAR, $task_id, home_url( '/' ) );
	}

	public static function mg_url( $task_id ) {
		return add_query_arg( self::QUERY_VAR_MG, $task_id, home_url( '/' ) );
	}

	public static function maybe_render() {
		$is_mg   = false;
		$task_id = (int) get_query_var( self::QUERY_VAR );

		if ( ! $task_id ) {
			$task_id = (int) get_query_var( self::QUERY_VAR_MG );
			$is_mg   = (bool) $task_id;
		}

		if ( ! $task_id ) {
			return;
		}

		if ( SRT_CPT_Task::POST_TYPE !== get_post_type( $task_id ) ) {
			wp_die( esc_html__( 'Task not found.', 'rw-site-review-tasks' ), '', array( 'response' => 404 ) );
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		if ( ! srt_user_can_access_task( $task_id ) ) {
			wp_die( esc_html__( 'You do not have access to this task.', 'rw-site-review-tasks' ), '', array( 'response' => 403 ) );
		}

		self::render( $task_id, $is_mg );
		exit;
	}

	private static function render( $task_id, $is_mg ) {
		$company_id = get_field( 'company', $task_id );
		$period     = get_field( 'period', $task_id );
		$summary    = get_field( 'executive_summary', $task_id );
		$rows       = get_field( 'checklist', $task_id );
		$notes      = get_field( 'extra_notes', $task_id );
		$scan       = $is_mg ? get_post_meta( $task_id, SRT_Site_Scanner::META_KEY, true ) : false;
		$tag        = srt_get_task_tag( $task_id );
		$logo_url   = SRT_PLUGIN_URL . 'assets/rosewood-logo.png';

		// Filename the browser suggests when saving as PDF is taken from <title>:
		// {Y-m-d}-{Company-Name}-Website-Health-Security-Report(.pdf)
		$date_for_name = $period ? $period : current_time( 'Y-m-d' );
		$company_slug  = trim( preg_replace( '/[^A-Za-z0-9]+/', '-', get_the_title( $company_id ) ), '-' );
		$pdf_filename  = $date_for_name . '-' . $company_slug . '-Website-Health-Security-Report';

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>" />
			<meta name="viewport" content="width=device-width, initial-scale=1" />
			<title><?php echo esc_html( $pdf_filename ); ?></title>
			<link rel="preconnect" href="https://fonts.googleapis.com" />
			<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
			<link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet" />
			<style>
				/* Rosewood Marketing "Website Health & Security Report" styling */
				:root {
					--rw-maroon: #a82e3d;
					--rw-gold:   #ad9661;
					--rw-ink:    #291f1c;
					--rw-cream:  #f2ede3;
					--rw-muted:  #6f6660;
				}
				* { box-sizing: border-box; }
				html, body { margin: 0; padding: 0; background: #fff; }
				body {
					font-family: "Libre Baskerville", Georgia, "Times New Roman", serif;
					color: var(--rw-ink);
					font-size: 11pt;
					line-height: 1.55;
					-webkit-print-color-adjust: exact;
					print-color-adjust: exact;
				}
				.rw-page {
					max-width: 8.5in;
					margin: 0 auto;
					padding: 0.9in 0.95in 1.9in;
				}
				.rw-title {
					font-family: "Open Sans", Arial, Helvetica, sans-serif;
					font-weight: 700;
					font-size: 30pt;
					line-height: 1.08;
					color: var(--rw-maroon);
					margin: 0 0 0.7rem;
				}
				.rw-meta { margin: 0 0 2.6rem; }
				.rw-meta p { margin: 0 0 0.35rem; }
				.rw-meta .rw-label { font-weight: 700; }
				.rw-section { margin: 0 0 2rem; }
				.rw-heading {
					font-family: "Open Sans", Arial, Helvetica, sans-serif;
					font-weight: 700;
					font-size: 15pt;
					color: var(--rw-gold);
					margin: 0 0 0.55rem;
				}
				.rw-answer { margin: 0; }

				/* Bulleted checklist — mirrors the report's bold lead-in list items */
				.rw-list {
					margin: 0;
					padding-left: 1.35rem;
				}
				.rw-list > li {
					margin: 0 0 0.5rem;
					padding-left: 0.2rem;
				}
				.rw-item-label { font-weight: 700; }
				.rw-item-text { display: block; margin-top: 0.15rem; }

				.rw-gallery { margin-top: 0.35rem; }
				.rw-gallery img {
					max-width: 150px;
					margin: 0 0.4rem 0.4rem 0;
					border: 1px solid #d8cfc2;
					border-radius: 3px;
					vertical-align: top;
				}
				.rw-notes {
					margin-top: 2rem;
					padding-top: 1.2rem;
					border-top: 2px solid var(--rw-maroon);
				}
				.rw-notes .rw-heading { color: var(--rw-maroon); }

				/* Site Scan Results (MG report) — collapsed sections mirroring the dashboard */
				.rw-scan .srt-scan-results { display: flex; flex-direction: column; gap: 0.9rem; margin-top: 0.4rem; }
				.rw-scan .srt-scan-page { border: 1px solid #d8cfc2; border-radius: 6px; overflow: hidden; }
				.rw-scan .srt-scan-page-title {
					margin: 0; padding: 0.5rem 0.7rem;
					font-family: "Open Sans", Arial, Helvetica, sans-serif;
					font-size: 9.5pt; font-weight: 700;
					background: var(--rw-cream); border-bottom: 1px solid #d8cfc2;
				}
				.rw-scan .srt-scan-page-title a { color: inherit; text-decoration: none; }
				.rw-scan .srt-scan-page-url { font-weight: 400; word-break: break-all; color: var(--rw-muted); }
				.rw-scan .srt-scan-section { border-top: 1px solid #e8ddcd; }
				.rw-scan .srt-scan-section:first-of-type { border-top: none; }
				.rw-scan .srt-scan-summary {
					display: flex; align-items: center; gap: 0.6rem;
					padding: 0.45rem 0.7rem;
					font-family: "Open Sans", Arial, Helvetica, sans-serif;
					list-style: none; cursor: default;
				}
				.rw-scan .srt-scan-summary::-webkit-details-marker { display: none; }
				.rw-scan .srt-scan-summary::before {
					content: '\25B6'; font-size: 0.55rem; color: #b3a894; flex-shrink: 0;
				}
				.rw-scan details[open] > .srt-scan-summary::before { content: '\25BC'; }
				.rw-scan .srt-scan-label { font-weight: 700; font-size: 9.5pt; }
				.rw-scan .srt-scan-badge {
					font-family: "Open Sans", Arial, Helvetica, sans-serif;
					font-size: 8.5pt; font-weight: 600;
					padding: 0.1rem 0.55rem; border-radius: 999px; white-space: nowrap;
				}
				.rw-scan .srt-scan-badge-ok { background: #d4edda; color: #1e7e34; }
				.rw-scan .srt-scan-badge-warn { background: #fff3cd; color: #8a6500; }
				.rw-scan .srt-scan-badge-error { background: #f8d7da; color: #a71d2a; }
				.rw-scan .srt-scan-items { list-style: none; margin: 0; padding: 0; }
				.rw-scan .srt-scan-item {
					display: flex; flex-direction: column; gap: 0.1rem;
					padding: 0.4rem 0.7rem 0.4rem 1.1rem;
					border-top: 1px solid #efe8db; border-left: 3px solid transparent;
					font-size: 9pt; line-height: 1.4;
				}
				.rw-scan .srt-scan-item-error { border-left-color: #dc3545; }
				.rw-scan .srt-scan-item-warn { border-left-color: #ffc107; }
				.rw-scan .srt-scan-status {
					font-family: "Open Sans", Arial, Helvetica, sans-serif;
					font-weight: 700; font-size: 8pt; color: var(--rw-muted);
					text-transform: uppercase; letter-spacing: 0.02em;
				}
				.rw-scan .srt-scan-msg { color: var(--rw-ink); }
				.rw-scan .srt-scan-msg a, .rw-scan .srt-scan-url { color: var(--rw-muted); word-break: break-all; }
				.rw-scan .srt-scan-extract {
					display: block; margin-top: 0.15rem; padding: 0.2rem 0.35rem;
					background: #f3efe7; border-radius: 3px; font-size: 8pt;
					white-space: pre-wrap; word-break: break-all; color: var(--rw-muted);
				}
				.rw-scan .srt-scan-skip-note { padding: 0.4rem 0.7rem; margin: 0; font-size: 9pt; color: var(--rw-muted); }

				/* Footer band — mirrors the report: cream bar, maroon rule, logo card */
				.rw-footer {
					position: fixed;
					left: 0; right: 0; bottom: 0;
					height: 1.15in;
				}
				.rw-footer-bar {
					position: absolute;
					left: 0; right: 0; bottom: 0.12in;
					height: 0.62in;
					background: var(--rw-cream);
					display: flex;
					align-items: center;
					gap: 2.2rem;
					padding: 0 0.55in 0 0.4in;
					font-family: "Open Sans", Arial, Helvetica, sans-serif;
					font-size: 9pt;
					letter-spacing: 0.02em;
					color: var(--rw-muted);
				}
				.rw-footer-page {
					width: 0.55in;
					text-align: center;
					color: var(--rw-ink);
				}
				.rw-footer-rule {
					position: absolute;
					left: 0; right: 0; bottom: 0;
					height: 0.12in;
					background: var(--rw-maroon);
				}
				.rw-footer-logo {
					position: absolute;
					right: 0.6in;
					bottom: 0.06in;
					width: 1.05in;
					height: auto;
					background: #fff;
					border-radius: 8px;
					padding: 6px 10px;
					box-shadow: 0 2px 6px rgba(0, 0, 0, 0.18);
				}
				.rw-actions {
					position: fixed;
					top: 0.5in;
					right: 0.6in;
				}
				.rw-actions button {
					font-family: "Open Sans", Arial, Helvetica, sans-serif;
					font-size: 0.95rem;
					font-weight: 600;
					padding: 0.55rem 1.1rem;
					color: #fff;
					background: var(--rw-maroon);
					border: 0;
					border-radius: 5px;
					cursor: pointer;
				}
				.rw-actions button:hover { background: #8f2634; }

				@media print {
					@page { margin: 0; }
					.rw-actions { display: none; }
					.rw-page { max-width: none; }
				}
			</style>
		</head>
		<body>
			<div class="rw-actions">
				<button onclick="window.print()" type="button"><?php esc_html_e( 'Print / Save as PDF', 'rw-site-review-tasks' ); ?></button>
			</div>

			<div class="rw-page">
				<h1 class="rw-title"><?php echo esc_html( get_the_title( $company_id ) ); ?></h1>

				<div class="rw-meta">
					<p><span class="rw-label"><?php esc_html_e( 'Report Period:', 'rw-site-review-tasks' ); ?></span> <?php echo esc_html( $period ); ?></p>
					<p><span class="rw-label"><?php esc_html_e( 'Status:', 'rw-site-review-tasks' ); ?></span> <?php echo esc_html( $tag ); ?></p>
					<p><?php echo wp_kses_post( srt_format_people_line( $task_id ) ); ?></p>
				</div>

				<?php if ( '' !== trim( (string) $summary ) ) : ?>
					<div class="rw-section">
						<h2 class="rw-heading"><?php esc_html_e( 'Executive Summary', 'rw-site-review-tasks' ); ?></h2>
						<div class="rw-answer"><?php echo nl2br( esc_html( $summary ) ); ?></div>
					</div>
				<?php endif; ?>

				<?php if ( is_array( $rows ) && ! empty( $rows ) ) : ?>
					<?php foreach ( self::group_rows_by_section( $rows ) as $group ) : ?>
						<div class="rw-section">
							<?php if ( '' !== $group['section'] ) : ?>
								<h2 class="rw-heading"><?php echo esc_html( $group['section'] ); ?></h2>
							<?php endif; ?>
							<ul class="rw-list">
								<?php foreach ( $group['rows'] as $row ) : ?>
									<li>
										<span class="rw-item-label"><?php echo esc_html( $row['label'] ); ?>:</span>
										<?php echo self::render_answer( $row ); ?>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
					<p><?php esc_html_e( 'This task has no checklist items.', 'rw-site-review-tasks' ); ?></p>
				<?php endif; ?>

				<?php if ( $is_mg && $scan ) : ?>
					<div class="rw-section rw-scan">
						<h2 class="rw-heading"><?php esc_html_e( 'Site Scan Results', 'rw-site-review-tasks' ); ?></h2>
						<?php echo SRT_Site_Scanner::render_results_html( $scan, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php endif; ?>

				<?php if ( $is_mg ) : ?>
					<div class="rw-section rw-notes">
						<h2 class="rw-heading"><?php esc_html_e( 'Extra Notes', 'rw-site-review-tasks' ); ?></h2>
						<div class="rw-answer"><?php echo '' !== $notes ? nl2br( esc_html( $notes ) ) : '&#8212;'; ?></div>
					</div>
				<?php endif; ?>
			</div>

			<div class="rw-footer" aria-hidden="true">
				<div class="rw-footer-bar">
					<span class="rw-footer-page">1</span>
					<span>email@rosewood.us.com</span>
					<span>rosewood.us.com</span>
					<span>717.866.5000</span>
				</div>
				<div class="rw-footer-rule"></div>
				<img class="rw-footer-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="Rosewood Marketing" />
			</div>
		</body>
		</html>
		<?php
	}

	/**
	 * Groups checklist rows into sequential sections. A non-empty Section title
	 * starts a new group; rows with a blank Section continue the current group,
	 * so a section's items and its free-text summaries stay together in document
	 * order. Leading blank-section rows form a headingless group.
	 *
	 * @return array<int, array{section:string,rows:array}>
	 */
	private static function group_rows_by_section( $rows ) {
		$groups          = array();
		$current_section = null;

		foreach ( $rows as $row ) {
			$section = isset( $row['section'] ) ? trim( (string) $row['section'] ) : '';

			if ( '' !== $section && $section !== $current_section ) {
				$groups[]        = array( 'section' => $section, 'rows' => array() );
				$current_section = $section;
			} elseif ( empty( $groups ) ) {
				$groups[]        = array( 'section' => '', 'rows' => array() );
				$current_section = '';
			}

			$groups[ count( $groups ) - 1 ]['rows'][] = $row;
		}

		return $groups;
	}

	private static function render_answer( $row ) {
		switch ( $row['field_type'] ) {
			case 'checkbox':
				$selected = srt_lines_to_array( $row['answer'] );
				return $selected ? esc_html( implode( ', ', $selected ) ) : '&#8212;';

			case 'textarea':
				return '' !== $row['answer'] ? nl2br( esc_html( $row['answer'] ) ) : '&#8212;';

			case 'gallery':
				$ids = array_filter( array_map( 'absint', explode( ',', (string) $row['answer'] ) ) );

				if ( ! $ids ) {
					return '&#8212;';
				}

				$html = '<div class="rw-gallery">';
				foreach ( $ids as $attachment_id ) {
					$html .= wp_get_attachment_image( $attachment_id, 'medium' );
				}
				$html .= '</div>';
				return $html;

			default: // radio
				return '' !== $row['answer'] ? esc_html( $row['answer'] ) : '&#8212;';
		}
	}
}

SRT_Task_Print::init();
