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

			$log_retention = isset( $_POST['srt_log_retention_days'] ) ? max( 0, (int) $_POST['srt_log_retention_days'] ) : SRT_Cron::LOG_RETENTION_DEFAULT;
			update_option( 'srt_log_retention_days', $log_retention );

			$from_name = isset( $_POST['srt_mail_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['srt_mail_from_name'] ) ) : '';
			update_option( SRT_Mailer::FROM_NAME_OPT, $from_name );

			// Blank clears the override and falls back to the WordPress default;
			// anything that isn't a valid address is rejected rather than stored.
			$from_address = isset( $_POST['srt_mail_from_address'] ) ? sanitize_email( wp_unslash( $_POST['srt_mail_from_address'] ) ) : '';
			update_option( SRT_Mailer::FROM_ADDR_OPT, is_email( $from_address ) ? $from_address : '' );

			$reply_to = isset( $_POST['srt_mail_reply_to'] ) ? sanitize_email( wp_unslash( $_POST['srt_mail_reply_to'] ) ) : '';
			update_option( SRT_Mailer::REPLY_TO_OPT, is_email( $reply_to ) ? $reply_to : '' );

			// Blank clears it — the webhook simply stays inert until a URL is set.
			$webhook_url = isset( $_POST['srt_webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['srt_webhook_url'] ) ) : '';
			update_option( SRT_Webhook::URL_OPT, $webhook_url );

			$webhook_secret = isset( $_POST['srt_webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['srt_webhook_secret'] ) ) : '';
			update_option( SRT_Webhook::SECRET_OPT, $webhook_secret );

			$kinsta_key = isset( $_POST['srt_kinsta_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['srt_kinsta_api_key'] ) ) : '';
			update_option( SRT_Kinsta::API_KEY_OPT, $kinsta_key );

			$kinsta_php = isset( $_POST['srt_kinsta_recommended_php'] ) ? sanitize_text_field( wp_unslash( $_POST['srt_kinsta_recommended_php'] ) ) : '';
			update_option( SRT_Kinsta::PHP_REC_OPT, $kinsta_php );

			$gtmetrix_key = isset( $_POST['srt_gtmetrix_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['srt_gtmetrix_api_key'] ) ) : '';
			update_option( SRT_GTmetrix::API_KEY_OPT, $gtmetrix_key );

			wp_safe_redirect( self::page_url( 'saved' ) );
			exit;
		}

		if ( isset( $_POST['srt_send_test_email'] ) && check_admin_referer( 'srt_test_email', 'srt_test_email_nonce' ) ) {
			wp_safe_redirect( self::page_url( self::send_test_email() ) );
			exit;
		}

		if ( isset( $_POST['srt_send_test_webhook'] ) && check_admin_referer( 'srt_test_webhook', 'srt_test_webhook_nonce' ) ) {
			wp_safe_redirect( self::page_url( self::send_test_webhook() ) );
			exit;
		}
	}

	/**
	 * Sends a notification-shaped test message to the current user so the From
	 * address and the transport can be verified without waiting for a real task.
	 *
	 * @return string Notice key for the redirect.
	 */
	private static function send_test_email() {
		$user = wp_get_current_user();

		if ( ! $user || ! is_email( $user->user_email ) ) {
			return 'test_no_address';
		}

		$sent = SRT_Mailer::send(
			$user->user_email,
			__( 'Test: website review task notification', 'rw-site-review-tasks' ),
			__( 'Email settings test', 'rw-site-review-tasks' ),
			array(
				__( 'This is a test of the review task notification email. If it reached your inbox rather than your spam folder, the From address and mail transport are working.', 'rw-site-review-tasks' ),
				sprintf(
					/* translators: %s: from address */
					__( 'It was sent from %s.', 'rw-site-review-tasks' ),
					SRT_Mailer::from_address()
				),
			),
			admin_url( 'edit.php?post_type=' . SRT_CPT_Company::POST_TYPE ),
			__( 'Open the Companies screen', 'rw-site-review-tasks' )
		);

		if ( $sent ) {
			return 'test_sent';
		}

		$error = SRT_Mailer::last_error();

		if ( $error ) {
			set_transient( 'srt_test_email_error', $error, 60 );
		}

		return 'test_failed';
	}

	/**
	 * Posts a sample "task completed" payload to the configured webhook URL so
	 * an admin can verify the URL and secret without waiting for a real task.
	 *
	 * @return string Notice key for the redirect.
	 */
	private static function send_test_webhook() {
		if ( ! trim( (string) get_option( SRT_Webhook::URL_OPT, '' ) ) ) {
			return 'webhook_no_url';
		}

		if ( SRT_Webhook::send_test() ) {
			return 'webhook_test_sent';
		}

		$error = SRT_Webhook::last_error();

		if ( $error ) {
			set_transient( 'srt_test_webhook_error', $error, 60 );
		}

		return 'webhook_test_failed';
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

			<?php if ( 'test_sent' === $notice ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %s: recipient email address */
							esc_html__( 'Test email handed off to the mail server for %s. Check your inbox and your spam folder — if it landed in spam, the notes below explain why.', 'rw-site-review-tasks' ),
							esc_html( wp_get_current_user()->user_email )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( 'test_failed' === $notice ) : ?>
				<?php $test_error = get_transient( 'srt_test_email_error' ); ?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'The test email could not be sent. WordPress rejected it before it left the site, so no review notifications are going out either.', 'rw-site-review-tasks' ); ?></p>
					<?php if ( $test_error ) : ?>
						<p><code><?php echo esc_html( $test_error ); ?></code></p>
					<?php endif; ?>
				</div>
				<?php delete_transient( 'srt_test_email_error' ); ?>
			<?php endif; ?>

			<?php if ( 'test_no_address' === $notice ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Your user account has no valid email address, so there is nowhere to send the test.', 'rw-site-review-tasks' ); ?></p></div>
			<?php endif; ?>

			<?php if ( 'webhook_test_sent' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Test webhook sent — the receiving server accepted it (2xx response).', 'rw-site-review-tasks' ); ?></p></div>
			<?php endif; ?>

			<?php if ( 'webhook_test_failed' === $notice ) : ?>
				<?php $webhook_test_error = get_transient( 'srt_test_webhook_error' ); ?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'The test webhook could not be delivered.', 'rw-site-review-tasks' ); ?></p>
					<?php if ( $webhook_test_error ) : ?>
						<p><code><?php echo esc_html( $webhook_test_error ); ?></code></p>
					<?php endif; ?>
				</div>
				<?php delete_transient( 'srt_test_webhook_error' ); ?>
			<?php endif; ?>

			<?php if ( 'webhook_no_url' === $notice ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Add a webhook URL below and save settings before sending a test.', 'rw-site-review-tasks' ); ?></p></div>
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
							<p class="description">
								<?php
								printf(
									/* translators: %d: seconds between staggered scans */
									esc_html__( 'If several companies are due the same day, their site scans are staggered %d seconds apart automatically (rather than all firing at once) to avoid overloading the WAVE/PageSpeed APIs and the server. No action needed here.', 'rw-site-review-tasks' ),
									(int) SRT_Cron::SCAN_STAGGER_SECONDS
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srt_mail_from_name"><?php esc_html_e( 'Email “From” name', 'rw-site-review-tasks' ); ?></label></th>
						<td>
							<input type="text" name="srt_mail_from_name" id="srt_mail_from_name" value="<?php echo esc_attr( get_option( SRT_Mailer::FROM_NAME_OPT, '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>" />
							<p class="description"><?php esc_html_e( 'The sender name on task notification emails. Defaults to the site title.', 'rw-site-review-tasks' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srt_mail_from_address"><?php esc_html_e( 'Email “From” address', 'rw-site-review-tasks' ); ?></label></th>
						<td>
							<input type="email" name="srt_mail_from_address" id="srt_mail_from_address" value="<?php echo esc_attr( get_option( SRT_Mailer::FROM_ADDR_OPT, '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( SRT_Mailer::default_from_address() ); ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: 1: site domain, 2: current effective from address */
									esc_html__( 'Use a real mailbox on %1$s that someone monitors. Leave blank to use the WordPress default (%2$s), which is usually a non-existent address — a common reason notifications are filtered as spam.', 'rw-site-review-tasks' ),
									esc_html( SRT_Mailer::site_domain() ),
									esc_html( SRT_Mailer::default_from_address() )
								);
								?>
							</p>
							<?php if ( ! SRT_Mailer::is_from_aligned() ) : ?>
								<p class="description" style="color:#b32d2e;">
									<?php
									printf(
										/* translators: 1: from address, 2: site domain */
										esc_html__( 'Warning: %1$s is not on %2$s. Sending as another domain (a Gmail or Yahoo address, for example) fails SPF/DKIM alignment and is very likely to be rejected or spam-foldered.', 'rw-site-review-tasks' ),
										esc_html( SRT_Mailer::from_address() ),
										esc_html( SRT_Mailer::site_domain() )
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srt_mail_reply_to"><?php esc_html_e( 'Email “Reply-To” address', 'rw-site-review-tasks' ); ?></label></th>
						<td>
							<input type="email" name="srt_mail_reply_to" id="srt_mail_reply_to" value="<?php echo esc_attr( get_option( SRT_Mailer::REPLY_TO_OPT, '' ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Optional', 'rw-site-review-tasks' ); ?>" />
							<p class="description"><?php esc_html_e( 'Where replies go if a maker or marketing guide answers the notification. Leave blank to reply to the From address.', 'rw-site-review-tasks' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srt_webhook_url"><?php esc_html_e( 'Webhook URL', 'rw-site-review-tasks' ); ?></label></th>
						<td>
							<input type="url" name="srt_webhook_url" id="srt_webhook_url" value="<?php echo esc_attr( get_option( SRT_Webhook::URL_OPT, '' ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'https://hooks.zapier.com/…', 'rw-site-review-tasks' ); ?>" />
							<p class="description">
								<?php esc_html_e( 'When a task is saved as Completed or Save Draft, this site POSTs a JSON payload here — a Zapier or Make webhook trigger, an n8n workflow, a Slack incoming webhook, or a CRM endpoint. Leave blank to disable; nothing is sent anywhere until a URL is saved.', 'rw-site-review-tasks' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'Payload fields: event, task_id, status ("completed" or "needs_work"), status_label, previous_status, company_id, company_name, period, task_url, site, timestamp.', 'rw-site-review-tasks' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srt_webhook_secret"><?php esc_html_e( 'Webhook signing secret', 'rw-site-review-tasks' ); ?></label></th>
						<td>
							<input type="password" name="srt_webhook_secret" id="srt_webhook_secret" value="<?php echo esc_attr( get_option( SRT_Webhook::SECRET_OPT, '' ) ); ?>" class="regular-text" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e( 'Optional', 'rw-site-review-tasks' ); ?>" />
							<button type="button" class="button button-secondary srt-toggle-secret" data-target="srt_webhook_secret" aria-pressed="false"><?php esc_html_e( 'Show', 'rw-site-review-tasks' ); ?></button>
							<p class="description"><?php esc_html_e( 'Optional. If set, requests carry an X-SRT-Signature header — an HMAC-SHA256 of the raw JSON body, keyed with this secret — so the receiving endpoint can verify the request came from this site.', 'rw-site-review-tasks' ); ?></p>
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
					<tr>
						<th scope="row"><label for="srt_kinsta_api_key"><?php esc_html_e( 'Kinsta API key', 'rw-site-review-tasks' ); ?></label></th>
						<td>
							<input type="password" name="srt_kinsta_api_key" id="srt_kinsta_api_key" value="<?php echo esc_attr( get_option( SRT_Kinsta::API_KEY_OPT, '' ) ); ?>" class="regular-text" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e( 'Leave blank to skip Kinsta data', 'rw-site-review-tasks' ); ?>" />
							<button type="button" class="button button-secondary srt-toggle-secret" data-target="srt_kinsta_api_key" aria-pressed="false"><?php esc_html_e( 'Show', 'rw-site-review-tasks' ); ?></button>
							<p class="description">
								<?php
								printf(
									/* translators: %s: MyKinsta API keys link */
									esc_html__( 'Create one under your username → Company settings → API Keys in %s. When set, and a company has a Kinsta Site ID, each task pulls plugin/theme/core update status, PHP version, and the last backup date from Kinsta.', 'rw-site-review-tasks' ),
									'<a href="https://my.kinsta.com" target="_blank" rel="noopener">my.kinsta.com</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srt_kinsta_recommended_php"><?php esc_html_e( 'Recommended PHP version', 'rw-site-review-tasks' ); ?></label></th>
						<td>
							<input type="text" name="srt_kinsta_recommended_php" id="srt_kinsta_recommended_php" value="<?php echo esc_attr( get_option( SRT_Kinsta::PHP_REC_OPT, '8.3' ) ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'A company\'s PHP Version item is flagged "Needs attention" when its Kinsta environment runs an older version than this. Update as newer PHP versions become the recommended baseline.', 'rw-site-review-tasks' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srt_gtmetrix_api_key"><?php esc_html_e( 'GTmetrix API key', 'rw-site-review-tasks' ); ?></label></th>
						<td>
							<input type="password" name="srt_gtmetrix_api_key" id="srt_gtmetrix_api_key" value="<?php echo esc_attr( get_option( SRT_GTmetrix::API_KEY_OPT, '' ) ); ?>" class="regular-text" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e( 'Leave blank to skip Page Load Speed testing', 'rw-site-review-tasks' ); ?>" />
							<button type="button" class="button button-secondary srt-toggle-secret" data-target="srt_gtmetrix_api_key" aria-pressed="false"><?php esc_html_e( 'Show', 'rw-site-review-tasks' ); ?></button>
							<p class="description">
								<?php
								printf(
									/* translators: %s: GTmetrix API key link */
									esc_html__( 'Find your key under Account → GTmetrix API in %s. When set, each task runs a GTmetrix Page Load Speed test on the company\'s website URL. Free-tier accounts get a limited daily test allowance — see GTmetrix\'s plan details if you\'re reviewing many companies.', 'rw-site-review-tasks' ),
									'<a href="https://gtmetrix.com/api/" target="_blank" rel="noopener">gtmetrix.com</a>'
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

			<?php // Reveal toggle for masked secret fields. Inline because this is the only screen in the plugin that needs script. ?>
			<script>
			document.querySelectorAll( '.srt-toggle-secret' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var field = document.getElementById( button.dataset.target );

					if ( ! field ) {
						return;
					}

					var reveal = 'password' === field.type;

					field.type          = reveal ? 'text' : 'password';
					button.textContent  = reveal ? <?php echo wp_json_encode( __( 'Hide', 'rw-site-review-tasks' ) ); ?> : <?php echo wp_json_encode( __( 'Show', 'rw-site-review-tasks' ) ); ?>;
					button.setAttribute( 'aria-pressed', reveal ? 'true' : 'false' );
				} );
			} );
			</script>

			<hr />

			<h2><?php esc_html_e( 'Email deliverability', 'rw-site-review-tasks' ); ?></h2>
			<p class="description" style="max-width:44rem;">
				<?php esc_html_e( 'Notification emails are sent as HTML with a plain-text alternative, from the address configured above, with the return path aligned to it. That covers what this plugin controls. Whether they reach the inbox mostly depends on two things it cannot control:', 'rw-site-review-tasks' ); ?>
			</p>
			<ol class="description" style="max-width:44rem;">
				<li>
					<?php esc_html_e( 'Authenticated SMTP. Mail sent through PHP’s built-in mail() from a web host’s shared IP is the most common reason notifications land in spam, and some hosts (Kinsta among them) do not deliver it at all. Route mail through a transactional provider — Postmark, SendGrid, Amazon SES, Mailgun, or Google Workspace SMTP — using an SMTP plugin.', 'rw-site-review-tasks' ); ?>
				</li>
				<li>
					<?php
					printf(
						/* translators: %s: site domain */
						esc_html__( 'DNS records on %s: an SPF record listing whichever service sends the mail, a DKIM key from that service, and a DMARC record. Without these, a correct From address is not enough.', 'rw-site-review-tasks' ),
						esc_html( SRT_Mailer::site_domain() )
					);
					?>
				</li>
			</ol>
			<form method="post">
				<?php wp_nonce_field( 'srt_test_email', 'srt_test_email_nonce' ); ?>
				<p>
					<button type="submit" name="srt_send_test_email" value="1" class="button"><?php esc_html_e( 'Send test email to me', 'rw-site-review-tasks' ); ?></button>
					<span class="description">
						<?php
						printf(
							/* translators: 1: current user email, 2: effective from address */
							esc_html__( 'Sends a sample notification to %1$s from %2$s.', 'rw-site-review-tasks' ),
							esc_html( wp_get_current_user()->user_email ),
							esc_html( SRT_Mailer::from_address() )
						);
						?>
					</span>
				</p>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Integrations', 'rw-site-review-tasks' ); ?></h2>
			<p class="description" style="max-width:44rem;">
				<?php esc_html_e( 'When a task is saved as Completed or Save Draft, this site can POST a JSON payload to a URL of your choosing — a Zapier or Make webhook trigger, an n8n workflow, a Slack incoming webhook, or a CRM endpoint. Configure the URL and secret in "Pages & Access" above; no further changes here are needed to add or change a destination, that happens on the receiving side.', 'rw-site-review-tasks' ); ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( 'srt_test_webhook', 'srt_test_webhook_nonce' ); ?>
				<p>
					<button type="submit" name="srt_send_test_webhook" value="1" class="button"><?php esc_html_e( 'Send test webhook', 'rw-site-review-tasks' ); ?></button>
					<span class="description"><?php esc_html_e( 'Posts a sample "completed" payload to the currently saved webhook URL.', 'rw-site-review-tasks' ); ?></span>
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
