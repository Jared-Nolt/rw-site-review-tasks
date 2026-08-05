<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page listing task results across every company — filterable by
 * company and status, with a CSV export over a selectable Period (due date)
 * range. Private like every other admin screen in this plugin: manage_options
 * only, no front-end equivalent.
 */
class SRT_Results_Admin {

	const PAGE_SLUG     = 'srt-results';
	const EXPORT_ACTION = 'srt_export_results';

	/** Number of Page Load Speed columns: homepage + up to 5 Additional Pages to Scan. */
	const GTMETRIX_MAX_PAGES = 6;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( __CLASS__, 'handle_export' ) );
	}

	public static function add_menu() {
		add_submenu_page(
			'edit.php?post_type=' . SRT_CPT_Company::POST_TYPE,
			__( 'Task Results', 'rw-site-review-tasks' ),
			__( 'Task Results', 'rw-site-review-tasks' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Reads filter args from the current request. Shared by the on-screen
	 * table and the CSV export so both always reflect the same filters.
	 */
	private static function filters_from_request() {
		$from = isset( $_REQUEST['srt_filter_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['srt_filter_from'] ) ) : '';
		$to   = isset( $_REQUEST['srt_filter_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['srt_filter_to'] ) ) : '';

		return array(
			'company_id' => isset( $_REQUEST['srt_filter_company'] ) ? absint( $_REQUEST['srt_filter_company'] ) : 0,
			'status'     => isset( $_REQUEST['srt_filter_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['srt_filter_status'] ) ) : '',
			'from'       => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : '',
			'to'         => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? $to : '',
		);
	}

	/**
	 * Fetches tasks matching company + Period date range at the DB level,
	 * then filters by computed status tag in PHP. completed/needs_work are
	 * real 'status' meta values, but overdue/ready are derived (empty status
	 * + Period vs today via srt_get_task_tag_slug()) and can't be expressed
	 * as a single meta_query, so that half of the filter happens here.
	 */
	private static function query_results( $filters ) {
		$meta_query = array();

		if ( $filters['company_id'] ) {
			$meta_query[] = array(
				'key'   => 'company',
				'value' => $filters['company_id'],
			);
		}

		if ( $filters['from'] ) {
			$meta_query[] = array(
				'key'     => 'period',
				'value'   => $filters['from'],
				'compare' => '>=',
				'type'    => 'DATE',
			);
		}

		if ( $filters['to'] ) {
			$meta_query[] = array(
				'key'     => 'period',
				'value'   => $filters['to'],
				'compare' => '<=',
				'type'    => 'DATE',
			);
		}

		$posts = get_posts(
			array(
				'post_type'      => SRT_CPT_Task::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'meta_key'       => 'period',
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
				'meta_query'     => $meta_query,
			)
		);

		$rows = array();

		foreach ( $posts as $post ) {
			$tag_slug = srt_get_task_tag_slug( $post->ID );

			if ( $filters['status'] && $filters['status'] !== $tag_slug ) {
				continue;
			}

			$company_id = (int) get_field( 'company', $post->ID );
			$security   = self::security_fields( $post->ID );
			$pages      = self::gtmetrix_pages( $post->ID );

			$rows[] = array(
				'task_id'          => $post->ID,
				'company_id'       => $company_id,
				'company_name'     => $company_id ? get_the_title( $company_id ) : '',
				'period'           => get_field( 'period', $post->ID ),
				'status_slug'      => $tag_slug,
				'status_label'     => srt_get_task_tag_label( $tag_slug ),
				'maker_name'       => self::user_name( (int) get_field( 'maker', $post->ID ) ),
				'mg_name'          => self::user_name( (int) get_field( 'marketing_guide', $post->ID ) ),
				'created'          => get_the_date( 'Y-m-d H:i', $post ),
				'security_core'    => $security['core'],
				'security_plugins' => $security['plugins'],
				'security_theme'   => $security['theme'],
				'security_php'     => $security['php'],
				'last_backup'      => self::last_backup( $post->ID ),
				'gtmetrix_pages'   => $pages,
			);
		}

		return $rows;
	}

	private static function user_name( $user_id ) {
		if ( ! $user_id ) {
			return '—';
		}
		$user = get_userdata( $user_id );
		return $user ? $user->display_name : '—';
	}

	/**
	 * Core/Plugins/Theme/PHP from Kinsta's Security Overview, keyed for direct
	 * column access. Each entry is either null (no data yet) or
	 * { status: 'ok'|'attention', detail: <version/update-count string> } —
	 * `detail` is what's actually shown in the table, not a generic "OK".
	 */
	private static function security_fields( $task_id ) {
		$results  = SRT_Kinsta::get_results( $task_id );
		$security = isset( $results['security'] ) ? $results['security'] : array();

		$fields = array();

		foreach ( array( 'core', 'plugins', 'theme', 'php' ) as $key ) {
			if ( empty( $security ) || isset( $security['error'] ) || ! isset( $security[ $key ]['status'] ) ) {
				$fields[ $key ] = null;
				continue;
			}

			$fields[ $key ] = array(
				'status' => $security[ $key ]['status'],
				'detail' => isset( $security[ $key ]['detail'] ) ? $security[ $key ]['detail'] : '',
			);
		}

		return $fields;
	}

	/**
	 * Kinsta's last completed backup date, or '' if none / not fetched.
	 */
	private static function last_backup( $task_id ) {
		$results = SRT_Kinsta::get_results( $task_id );
		$backups = isset( $results['backups'] ) ? $results['backups'] : array();

		if ( empty( $backups ) || isset( $backups['error'] ) || empty( $backups['last_backup'] ) ) {
			return '';
		}

		return $backups['last_backup'];
	}

	/**
	 * GTmetrix Page Load Speed as a fixed 6-slot array (Page1..Page6: homepage
	 * plus up to 5 Additional Pages to Scan). A slot is null when the company
	 * has no page configured there; otherwise it's the performance score (or
	 * Pending/Error/— while a configured page hasn't completed a run yet).
	 */
	private static function gtmetrix_pages( $task_id ) {
		$results = SRT_GTmetrix::get_results( $task_id );
		$pages   = isset( $results['pages'] ) && is_array( $results['pages'] ) ? $results['pages'] : array();

		$slots = array_fill( 0, self::GTMETRIX_MAX_PAGES, null );

		foreach ( $pages as $index => $page ) {
			if ( $index >= self::GTMETRIX_MAX_PAGES ) {
				break;
			}

			$status = isset( $page['status'] ) ? $page['status'] : '';

			if ( 'ready' === $status && isset( $page['current']['performance'] ) && null !== $page['current']['performance'] ) {
				$value = $page['current']['performance'] . '/100';
			} elseif ( 'pending' === $status ) {
				$value = __( 'Pending', 'rw-site-review-tasks' );
			} elseif ( 'error' === $status ) {
				$value = __( 'Error', 'rw-site-review-tasks' );
			} else {
				$value = '—'; // Page is configured but hasn't completed a run yet.
			}

			$slots[ $index ] = $value;
		}

		return $slots;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$filters   = self::filters_from_request();
		$rows      = self::query_results( $filters );
		$companies = get_posts(
			array(
				'post_type'      => SRT_CPT_Company::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$page_url   = add_query_arg(
			array(
				'post_type' => SRT_CPT_Company::POST_TYPE,
				'page'      => self::PAGE_SLUG,
			),
			admin_url( 'edit.php' )
		);
		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'             => self::EXPORT_ACTION,
					'srt_filter_company' => $filters['company_id'],
					'srt_filter_status'  => $filters['status'],
					'srt_filter_from'    => $filters['from'],
					'srt_filter_to'      => $filters['to'],
				),
				admin_url( 'admin-post.php' )
			),
			self::EXPORT_ACTION
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Task Results', 'rw-site-review-tasks' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Every review task across every company, filterable below. Export the current filter as a CSV for spreadsheet analysis or sharing outside WordPress.', 'rw-site-review-tasks' ); ?></p>

			<style>
				.srt-results-badge { display: inline-block; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.02em; padding: 0.2rem 0.6rem; border-radius: 999px; }
				a.srt-results-badge { text-decoration: none; cursor: pointer; }
				a.srt-results-badge:hover, a.srt-results-badge:focus { text-decoration: underline; }
				.srt-results-badge-completed { background: #d4edda; color: #1e7e34; }
				.srt-results-badge-ready { background: #d6e4ff; color: #1c4e9c; }
				.srt-results-badge-overdue { background: #f8d7da; color: #a71d2a; }
				.srt-results-badge-needs_work { background: #fff3cd; color: #8a6500; }
				.srt-results-status-ok { color: #1e7e34; white-space: nowrap; }
				.srt-results-status-attention { color: #a71d2a; font-weight: 600; white-space: nowrap; }
			</style>

			<form method="get">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( SRT_CPT_Company::POST_TYPE ); ?>" />
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<table class="form-table">
					<tr>
						<th scope="row"><label for="srt_filter_company"><?php esc_html_e( 'Company', 'rw-site-review-tasks' ); ?></label></th>
						<td>
							<select name="srt_filter_company" id="srt_filter_company">
								<option value="0"><?php esc_html_e( 'All companies', 'rw-site-review-tasks' ); ?></option>
								<?php foreach ( $companies as $company ) : ?>
									<option value="<?php echo esc_attr( $company->ID ); ?>" <?php selected( $filters['company_id'], $company->ID ); ?>><?php echo esc_html( get_the_title( $company ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srt_filter_status"><?php esc_html_e( 'Status', 'rw-site-review-tasks' ); ?></label></th>
						<td>
							<select name="srt_filter_status" id="srt_filter_status">
								<option value=""><?php esc_html_e( 'All statuses', 'rw-site-review-tasks' ); ?></option>
								<?php foreach ( array( 'completed', 'needs_work', 'overdue', 'ready' ) as $slug ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['status'], $slug ); ?>><?php echo esc_html( srt_get_task_tag_label( $slug ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="srt_filter_from"><?php esc_html_e( 'Period from', 'rw-site-review-tasks' ); ?></label></th>
						<td><input type="date" name="srt_filter_from" id="srt_filter_from" value="<?php echo esc_attr( $filters['from'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="srt_filter_to"><?php esc_html_e( 'Period to', 'rw-site-review-tasks' ); ?></label></th>
						<td>
							<input type="date" name="srt_filter_to" id="srt_filter_to" value="<?php echo esc_attr( $filters['to'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Filters and export both use the task\'s Period — the due date the review is for — not when the task was created.', 'rw-site-review-tasks' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'rw-site-review-tasks' ); ?></button>
					<a class="button" href="<?php echo esc_url( $page_url ); ?>"><?php esc_html_e( 'Reset', 'rw-site-review-tasks' ); ?></a>
					<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'rw-site-review-tasks' ); ?></a>
				</p>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Company', 'rw-site-review-tasks' ); ?></th>
						<th><?php esc_html_e( 'Period', 'rw-site-review-tasks' ); ?></th>
						<th><?php esc_html_e( 'Status', 'rw-site-review-tasks' ); ?></th>
						<th><?php esc_html_e( 'Maker', 'rw-site-review-tasks' ); ?></th>
						<th><?php esc_html_e( 'Marketing Guide', 'rw-site-review-tasks' ); ?></th>
						<th><?php esc_html_e( 'Created', 'rw-site-review-tasks' ); ?></th>
						<th><?php esc_html_e( 'Core', 'rw-site-review-tasks' ); ?></th>
						<th><?php esc_html_e( 'Plugins', 'rw-site-review-tasks' ); ?></th>
						<th><?php esc_html_e( 'Theme', 'rw-site-review-tasks' ); ?></th>
						<th><?php esc_html_e( 'PHP', 'rw-site-review-tasks' ); ?></th>
						<th><?php esc_html_e( 'Last Backup', 'rw-site-review-tasks' ); ?></th>
						<?php for ( $i = 1; $i <= self::GTMETRIX_MAX_PAGES; $i++ ) : ?>
							<th><?php echo esc_html( sprintf( 'Page%d', $i ) ); ?></th>
						<?php endfor; ?>
						<th><?php esc_html_e( 'Task', 'rw-site-review-tasks' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="<?php echo esc_attr( 7 + 4 + 1 + self::GTMETRIX_MAX_PAGES + 1 ); ?>"><?php esc_html_e( 'No tasks match these filters.', 'rw-site-review-tasks' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td>
									<?php if ( $row['company_id'] ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $row['company_id'] ) ); ?>"><?php echo esc_html( $row['company_name'] ); ?></a>
									<?php else : ?>
										&#8212;
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $row['period'] ); ?></td>
								<td>
								<?php $task_url = srt_task_page_url( $row['task_id'] ); ?>
								<?php if ( $task_url ) : ?>
									<a href="<?php echo esc_url( $task_url ); ?>" class="srt-results-badge srt-results-badge-<?php echo esc_attr( $row['status_slug'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['status_label'] ); ?></a>
								<?php else : ?>
									<span class="srt-results-badge srt-results-badge-<?php echo esc_attr( $row['status_slug'] ); ?>"><?php echo esc_html( $row['status_label'] ); ?></span>
								<?php endif; ?>
							</td>
								<td><?php echo esc_html( $row['maker_name'] ); ?></td>
								<td><?php echo esc_html( $row['mg_name'] ); ?></td>
								<td><?php echo esc_html( $row['created'] ); ?></td>
								<?php foreach ( array( 'security_core', 'security_plugins', 'security_theme', 'security_php' ) as $field ) : ?>
									<td>
										<?php if ( null === $row[ $field ] ) : ?>
											&#8212;
										<?php else : ?>
											<span class="srt-results-status-<?php echo esc_attr( $row[ $field ]['status'] ); ?>"><?php echo esc_html( $row[ $field ]['detail'] ); ?></span>
										<?php endif; ?>
									</td>
								<?php endforeach; ?>
								<td><?php echo $row['last_backup'] ? esc_html( $row['last_backup'] ) : '&#8212;'; ?></td>
								<?php foreach ( $row['gtmetrix_pages'] as $value ) : ?>
									<td><?php echo null === $value ? '' : esc_html( $value ); ?></td>
								<?php endforeach; ?>
								<td><a href="<?php echo esc_url( get_edit_post_link( $row['task_id'] ) ); ?>">#<?php echo esc_html( $row['task_id'] ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<p class="description">
				<?php
				printf(
					/* translators: %d: number of matching tasks */
					esc_html__( '%d task(s) match the current filters.', 'rw-site-review-tasks' ),
					count( $rows )
				);
				?>
			</p>
		</div>
		<?php
	}

	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export this data.', 'rw-site-review-tasks' ) );
		}

		check_admin_referer( self::EXPORT_ACTION );

		$filters = self::filters_from_request();
		$rows    = self::query_results( $filters );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="srt-task-results-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$headers = array( 'Task ID', 'Company', 'Period', 'Status', 'Maker', 'Marketing Guide', 'Created', 'Core', 'Plugins', 'Theme', 'PHP', 'Last Backup' );
		for ( $i = 1; $i <= self::GTMETRIX_MAX_PAGES; $i++ ) {
			$headers[] = sprintf( 'Page%d', $i );
		}
		$headers[] = 'Task Edit URL';

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, $headers );

		foreach ( $rows as $row ) {
			$line = array(
				$row['task_id'],
				$row['company_name'],
				$row['period'],
				$row['status_label'],
				$row['maker_name'],
				$row['mg_name'],
				$row['created'],
				self::security_field_text( $row['security_core'] ),
				self::security_field_text( $row['security_plugins'] ),
				self::security_field_text( $row['security_theme'] ),
				self::security_field_text( $row['security_php'] ),
				$row['last_backup'],
			);

			foreach ( $row['gtmetrix_pages'] as $value ) {
				$line[] = null === $value ? '' : $value;
			}

			$line[] = get_edit_post_link( $row['task_id'], 'raw' );

			fputcsv( $out, $line );
		}

		fclose( $out );
		exit;
	}

	/**
	 * A security_fields() entry as one CSV-friendly string — '' when there's
	 * no data yet, otherwise the version/update-count detail text.
	 */
	private static function security_field_text( $field ) {
		return null === $field ? '' : $field['detail'];
	}
}

SRT_Results_Admin::init();
