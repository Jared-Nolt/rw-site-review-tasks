<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRT_Import {

	const PAGE_SLUG = 'srt-import-companies';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
	}

	public static function add_menu() {
		add_submenu_page(
			'edit.php?post_type=' . SRT_CPT_Company::POST_TYPE,
			__( 'Import Companies', 'rw-site-review-tasks' ),
			__( 'Import CSV', 'rw-site-review-tasks' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_post() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! isset( $_POST['srt_import_companies'] ) || ! check_admin_referer( 'srt_import_companies', 'srt_import_nonce' ) ) {
			return;
		}

		if ( empty( $_FILES['srt_csv_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['srt_csv_file']['tmp_name'] ) ) {
			set_transient( 'srt_import_result', array( 'error' => __( 'No file was uploaded.', 'rw-site-review-tasks' ) ), 60 );
		} elseif ( 'csv' !== strtolower( pathinfo( $_FILES['srt_csv_file']['name'], PATHINFO_EXTENSION ) ) ) {
			set_transient( 'srt_import_result', array( 'error' => __( 'Please upload a .csv file.', 'rw-site-review-tasks' ) ), 60 );
		} else {
			$result = self::process_csv( $_FILES['srt_csv_file']['tmp_name'] );
			set_transient( 'srt_import_result', $result, 60 );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => SRT_CPT_Company::POST_TYPE,
					'page'      => self::PAGE_SLUG,
					'srt_notice' => 'imported',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Expected columns: Company Name, Maker (email or username, optional), Active (yes/no, optional,
	 * defaults to yes), Interval (monthly/quarterly/semi_annually/annually, optional, defaults to
	 * monthly), Lead Days (integer, optional, defaults to 0), Due Date (YYYY-MM-DD, optional, defaults
	 * to today). A header row is detected and skipped automatically. Matching is by exact company
	 * title: existing companies are updated, new names are created. Each company's checklist is no
	 * longer set from a shared template — new companies get the default checklist sections (see
	 * srt_default_checklist_items()); edit checklist items directly on the company afterward.
	 */
	private static function process_csv( $tmp_path ) {
		$handle = fopen( $tmp_path, 'r' );

		if ( ! $handle ) {
			return array( 'error' => __( 'Could not read the uploaded file.', 'rw-site-review-tasks' ) );
		}

		$rows = array();
		while ( ( $data = fgetcsv( $handle ) ) !== false ) {
			$rows[] = $data;
		}
		fclose( $handle );

		if ( ! empty( $rows ) ) {
			$first = array_map( 'trim', $rows[0] );
			if ( isset( $first[0] ) && in_array( strtolower( $first[0] ), array( 'company', 'company name', 'name' ), true ) ) {
				array_shift( $rows );
			}
		}

		$created  = 0;
		$updated  = 0;
		$warnings = array();

		foreach ( $rows as $i => $row ) {
			$line = $i + 1;
			$name = isset( $row[0] ) ? trim( $row[0] ) : '';

			if ( '' === $name ) {
				continue;
			}

			$maker_raw     = isset( $row[1] ) ? trim( $row[1] ) : '';
			$active_raw    = isset( $row[2] ) ? trim( $row[2] ) : '';
			$interval_raw  = isset( $row[3] ) ? trim( $row[3] ) : '';
			$lead_days_raw = isset( $row[4] ) ? trim( $row[4] ) : '';
			$due_date_raw  = isset( $row[5] ) ? trim( $row[5] ) : '';

			$active = 1;
			if ( '' !== $active_raw ) {
				$active = in_array( strtolower( $active_raw ), array( '1', 'yes', 'y', 'true', 'active' ), true ) ? 1 : 0;
			}

			$valid_intervals = array( 'monthly', 'quarterly', 'semi_annually', 'annually' );
			$interval        = strtolower( $interval_raw );
			if ( ! in_array( $interval, $valid_intervals, true ) ) {
				$interval = 'monthly';
			}

			$lead_days = '' !== $lead_days_raw ? max( 0, (int) $lead_days_raw ) : 0;

			$due_date = $due_date_raw && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due_date_raw ) ? $due_date_raw : current_time( 'Y-m-d' );

			$maker_id = 0;
			if ( '' !== $maker_raw ) {
				$user = is_email( $maker_raw ) ? get_user_by( 'email', $maker_raw ) : get_user_by( 'login', $maker_raw );

				if ( $user ) {
					$maker_id = $user->ID;
				} else {
					$warnings[] = sprintf(
						/* translators: 1: row number, 2: maker value, 3: company name */
						__( 'Row %1$d: maker "%2$s" not found for "%3$s" — left unassigned.', 'rw-site-review-tasks' ),
						$line,
						$maker_raw,
						$name
					);
				}
			}

			$existing = get_posts(
				array(
					'post_type'      => SRT_CPT_Company::POST_TYPE,
					'post_status'    => 'any',
					'title'          => $name,
					'posts_per_page' => 1,
				)
			);

			if ( $existing ) {
				$post_id = $existing[0]->ID;
				$updated++;
			} else {
				$post_id = wp_insert_post(
					array(
						'post_type'   => SRT_CPT_Company::POST_TYPE,
						'post_status' => 'publish',
						'post_title'  => $name,
					)
				);

				if ( is_wp_error( $post_id ) || ! $post_id ) {
					$warnings[] = sprintf(
						/* translators: 1: row number, 2: company name */
						__( 'Row %1$d: failed to create "%2$s".', 'rw-site-review-tasks' ),
						$line,
						$name
					);
					continue;
				}

				$created++;
			}

			if ( $maker_id ) {
				update_field( 'maker', $maker_id, $post_id );
			}
			update_field( 'active', $active, $post_id );
			update_field( 'interval', $interval, $post_id );
			update_field( 'lead_days', $lead_days, $post_id );
			update_field( 'due_date', $due_date, $post_id );
		}

		return array(
			'created'  => $created,
			'updated'  => $updated,
			'warnings' => $warnings,
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$result = get_transient( 'srt_import_result' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Companies', 'rw-site-review-tasks' ); ?></h1>

			<?php if ( $result && ! empty( $result['error'] ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $result['error'] ); ?></p></div>
			<?php elseif ( $result ) : ?>
				<div class="notice notice-success">
					<p>
						<?php
						printf(
							/* translators: 1: created count, 2: updated count */
							esc_html__( 'Import finished: %1$d company(ies) created, %2$d updated.', 'rw-site-review-tasks' ),
							(int) $result['created'],
							(int) $result['updated']
						);
						?>
					</p>
				</div>
				<?php if ( ! empty( $result['warnings'] ) ) : ?>
					<div class="notice notice-warning">
						<p><strong><?php esc_html_e( 'Warnings:', 'rw-site-review-tasks' ); ?></strong></p>
						<ul>
							<?php foreach ( $result['warnings'] as $warning ) : ?>
								<li><?php echo esc_html( $warning ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'srt_import_companies', 'srt_import_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="srt_csv_file"><?php esc_html_e( 'CSV file', 'rw-site-review-tasks' ); ?></label></th>
						<td>
							<input type="file" id="srt_csv_file" name="srt_csv_file" accept=".csv" required />
							<p class="description">
								<?php esc_html_e( 'Columns: Company Name, Maker (email or username, optional), Active (yes/no, optional — defaults to yes), Interval (monthly/quarterly/semi_annually/annually, optional — defaults to monthly), Lead Days (integer, optional — defaults to 0), Due Date (YYYY-MM-DD, optional — defaults to today). A header row is detected automatically. Matching is by exact company name: existing companies are updated, new names are created. New companies get the default checklist sections automatically.', 'rw-site-review-tasks' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="srt_import_companies" value="1" class="button button-primary"><?php esc_html_e( 'Import', 'rw-site-review-tasks' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}

SRT_Import::init();
