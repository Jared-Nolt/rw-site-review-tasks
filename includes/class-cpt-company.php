<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRT_CPT_Company {

	const POST_TYPE = 'rwsrt_company';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'remove_add_new_submenu' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_tasks_meta_box' ) );
		add_action( 'admin_post_srt_run_company', array( __CLASS__, 'handle_run_company' ) );
		add_action( 'admin_notices', array( __CLASS__, 'run_company_notice' ) );
	}

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Companies', 'rw-site-review-tasks' ),
					'singular_name' => __( 'Company', 'rw-site-review-tasks' ),
					'add_new_item'  => __( 'Add New Company', 'rw-site-review-tasks' ),
					'all_items'     => __( 'Companies', 'rw-site-review-tasks' ),
					'menu_name'     => __( 'RW Site Review Tasks', 'rw-site-review-tasks' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-building',
				'supports'     => array( 'title' ),
				'capability_type' => 'post',
			)
		);
	}

	/**
	 * Keep the "Add New" page-title-action button on the Companies list screen,
	 * but drop the separate "Add New Company" sidebar submenu link.
	 */
	public static function remove_add_new_submenu() {
		remove_submenu_page( 'edit.php?post_type=' . self::POST_TYPE, 'post-new.php?post_type=' . self::POST_TYPE );
	}

	public static function columns( $columns ) {
		$columns['srt_maker']           = __( 'Maker', 'rw-site-review-tasks' );
		$columns['srt_marketing_guide'] = __( 'Marketing Guide', 'rw-site-review-tasks' );
		$columns['srt_checklist']       = __( 'Checklist', 'rw-site-review-tasks' );
		$columns['srt_interval']  = __( 'Interval', 'rw-site-review-tasks' );
		$columns['srt_due_date']  = __( 'Next Due', 'rw-site-review-tasks' );
		$columns['srt_active']    = __( 'Active', 'rw-site-review-tasks' );
		return $columns;
	}

	public static function render_column( $column, $post_id ) {
		if ( 'srt_maker' === $column ) {
			$user_id = get_field( 'maker', $post_id );
			if ( $user_id ) {
				$user = get_userdata( is_array( $user_id ) ? reset( $user_id ) : $user_id );
				echo $user ? esc_html( $user->display_name ) : '&#8212;';
			} else {
				echo '&#8212;';
			}
		}

		if ( 'srt_marketing_guide' === $column ) {
			$user_id = get_field( 'marketing_guide', $post_id );
			if ( $user_id ) {
				$user = get_userdata( is_array( $user_id ) ? reset( $user_id ) : $user_id );
				echo $user ? esc_html( $user->display_name ) : '&#8212;';
			} else {
				echo '&#8212;';
			}
		}

		if ( 'srt_checklist' === $column ) {
			$checklist_id = get_field( 'checklist_template', $post_id );
			echo $checklist_id ? esc_html( get_the_title( $checklist_id ) ) : '&#8212;';
		}

		if ( 'srt_interval' === $column ) {
			$choices  = array(
				'monthly'       => __( 'Monthly', 'rw-site-review-tasks' ),
				'quarterly'     => __( 'Quarterly', 'rw-site-review-tasks' ),
				'semi_annually' => __( 'Semi-Annually', 'rw-site-review-tasks' ),
				'annually'      => __( 'Annually', 'rw-site-review-tasks' ),
			);
			$interval = get_field( 'interval', $post_id );
			echo isset( $choices[ $interval ] ) ? esc_html( $choices[ $interval ] ) : '&#8212;';
		}

		if ( 'srt_due_date' === $column ) {
			$due_date = get_field( 'due_date', $post_id );
			echo $due_date ? esc_html( $due_date ) : '&#8212;';
		}

		if ( 'srt_active' === $column ) {
			echo get_field( 'active', $post_id ) ? esc_html__( 'Yes', 'rw-site-review-tasks' ) : esc_html__( 'No', 'rw-site-review-tasks' );
		}
	}

	public static function add_tasks_meta_box() {
		add_meta_box(
			'srt_company_tasks',
			__( 'Review Tasks', 'rw-site-review-tasks' ),
			array( __CLASS__, 'render_tasks_meta_box' ),
			self::POST_TYPE,
			'normal',
			'low'
		);
	}

	public static function render_tasks_meta_box( $post ) {
		self::render_run_now_form( $post->ID );

		$tasks = get_posts(
			array(
				'post_type'      => SRT_CPT_Task::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'meta_key'       => 'period',
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => 'company',
						'value' => $post->ID,
					),
				),
			)
		);

		if ( ! $tasks ) {
			echo '<p>' . esc_html__( 'No review tasks yet.', 'rw-site-review-tasks' ) . '</p>';
			return;
		}

		// Build a task_id → log entry map so we can show creation time without looping the log per task.
		$log     = array_reverse( get_option( SRT_Cron::LOG_OPT, array() ) );
		$log_map = array();
		foreach ( $log as $entry ) {
			$tid = isset( $entry['task_id'] ) ? (int) $entry['task_id'] : 0;
			if ( $tid && ! isset( $log_map[ $tid ] ) ) {
				$log_map[ $tid ] = $entry;
			}
		}

		$log_page_url = add_query_arg(
			array(
				'post_type' => self::POST_TYPE,
				'page'      => SRT_Admin_Settings::PAGE_SLUG,
			),
			admin_url( 'edit.php' )
		);
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Due', 'rw-site-review-tasks' ); ?></th>
					<th><?php esc_html_e( 'Status', 'rw-site-review-tasks' ); ?></th>
					<th><?php esc_html_e( 'Created', 'rw-site-review-tasks' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'rw-site-review-tasks' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $tasks as $task ) : ?>
					<?php $log_entry = isset( $log_map[ $task->ID ] ) ? $log_map[ $task->ID ] : null; ?>
					<tr>
						<td><?php echo esc_html( get_field( 'period', $task->ID ) ); ?></td>
						<td><?php echo esc_html( srt_get_task_tag( $task->ID ) ); ?></td>
						<td>
							<?php if ( $log_entry ) : ?>
								<a href="<?php echo esc_url( $log_page_url . '#srt-log-' . $task->ID ); ?>">
									<?php echo esc_html( $log_entry['time'] ); ?>
								</a>
							<?php else : ?>
								&#8212;
							<?php endif; ?>
						</td>
						<td>
							<a href="<?php echo esc_url( get_edit_post_link( $task->ID ) ); ?>"><?php esc_html_e( 'Edit', 'rw-site-review-tasks' ); ?></a>
							&nbsp;|&nbsp;
							<a href="<?php echo esc_url( SRT_Task_Print::url( $task->ID ) ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Download PDF', 'rw-site-review-tasks' ); ?>
							</a>
							&nbsp;|&nbsp;
							<a href="<?php echo esc_url( SRT_Task_Print::mg_url( $task->ID ) ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Download PDF (MG)', 'rw-site-review-tasks' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p style="margin:8px 0 0;">
			<a href="<?php echo esc_url( $log_page_url . '#srt-run-log' ); ?>"><?php esc_html_e( '&#8594; View full run log', 'rw-site-review-tasks' ); ?></a>
		</p>
		<?php
	}

	/**
	 * "Create review task now" button for this one company — the per-company
	 * replacement for the old site-wide "Run now" button on the settings page.
	 */
	private static function render_run_now_form( $company_id ) {
		// A nonced link (not a <form>): this meta box renders inside WordPress's
		// main post-edit <form>, and a nested form would hijack the outer form's
		// submit — breaking the Update button.
		$run_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'srt_run_company',
					'company_id' => $company_id,
				),
				admin_url( 'admin-post.php' )
			),
			'srt_run_company_' . $company_id
		);
		?>
		<p style="margin:0 0 14px;">
			<a href="<?php echo esc_url( $run_url ); ?>" class="button button-secondary">
				<?php esc_html_e( 'Create review task now (ignores due/lead-day timing)', 'rw-site-review-tasks' ); ?>
			</a>
			<span class="description" style="display:block;margin-top:4px;">
				<?php esc_html_e( 'Creates a task immediately for this company\'s current due date if one doesn\'t already exist, then advances the due date. Requires a Next Due Date to be set.', 'rw-site-review-tasks' ); ?>
			</span>
		</p>
		<?php
	}

	/**
	 * Handles the per-company "Create review task now" submission, then redirects
	 * back to the company edit screen with a result notice.
	 */
	public static function handle_run_company() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'rw-site-review-tasks' ), '', array( 'response' => 403 ) );
		}

		$company_id = isset( $_REQUEST['company_id'] ) ? absint( $_REQUEST['company_id'] ) : 0;

		check_admin_referer( 'srt_run_company_' . $company_id );

		if ( ! $company_id || self::POST_TYPE !== get_post_type( $company_id ) ) {
			wp_die( esc_html__( 'Company not found.', 'rw-site-review-tasks' ), '', array( 'response' => 404 ) );
		}

		$result = SRT_Cron::run_for_company( $company_id, true );

		wp_safe_redirect(
			add_query_arg( 'srt_ran', $result['result'], get_edit_post_link( $company_id, 'raw' ) )
		);
		exit;
	}

	/**
	 * Shows the outcome of a per-company run on the company edit screen.
	 */
	public static function run_company_notice() {
		if ( empty( $_GET['srt_ran'] ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || self::POST_TYPE !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}

		$result = sanitize_key( wp_unslash( $_GET['srt_ran'] ) );

		$messages = array(
			'created'        => array( 'success', __( 'Review task created for this company.', 'rw-site-review-tasks' ) ),
			'skipped_exists' => array( 'warning', __( 'A review task already exists for this company\'s current due date.', 'rw-site-review-tasks' ) ),
			'not_scheduled'  => array( 'warning', __( 'Set a Next Due Date on this company before creating a task.', 'rw-site-review-tasks' ) ),
			'error'          => array( 'error', __( 'Could not create the review task.', 'rw-site-review-tasks' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $result ][0] ),
			esc_html( $messages[ $result ][1] )
		);
	}
}

SRT_CPT_Company::init();
