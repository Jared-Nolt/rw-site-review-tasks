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

		// Authenticate before revealing anything about the ID. Checking the post
		// type first would let an anonymous visitor tell a review task ("please log
		// in") apart from any other post ID ("not found") and enumerate them.
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		if ( SRT_CPT_Task::POST_TYPE !== get_post_type( $task_id ) ) {
			wp_die( esc_html__( 'Task not found.', 'rw-site-review-tasks' ), '', array( 'response' => 404 ) );
		}

		if ( ! srt_user_can_access_task( $task_id ) ) {
			wp_die( esc_html__( 'You do not have access to this task.', 'rw-site-review-tasks' ), '', array( 'response' => 403 ) );
		}

		self::render( $task_id, $is_mg );
		exit;
	}

	private static function render( $task_id, $is_mg ) {
		$company_id = get_field( 'company', $task_id );
		$period     = (string) get_field( 'period', $task_id );
		$summary    = (string) get_field( 'executive_summary', $task_id );
		$rows       = srt_get_checklist( $task_id );
		$notes      = (string) get_field( 'extra_notes', $task_id );
		$scan       = $is_mg ? get_post_meta( $task_id, SRT_Site_Scanner::META_KEY, true ) : false;
		$tag        = srt_get_task_tag( $task_id );
		$logo_url   = SRT_PLUGIN_URL . 'assets/rosewood-logo.png';
		$task_url   = $is_mg ? srt_task_page_url( $task_id ) : '';

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
				/*
				 * Page-break plumbing. A position: fixed footer repeats on every
				 * printed page but reserves no space in the flow, and Chrome clips
				 * it to the page area so it can't be pushed into an @page margin.
				 * Text therefore ran underneath the footer band at every page
				 * break. The fix splits the two jobs: an invisible <tfoot> spacer
				 * reserves the band on every page — table footers repeat per page
				 * fragment and do occupy flow space — while .rw-footer keeps
				 * painting the visible band at the bottom of each page, including
				 * the last one, where a real <tfoot> would float up under the
				 * content instead.
				 */
				.rw-flow {
					width: 100%;
					border-collapse: collapse;
				}
				.rw-flow > tbody > tr > td,
				.rw-flow > tfoot > tr > td {
					padding: 0;
					border: 0;
					vertical-align: top;
				}
				.rw-flow-spacer { height: 1.15in; } /* must match .rw-footer height */

				.rw-page {
					max-width: 8.5in;
					margin: 0 auto;
					/*
					 * Top padding is the first page's extra offset only; @page
					 * margin-top supplies the 0.55in that every page gets, so page
					 * one totals the original 0.9in. Bottom padding is gone — the
					 * <tfoot> spacer reserves that space now, on every page rather
					 * than just after the final line.
					 */
					padding: 0.35in 0.95in 0;
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

				/* Back-link to the front-end task page (MG report only) */
				.rw-task-link { margin-top: 0.7rem; }
				.rw-task-link a {
					font-family: "Open Sans", Arial, Helvetica, sans-serif;
					font-weight: 600;
					font-size: 10pt;
					color: var(--rw-maroon);
				}
				.rw-task-link-url {
					display: block;
					font-family: "Open Sans", Arial, Helvetica, sans-serif;
					font-size: 8.5pt;
					color: var(--rw-muted);
					word-break: break-all;
				}

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

				/* Subsection headings inside Kinsta Data: Security Overview / Backups /
				   Bot Protection (Bot Protection MG PDF only) */
				.rw-subheading {
					font-family: "Open Sans", Arial, Helvetica, sans-serif;
					font-weight: 700;
					font-size: 11pt;
					color: var(--rw-gold);
					margin: 1rem 0 0.35rem;
				}

				/* GTmetrix Page Load Speed comparison — vertical rows, previous/current columns */
				.rw-gtmetrix-table {
					margin-top: 0.4rem;
					border-collapse: collapse;
					font-family: "Open Sans", Arial, Helvetica, sans-serif;
					font-size: 9.5pt;
				}
				.rw-gtmetrix-table th,
				.rw-gtmetrix-table td {
					padding: 0.25rem 0.9rem 0.25rem 0;
					text-align: left;
				}
				.rw-gtmetrix-table thead th { color: var(--rw-muted); font-weight: 700; }
				.rw-gtmetrix-table .rw-gtmetrix-dates th { font-weight: 400; font-size: 8.5pt; color: var(--rw-muted); }
				.rw-gtmetrix-table tbody td:first-child { font-weight: 700; }

				.rw-gallery { margin-top: 0.35rem; }
				.rw-gallery img {
					margin: 0 0.4rem 0.4rem 0;
					border: 1px solid #d8cfc2;
					border-radius: 3px;
					vertical-align: top;
					height: auto;
					max-width: 550px;
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

				@media screen {
					/* Keep the on-screen preview's original top offset, since
					   @page margins only apply when printing. */
					.rw-page { padding-top: 0.9in; }
				}

				@media print {
					/* Top margin repeats on every page, so continuation pages no
					   longer start hard against the sheet edge. Sides and bottom
					   stay at zero to keep the footer band full-bleed. */
					@page { margin: 0.55in 0 0; }
					.rw-actions { display: none; }
					.rw-page { max-width: none; }
				}
			</style>
		</head>
		<body>
			<div class="rw-actions">
				<button onclick="window.print()" type="button"><?php esc_html_e( 'Print / Save as PDF', 'rw-site-review-tasks' ); ?></button>
			</div>

			<?php /* See .rw-flow in the stylesheet: the tfoot reserves the footer band on every page. */ ?>
			<table class="rw-flow" role="presentation">
				<tfoot>
					<tr><td class="rw-flow-spacer"></td></tr>
				</tfoot>
				<tbody>
				<tr><td>

			<div class="rw-page">
				<h1 class="rw-title"><?php echo esc_html( get_the_title( $company_id ) ); ?></h1>

				<div class="rw-meta">
					<p><span class="rw-label"><?php esc_html_e( 'Report Period:', 'rw-site-review-tasks' ); ?></span> <?php echo esc_html( $period ); ?></p>
					<p><span class="rw-label"><?php esc_html_e( 'Status:', 'rw-site-review-tasks' ); ?></span> <?php echo esc_html( $tag ); ?></p>
					<p><?php echo wp_kses_post( srt_format_people_line( $task_id ) ); ?></p>
					<?php if ( $task_url ) : ?>
						<p class="rw-task-link">
							<a href="<?php echo esc_url( $task_url ); ?>"><?php esc_html_e( 'View this task online', 'rw-site-review-tasks' ); ?></a>
							<span class="rw-task-link-url"><?php echo esc_html( $task_url ); ?></span>
						</p>
					<?php endif; ?>
				</div>

				<?php if ( '' !== trim( (string) $summary ) ) : ?>
					<div class="rw-section">
						<h2 class="rw-heading"><?php esc_html_e( 'Executive Summary', 'rw-site-review-tasks' ); ?></h2>
						<div class="rw-answer"><?php echo nl2br( esc_html( $summary ) ); ?></div>
					</div>
				<?php endif; ?>

				<?php
				$visible_groups = array();
				if ( is_array( $rows ) && ! empty( $rows ) ) {
					foreach ( self::group_rows_by_section( $rows ) as $group ) {
						$visible_rows = array_values( array_filter( $group['rows'], array( __CLASS__, 'row_has_answer' ) ) );
						if ( $visible_rows ) {
							$visible_groups[] = array( 'section' => $group['section'], 'rows' => $visible_rows );
						}
					}
				}
				?>

				<?php if ( $visible_groups ) : ?>
					<?php foreach ( $visible_groups as $group ) : ?>
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

				<?php
				// Kinsta Data and Page Load Speed are independent of the checklist —
				// they're fetched data, not something contingent on a matching
				// checklist item existing and being answered — so they're always
				// considered here, on both the Client and MG PDF.
				echo self::render_kinsta_section_html( $task_id, $is_mg ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::render_gtmetrix_section_html( $task_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>

				<?php if ( $is_mg && $scan ) : ?>
					<div class="rw-section rw-scan">
						<h2 class="rw-heading"><?php esc_html_e( 'Site Scan Results', 'rw-site-review-tasks' ); ?></h2>
						<?php echo SRT_Site_Scanner::render_results_html( $scan, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php endif; ?>

				<?php if ( $is_mg ) : ?>
					<div class="rw-section rw-notes">
						<h2 class="rw-heading"><?php esc_html_e( 'Extra Notes for MG', 'rw-site-review-tasks' ); ?></h2>
						<div class="rw-answer"><?php echo '' !== $notes ? nl2br( esc_html( $notes ) ) : '&#8212;'; ?></div>
					</div>
				<?php endif; ?>
			</div>

				</td></tr>
				</tbody>
			</table>

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
	 * "Kinsta Data" section — Security Overview, Backups, and (Marketing Guide
	 * PDF only) Bot Protection, each with its notes field. Independent of the
	 * checklist entirely, unlike the earlier design where this only appeared
	 * beside a matching checklist item — a task with no checklist items (or a
	 * checklist that didn't happen to include those labels) showed nothing at
	 * all. Shown on both the Client and MG PDF; each subsection (and the
	 * section as a whole) is hidden when there's nothing to show.
	 */
	private static function render_kinsta_section_html( $task_id, $is_mg ) {
		$results  = SRT_Kinsta::get_results( $task_id );
		$security = isset( $results['security'] ) ? $results['security'] : array();
		$backups  = isset( $results['backups'] ) ? $results['backups'] : array();

		$security_html  = ( ! empty( $security ) && ! isset( $security['error'] ) ) ? SRT_Kinsta::render_security_overview_html( 0, $security ) : '';
		$security_notes = trim( (string) get_field( 'kinsta_security_notes', $task_id ) );

		$backups_html  = SRT_Kinsta::render_backups_html( $task_id, $backups );
		$backups_notes = trim( (string) get_field( 'kinsta_backups_notes', $task_id ) );

		$bot_html  = $is_mg ? SRT_Kinsta::render_bot_protection_html( $task_id ) : '';
		$bot_notes = $is_mg ? trim( (string) get_field( 'kinsta_bot_protection_notes', $task_id ) ) : '';

		$has_security = '' !== $security_html || '' !== $security_notes;
		$has_backups  = '' !== $backups_html || '' !== $backups_notes;
		$has_bot      = '' !== $bot_html || '' !== $bot_notes;

		if ( ! $has_security && ! $has_backups && ! $has_bot ) {
			return '';
		}

		ob_start();
		?>
		<div class="rw-section">
			<h2 class="rw-heading"><?php esc_html_e( 'Server Data', 'rw-site-review-tasks' ); ?></h2>

			<?php if ( $has_security ) : ?>
				<h3 class="rw-subheading"><?php esc_html_e( 'Security Overview', 'rw-site-review-tasks' ); ?></h3>
				<?php echo $security_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php if ( '' !== $security_notes ) : ?>
					<div class="rw-answer"><?php echo nl2br( esc_html( $security_notes ) ); ?></div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $has_backups ) : ?>
				<h3 class="rw-subheading"><?php esc_html_e( 'Backups', 'rw-site-review-tasks' ); ?></h3>
				<?php echo $backups_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php if ( '' !== $backups_notes ) : ?>
					<div class="rw-answer"><?php echo nl2br( esc_html( $backups_notes ) ); ?></div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $has_bot ) : ?>
				<h3 class="rw-subheading"><?php esc_html_e( 'Bot Protection', 'rw-site-review-tasks' ); ?></h3>
				<?php echo $bot_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php if ( '' !== $bot_notes ) : ?>
					<div class="rw-answer"><?php echo nl2br( esc_html( $bot_notes ) ); ?></div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * "Page Load Speed (GTmetrix)" section — the Previous/Current comparison
	 * table, independent of the checklist. Shown on both the Client and MG
	 * PDF; hidden entirely when there's no completed run yet.
	 */
	private static function render_gtmetrix_section_html( $task_id ) {
		$table = SRT_GTmetrix::render_table_html( null, $task_id, true );

		if ( '' === $table ) {
			return '';
		}

		ob_start();
		?>
		<div class="rw-section">
			<h2 class="rw-heading"><?php esc_html_e( 'Page Load Speed', 'rw-site-review-tasks' ); ?></h2>
			<?php echo $table; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
		return ob_get_clean();
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

	/**
	 * Whether a checklist row has anything worth printing. Unanswered items
	 * are hidden from the PDF entirely (not shown with a blank/placeholder
	 * answer) — a section whose every item ends up hidden this way is itself
	 * dropped by the caller so no empty heading is left behind.
	 */
	private static function row_has_answer( $row ) {
		// Some rows on older tasks can be missing the 'answer' key entirely
		// (e.g. a row that never had a value stored) — treat that the same
		// as an empty answer rather than throwing an undefined-key notice.
		$answer = isset( $row['answer'] ) ? $row['answer'] : '';

		return '' !== trim( (string) $answer );
	}

	private static function render_answer( $row ) {
		$answer = isset( $row['answer'] ) ? $row['answer'] : '';

		return '' !== $answer ? nl2br( esc_html( $answer ) ) : '&#8212;';
	}
}

SRT_Task_Print::init();
