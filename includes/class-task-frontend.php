<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRT_Task_Frontend {

	private static $task_id      = 0;
	private static $access_error = '';

	public static function init() {
		add_shortcode( 'srt_task_view', array( __CLASS__, 'render' ) );
		add_action( 'wp', array( __CLASS__, 'maybe_init_form' ) );
	}

	public static function maybe_init_form() {
		global $post;

		if ( empty( $post ) || ! has_shortcode( $post->post_content, 'srt_task_view' ) ) {
			return;
		}

		// Read from $_REQUEST (not just $_GET): the form posts task_id as a hidden
		// field so it survives the round trip regardless of the form's action URL.
		$task_id = isset( $_REQUEST['task_id'] ) ? absint( $_REQUEST['task_id'] ) : 0;
		self::$task_id = $task_id;

		if ( ! $task_id ) {
			self::$access_error = 'invalid';
			return;
		}

		// Authenticate before the post-type check, so an anonymous visitor can't
		// tell a real review task ("please log in") from any other post ID ("task
		// not found") and enumerate them.
		if ( ! is_user_logged_in() ) {
			self::$access_error = 'login';
			return;
		}

		if ( SRT_CPT_Task::POST_TYPE !== get_post_type( $task_id ) ) {
			self::$access_error = 'invalid';
			return;
		}

		if ( ! srt_user_can_access_task( $task_id ) ) {
			self::$access_error = 'denied';
			return;
		}

		self::maybe_save( $task_id, get_permalink( $post ) );
	}

	private static function maybe_save( $task_id, $current_url ) {
		if ( ! isset( $_POST['srt_save_task'] ) ) {
			return;
		}

		$nonce = isset( $_POST['srt_task_nonce'] ) ? wp_unslash( $_POST['srt_task_nonce'] ) : '';

		if ( ! wp_verify_nonce( $nonce, 'srt_task_' . $task_id ) ) {
			return;
		}

		$rows   = srt_get_checklist( $task_id );
		$posted = isset( $_POST['srt_checklist'] ) ? (array) $_POST['srt_checklist'] : array();

		if ( $rows ) {
			foreach ( $rows as $i => &$row ) {
				$value         = isset( $posted[ $i ]['answer'] ) ? wp_unslash( $posted[ $i ]['answer'] ) : '';
				$row['answer'] = sanitize_textarea_field( $value );
			}
			unset( $row );

			srt_update_checklist( $task_id, $rows );
		}

		if ( isset( $_POST['srt_executive_summary'] ) ) {
			update_field( 'executive_summary', sanitize_textarea_field( wp_unslash( $_POST['srt_executive_summary'] ) ), $task_id );
		}

		if ( isset( $_POST['srt_extra_notes'] ) ) {
			update_field( 'extra_notes', sanitize_textarea_field( wp_unslash( $_POST['srt_extra_notes'] ) ), $task_id );
		}

		if ( isset( $_POST['srt_kinsta_security_notes'] ) ) {
			update_field( 'kinsta_security_notes', sanitize_textarea_field( wp_unslash( $_POST['srt_kinsta_security_notes'] ) ), $task_id );
		}

		if ( isset( $_POST['srt_kinsta_backups_notes'] ) ) {
			update_field( 'kinsta_backups_notes', sanitize_textarea_field( wp_unslash( $_POST['srt_kinsta_backups_notes'] ) ), $task_id );
		}

		if ( isset( $_POST['srt_kinsta_bot_protection_notes'] ) ) {
			update_field( 'kinsta_bot_protection_notes', sanitize_textarea_field( wp_unslash( $_POST['srt_kinsta_bot_protection_notes'] ) ), $task_id );
		}

		if ( isset( $_POST['srt_status'] ) ) {
			$old_status = get_field( 'status', $task_id );
			$status     = wp_unslash( $_POST['srt_status'] );

			if ( in_array( $status, array( 'completed', 'needs_work' ), true ) ) {
				update_field( 'status', $status, $task_id );

				if ( $status !== $old_status ) {
					SRT_Cron::notify_status_change( $task_id, $status );

					/**
					 * Fires whenever a task's status is saved as Completed or Needs
					 * Work. Hook this to sync the result to another platform (a
					 * webhook, CRM, Slack, etc.) without touching this plugin.
					 *
					 * @param int    $task_id    The task post ID.
					 * @param string $status     New status: 'completed' or 'needs_work'.
					 * @param mixed  $old_status Previous status value (may be '' or false).
					 */
					do_action( 'srt_task_status_changed', $task_id, $status, $old_status );
				}
			}
		}

		$dashboard_id = (int) get_option( 'srt_dashboard_page_id' );
		$redirect_url = $dashboard_id ? get_permalink( $dashboard_id ) : add_query_arg( 'task_id', $task_id, $current_url );

		wp_safe_redirect( $redirect_url );
		exit;
	}

	public static function render() {
		if ( 'login' === self::$access_error ) {
			return sprintf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html__( 'Please log in to view this task.', 'rw-site-review-tasks' ),
				esc_url( wp_login_url( get_permalink() ) ),
				esc_html__( 'Log in', 'rw-site-review-tasks' )
			);
		}

		if ( 'denied' === self::$access_error ) {
			return '<p>' . esc_html__( 'You do not have access to this task.', 'rw-site-review-tasks' ) . '</p>';
		}

		if ( 'invalid' === self::$access_error || ! self::$task_id ) {
			return '<p>' . esc_html__( 'Task not found.', 'rw-site-review-tasks' ) . '</p>';
		}

		wp_enqueue_style( 'srt-dashboard' );

		$task_id    = self::$task_id;
		$company_id = get_field( 'company', $task_id );
		$period     = (string) get_field( 'period', $task_id );
		$status     = get_field( 'status', $task_id );
		$summary    = (string) get_field( 'executive_summary', $task_id );
		$rows       = srt_get_checklist( $task_id );
		$notes      = (string) get_field( 'extra_notes', $task_id );

		ob_start();
		?>
		<div class="srt-task-view">
			<p class="srt-task-meta">
				<strong><?php echo esc_html( get_the_title( $company_id ) ); ?></strong>
				&mdash; <?php echo esc_html( $period ); ?>
				<br />
				<?php echo wp_kses_post( srt_format_people_line( $task_id ) ); ?>
			</p>

			<?php echo self::render_notices(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<form method="post" class="srt-checklist-form">
				<?php wp_nonce_field( 'srt_task_' . $task_id, 'srt_task_nonce' ); ?>
				<input type="hidden" name="task_id" value="<?php echo esc_attr( $task_id ); ?>" />

				<?php
				$scan_results = get_post_meta( $task_id, SRT_Site_Scanner::META_KEY, true );
				if ( $scan_results ) :
					?>
					<div class="srt-scan-wrapper">
						<h3 class="srt-group-title"><?php esc_html_e( 'Site Scan Results', 'rw-site-review-tasks' ); ?></h3>
						<p class="srt-scan-meta">
							<?php
							printf(
								/* translators: %s: timestamp */
								esc_html__( 'Last scanned: %s', 'rw-site-review-tasks' ),
								esc_html( $scan_results['scanned_at'] )
							);
							?>
						</p>
						<?php echo SRT_Site_Scanner::render_results_html( $scan_results, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<p><a href="<?php echo esc_url( SRT_Site_Scanner::rescan_url( $task_id, srt_task_page_url( $task_id ) ) ); ?>" class="button srt-refresh-link"><?php esc_html_e( 'Re-scan now', 'rw-site-review-tasks' ); ?></a></p>
					</div>
				<?php endif; ?>

				<?php echo self::render_kinsta_section( $task_id, $company_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php echo self::render_gtmetrix_section( $task_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<div class="srt-checklist-item srt-task-summary">
					<label>
						<strong><?php esc_html_e( 'Executive Summary', 'rw-site-review-tasks' ); ?></strong><br />
						<textarea name="srt_executive_summary" rows="4" class="srt-checklist-textarea"><?php echo esc_textarea( $summary ); ?></textarea>
					</label>
				</div>

				<?php if ( is_array( $rows ) && ! empty( $rows ) ) : ?>
					<?php $current_section = null; ?>
					<?php foreach ( $rows as $i => $row ) : ?>
						<?php
						$section = isset( $row['section'] ) ? trim( (string) $row['section'] ) : '';
						if ( '' !== $section && $section !== $current_section ) :
							$current_section = $section;
							?>
							<h3 class="srt-group-title"><?php echo esc_html( $section ); ?></h3>
							<?php
						endif;
						?>
						<div class="srt-checklist-item">
							<?php echo self::render_item( $i, $row, $task_id ); ?>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
				<?php endif; ?>

				<div class="srt-checklist-item srt-task-notes">
					<label>
						<strong><?php esc_html_e( 'Extra Notes for MG', 'rw-site-review-tasks' ); ?></strong><br />
						<textarea name="srt_extra_notes" rows="4" class="srt-checklist-textarea"><?php echo esc_textarea( $notes ); ?></textarea>
					</label>
				</div>

				<div class="srt-checklist-item srt-task-status">
					<fieldset>
						<legend><?php esc_html_e( 'Status', 'rw-site-review-tasks' ); ?></legend>
						<label class="srt-checklist-choice">
							<input type="radio" name="srt_status" value="completed" <?php checked( $status, 'completed' ); ?> />
							<?php esc_html_e( 'Completed', 'rw-site-review-tasks' ); ?>
						</label>
						<label class="srt-checklist-choice">
							<input type="radio" name="srt_status" value="needs_work" <?php checked( $status, 'needs_work' ); ?> />
							<?php esc_html_e( 'Save Draft', 'rw-site-review-tasks' ); ?>
						</label>
					</fieldset>
				</div>

				<button type="submit" name="srt_save_task" value="1" class="srt-checklist-submit">
					<?php esc_html_e( 'Save', 'rw-site-review-tasks' ); ?>
				</button>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_item( $i, $row, $task_id = 0 ) {
		ob_start();

		$answer = isset( $row['answer'] ) ? $row['answer'] : '';
		?>
		<label>
			<?php echo esc_html( $row['label'] ); ?><br />
			<textarea name="srt_checklist[<?php echo esc_attr( $i ); ?>][answer]" rows="3" class="srt-checklist-textarea"><?php echo esc_textarea( $answer ); ?></textarea>
		</label>
		<?php
		return ob_get_clean();
	}

	/**
	 * Success notices for the rescan/refresh links below — mirrors the
	 * wp-admin notices in class-site-scanner.php / class-kinsta.php /
	 * class-gtmetrix.php, which never reach this page since it isn't in
	 * wp-admin.
	 */
	private static function render_notices() {
		$messages = array();

		if ( isset( $_GET['srt_notice'] ) && 'rescanned' === $_GET['srt_notice'] ) {
			$messages[] = __( 'Site scan complete. Results updated below.', 'rw-site-review-tasks' );
		}

		if ( ! empty( $_GET['srt_kinsta'] ) ) {
			$map = array(
				'security_refreshed' => __( 'Security Overview refreshed.', 'rw-site-review-tasks' ),
				'backups_refreshed'  => __( 'Backups refreshed.', 'rw-site-review-tasks' ),
			);
			$key = sanitize_key( wp_unslash( $_GET['srt_kinsta'] ) );
			if ( isset( $map[ $key ] ) ) {
				$messages[] = $map[ $key ];
			}
		}

		if ( ! empty( $_GET['srt_gtmetrix'] ) && 'refresh_started' === $_GET['srt_gtmetrix'] ) {
			$messages[] = __( 'GTmetrix test started — results will appear here in about a minute.', 'rw-site-review-tasks' );
		}

		if ( ! $messages ) {
			return '';
		}

		$html = '';
		foreach ( $messages as $message ) {
			$html .= '<p class="srt-task-notice">' . esc_html( $message ) . '</p>';
		}
		return $html;
	}

	/**
	 * "Kinsta Data" section: Security Overview and Backups, each with a
	 * Refresh link and a notes textarea. Independent of the checklist —
	 * mirrors the admin meta box's states so a maker/marketing guide (who
	 * usually can't open wp-admin at all) can see and refresh the same data
	 * there. Bot Protection is intentionally not shown here — it's a manual,
	 * admin-only entry (see class-kinsta.php) that only ever appears on the
	 * Marketing Guide PDF.
	 */
	private static function render_kinsta_section( $task_id, $company_id ) {
		$site_id = $company_id ? trim( (string) get_field( 'kinsta_site_id', $company_id ) ) : '';

		ob_start();
		?>
		<div class="srt-scan-wrapper">
			<h3 class="srt-group-title"><?php esc_html_e( 'Server Data', 'rw-site-review-tasks' ); ?></h3>
			<?php if ( ! $site_id ) : ?>
				<p class="srt-scan-meta"><?php esc_html_e( 'No Kinsta Site ID set on the company — add one on the company edit screen to enable this.', 'rw-site-review-tasks' ); ?></p>
			<?php else : ?>
				<?php
				$results  = SRT_Kinsta::get_results( $task_id );
				$security = isset( $results['security'] ) ? $results['security'] : array();
				$backups  = isset( $results['backups'] ) ? $results['backups'] : array();
				$return   = srt_task_page_url( $task_id );
				?>
				<h4 class="srt-subheading"><?php esc_html_e( 'Security Overview', 'rw-site-review-tasks' ); ?></h4>
				<?php if ( empty( $security ) ) : ?>
					<p class="srt-scan-meta"><?php esc_html_e( 'Not fetched yet — runs automatically alongside the site scan.', 'rw-site-review-tasks' ); ?></p>
				<?php elseif ( isset( $security['error'] ) ) : ?>
					<p class="srt-scan-meta"><?php echo esc_html( $security['error'] ); ?></p>
				<?php else : ?>
					<?php echo SRT_Kinsta::render_security_overview_html( 0, $security ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
				<p><a href="<?php echo esc_url( SRT_Kinsta::security_refresh_url( $task_id, $return ) ); ?>" class="button srt-refresh-link"><?php esc_html_e( 'Refresh Security Overview', 'rw-site-review-tasks' ); ?></a></p>
				<div class="srt-checklist-item">
					<label>
						<strong><?php esc_html_e( 'Security Overview Notes', 'rw-site-review-tasks' ); ?></strong><br />
						<textarea name="srt_kinsta_security_notes" rows="3" class="srt-checklist-textarea"><?php echo esc_textarea( (string) get_field( 'kinsta_security_notes', $task_id ) ); ?></textarea>
					</label>
				</div>

				<h4 class="srt-subheading"><?php esc_html_e( 'Backups', 'rw-site-review-tasks' ); ?></h4>
				<?php if ( empty( $backups ) ) : ?>
					<p class="srt-scan-meta"><?php esc_html_e( 'Not fetched yet — runs automatically alongside the site scan.', 'rw-site-review-tasks' ); ?></p>
				<?php elseif ( isset( $backups['error'] ) ) : ?>
					<p class="srt-scan-meta"><?php echo esc_html( $backups['error'] ); ?></p>
				<?php else : ?>
					<p class="srt-scan-meta">
						<?php
						echo esc_html(
							! empty( $backups['last_backup'] )
								/* translators: %s: last backup date */
								? sprintf( __( 'Last backup: %s', 'rw-site-review-tasks' ), $backups['last_backup'] )
								: __( 'No backups found.', 'rw-site-review-tasks' )
						);
						?>
					</p>
				<?php endif; ?>
				<p><a href="<?php echo esc_url( SRT_Kinsta::backups_refresh_url( $task_id, $return ) ); ?>" class="button srt-refresh-link"><?php esc_html_e( 'Refresh Backups', 'rw-site-review-tasks' ); ?></a></p>
				<div class="srt-checklist-item">
					<label>
						<strong><?php esc_html_e( 'Backups Notes', 'rw-site-review-tasks' ); ?></strong><br />
						<textarea name="srt_kinsta_backups_notes" rows="3" class="srt-checklist-textarea"><?php echo esc_textarea( (string) get_field( 'kinsta_backups_notes', $task_id ) ); ?></textarea>
					</label>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * "Page Load Speed (GTmetrix)" section — mirrors the admin meta box's
	 * not-tested/pending/error/ready states, plus a Refresh link.
	 */
	private static function render_gtmetrix_section( $task_id ) {
		$results = SRT_GTmetrix::get_results( $task_id );

		ob_start();
		?>
		<div class="srt-scan-wrapper">
			<h3 class="srt-group-title"><?php esc_html_e( 'Page Load Speed', 'rw-site-review-tasks' ); ?></h3>
			<?php if ( empty( $results['pages'] ) ) : ?>
				<p class="srt-scan-meta"><?php esc_html_e( 'Not tested yet — runs automatically alongside the site scan.', 'rw-site-review-tasks' ); ?></p>
			<?php else : ?>
				<?php echo SRT_GTmetrix::render_table_html( $results ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( SRT_GTmetrix::refresh_url( $task_id, srt_task_page_url( $task_id ) ) ); ?>" class="button srt-refresh-link"><?php esc_html_e( 'Refresh Page Load Speed', 'rw-site-review-tasks' ); ?></a></p>
		</div>
		<?php
		return ob_get_clean();
	}
	/**END page load speed section**/
}

SRT_Task_Frontend::init();
