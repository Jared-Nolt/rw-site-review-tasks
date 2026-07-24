<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRT_Admin_Settings {

	const PAGE_SLUG = 'srt-settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
	}

	public static function add_menu() {
		add_submenu_page(
			'edit.php?post_type=' . SRT_CPT_Company::POST_TYPE,
			__( 'RW Site Review Tasks Settings', 'rw-site-review-tasks' ),
			__( 'Settings', 'rw-site-review-tasks' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_post() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['srt_save_settings'] ) && check_admin_referer( 'srt_settings', 'srt_settings_nonce' ) ) {
			$dashboard_page_id = isset( $_POST['srt_dashboard_page_id'] ) ? (int) $_POST['srt_dashboard_page_id'] : 0;
			update_option( 'srt_dashboard_page_id', $dashboard_page_id );

			$task_page_id = isset( $_POST['srt_task_page_id'] ) ? (int) $_POST['srt_task_page_id'] : 0;
			update_option( 'srt_task_page_id', $task_page_id );

			$restrict_to_maker = isset( $_POST['srt_restrict_to_maker'] ) ? 1 : 0;
			update_option( 'srt_restrict_to_maker', $restrict_to_maker );

			$cron_time = isset( $_POST['srt_cron_time'] ) ? sanitize_text_field( $_POST['srt_cron_time'] ) : '08:00';
			if ( ! preg_match( '/^\d{2}:\d{2}$/', $cron_time ) ) {
				$cron_time = '08:00';
			}
			update_option( 'srt_cron_time', $cron_time );
			SRT_Cron::reschedule();

			$wave_key = isset( $_POST['srt_wave_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['srt_wave_api_key'] ) ) : '';
			update_option( 'srt_wave_api_key', $wave_key );

			$psi_key = isset( $_POST['srt_psi_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['srt_psi_api_key'] ) ) : '';
			update_option( 'srt_psi_api_key', $psi_key );

			$log_retention = isset( $_POST['srt_log_retention_days'] ) ? max( 0, (int) $_POST['srt_log_retention_days'] ) : SRT_Cron::LOG_RETENTION_DEFAULT;
			update_option( 'srt_log_retention_days', $log_retention );

			wp_safe_redirect( self::page_url( 'saved' ) );
			exit;
		}
	}

	private static function page_url( $notice ) {
		return add_query_arg(
			array(
				'post_type'  => SRT_CPT_Company::POST_TYPE,
				'page'       => self::PAGE_SLUG,
				'srt_notice' => $notice,
			),
			admin_url( 'edit.php' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$next_run = wp_next_scheduled( SRT_Cron::HOOK );
		$log      = array_reverse( get_option( SRT_Cron::LOG_OPT, array() ) );
		$log      = array_slice( $log, 0, 50 );
		$notice   = isset( $_GET['srt_notice'] ) ? sanitize_text_field( $_GET['srt_notice'] ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RW Site Review Tasks', 'rw-site-review-tasks' ); ?></h1>

			<?php if ( 'saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'rw-site-review-tasks' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Pages & Access', 'rw-site-review-tasks' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Each company now has its own checklist, review interval, due date, and lead days — set those on the company edit screen.', 'rw-site-review-tasks' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'srt_settings', 'srt_settings_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="srt_dashboard_page_id"><?php esc_html_e( 'Maker dashboard page', 'rw-site-review-tasks' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'name'             => 'srt_dashboard_page_id',
									'id'               => 'srt_dashboard_page_id',
									'selected'         => (int) get_option( 'srt_dashboard_page_id' ),
									'show_option_none' => __( '— Select a page —', 'rw-site-review-tasks' ),
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'The page where you place the [srt_maker_dashboard] shortcode.', 'rw-site-review-tasks' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srt_task_page_id"><?php esc_html_e( 'Task detail page', 'rw-site-review-tasks' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'name'             => 'srt_task_page_id',
									'id'               => 'srt_task_page_id',
									'selected'         => (int) get_option( 'srt_task_page_id' ),
									'show_option_none' => __( '— Select a page —', 'rw-site-review-tasks' ),
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'The page where you place the [srt_task_view] shortcode. Dashboard tiles link here with a task_id query var.', 'rw-site-review-tasks' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Restrict to assigned maker', 'rw-site-review-tasks' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="srt_restrict_to_maker" value="1" <?php checked( get_option( 'srt_restrict_to_maker', 1 ) ); ?> />
								<?php esc_html_e( 'Makers can only see and edit their own companies/tasks (on the dashboard and the task page)', 'rw-site-review-tasks' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Turn this off to let any logged-in user view every company and task — e.g. for a manager who needs the full picture without an admin account. Login is still required either way.', 'rw-site-review-tasks' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srt_cron_time"><?php esc_html_e( 'Daily check time', 'rw-site-review-tasks' ); ?></label></th>
						<td>
							<input type="time" name="srt_cron_time" id="srt_cron_time" value="<?php echo esc_attr( get_option( 'srt_cron_time', '08:00' ) ); ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: site timezone name */
									esc_html__( 'Time of day (site timezone: %s) to run the daily task-creation check. Saving reschedules the cron immediately.', 'rw-site-review-tasks' ),
									esc_html( wp_timezone_string() )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srt_wave_api_key"><?php esc_html_e( 'WAVE API key', 'rw-site-review-tasks' ); ?></label></th>
						<td>
							<input type="text" name="srt_wave_api_key" id="srt_wave_api_key" value="<?php echo esc_attr( get_option( 'srt_wave_api_key', '' ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank to skip accessibility scanning', 'rw-site-review-tasks' ); ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: WAVE API link */
									esc_html__( 'Optional. Get a key at %s (~$4/month for 500 pages). When set, each new task runs an accessibility scan against the company\'s website URL.', 'rw-site-review-tasks' ),
									'<a href="https://wave.webaim.org/api" target="_blank" rel="noopener">wave.webaim.org/api</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srt_psi_api_key"><?php esc_html_e( 'Google PageSpeed API key', 'rw-site-review-tasks' ); ?></label></th>
						<td>
							<input type="text" name="srt_psi_api_key" id="srt_psi_api_key" value="<?php echo esc_attr( get_option( 'srt_psi_api_key', '' ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Optional — leave blank to run keyless (lower quota)', 'rw-site-review-tasks' ); ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: Google API console link */
									esc_html__( 'Optional. Create a key in %s (enable the "PageSpeed Insights API"). Free. When PageSpeed is enabled on a company, each site scan adds Lighthouse scores (performance, SEO, accessibility, best practices) and Core Web Vitals for the homepage.', 'rw-site-review-tasks' ),
									'<a href="https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com" target="_blank" rel="noopener">Google Cloud Console</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srt_log_retention_days"><?php esc_html_e( 'Run log retention', 'rw-site-review-tasks' ); ?></label></th>
						<td>
							<input type="number" name="srt_log_retention_days" id="srt_log_retention_days" min="0" step="1" value="<?php echo esc_attr( get_option( 'srt_log_retention_days', SRT_Cron::LOG_RETENTION_DEFAULT ) ); ?>" class="small-text" />
							<?php esc_html_e( 'days', 'rw-site-review-tasks' ); ?>
							<p class="description">
								<?php
								printf(
									/* translators: %d: hard entry cap */
									esc_html__( 'How long to keep Run log entries. Older entries are removed the next time the log is written. Set 0 to keep entries until the %d-entry limit is reached.', 'rw-site-review-tasks' ),
									(int) SRT_Cron::LOG_CAP
								);
								?>
							</p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="srt_save_settings" value="1" class="button button-primary"><?php esc_html_e( 'Save Settings', 'rw-site-review-tasks' ); ?></button>
				</p>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Run status', 'rw-site-review-tasks' ); ?></h2>
			<p>
				<?php
				if ( $next_run ) {
					printf(
						/* translators: 1: date/time, 2: timezone name */
						esc_html__( 'Next scheduled check: %1$s (%2$s)', 'rw-site-review-tasks' ),
						esc_html( wp_date( 'Y-m-d H:i', $next_run ) ),
						esc_html( wp_timezone_string() )
					);
				} else {
					esc_html_e( 'No daily check is currently scheduled.', 'rw-site-review-tasks' );
				}
				?>
			</p>
			<p class="description"><?php esc_html_e( 'Tasks are created automatically at the scheduled time. To create one immediately for a single company, use the “Create review task now” button on that company’s edit screen.', 'rw-site-review-tasks' ); ?></p>

			<h2 id="srt-run-log"><?php esc_html_e( 'Run log', 'rw-site-review-tasks' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'rw-site-review-tasks' ); ?></th>
						<th><?php esc_html_e( 'Company', 'rw-site-review-tasks' ); ?></th>
						<th><?php esc_html_e( 'Task', 'rw-site-review-tasks' ); ?></th>
						<th><?php esc_html_e( 'Due', 'rw-site-review-tasks' ); ?></th>
						<th><?php esc_html_e( 'Result', 'rw-site-review-tasks' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $log ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No runs logged yet.', 'rw-site-review-tasks' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $log as $entry ) : ?>
							<tr id="srt-log-<?php echo esc_attr( $entry['task_id'] ); ?>">
								<td><?php echo esc_html( $entry['time'] ); ?></td>
								<td>
									<?php if ( ! empty( $entry['company_id'] ) ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $entry['company_id'] ) ); ?>">
											<?php echo esc_html( $entry['company'] ); ?>
										</a>
									<?php else : ?>
										<?php echo esc_html( $entry['company'] ); ?>
									<?php endif; ?>
								</td>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $entry['task_id'] ) ); ?>">
										#<?php echo esc_html( $entry['task_id'] ); ?>
									</a>
								</td>
								<td><?php echo esc_html( isset( $entry['due'] ) ? $entry['due'] : '' ); ?></td>
								<td><?php echo esc_html( $entry['result'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

SRT_Admin_Settings::init();
