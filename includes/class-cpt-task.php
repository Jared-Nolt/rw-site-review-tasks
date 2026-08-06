<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRT_CPT_Task {

	const POST_TYPE = 'rwsrt_task';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_checklist_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_checklist' ), 20 );
	}

	/**
	 * Checklist meta box for a Task — Section and Label are shown as
	 * reference text (they're a locked snapshot from the company at creation
	 * time), only Answer is an editable field. Not an ACF field: see
	 * srt_get_checklist() / srt_update_checklist() in class-acf-fields.php.
	 */
	public static function add_checklist_meta_box() {
		add_meta_box(
			'srt_task_checklist',
			__( 'Checklist', 'rw-site-review-tasks' ),
			array( __CLASS__, 'render_checklist_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	public static function render_checklist_meta_box( $post ) {
		$rows = srt_get_checklist( $post->ID );

		wp_nonce_field( 'srt_save_task_checklist', 'srt_task_checklist_nonce' );

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No checklist items on this task.', 'rw-site-review-tasks' ) . '</p>';
			return;
		}
		?>
		<p class="description"><?php esc_html_e( 'Snapshotted from the company\'s checklist when this task was created. Section and Label are shown for reference only; only Answer is editable here.', 'rw-site-review-tasks' ); ?></p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Section', 'rw-site-review-tasks' ); ?></th>
					<th><?php esc_html_e( 'Label', 'rw-site-review-tasks' ); ?></th>
					<th><?php esc_html_e( 'Answer', 'rw-site-review-tasks' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $i => $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['section'] ); ?></td>
						<td><?php echo esc_html( $row['label'] ); ?></td>
						<td><textarea name="srt_checklist_answer[<?php echo esc_attr( $i ); ?>]" rows="3" class="widefat"><?php echo esc_textarea( $row['answer'] ); ?></textarea></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Saves only the Answer column — Section/Label/row count come from the
	 * company snapshot at creation and are never editable here.
	 */
	public static function save_checklist( $post_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['srt_task_checklist_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['srt_task_checklist_nonce'] ), 'srt_save_task_checklist' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$rows = srt_get_checklist( $post_id );

		if ( ! $rows ) {
			return;
		}

		$posted = isset( $_POST['srt_checklist_answer'] ) ? (array) wp_unslash( $_POST['srt_checklist_answer'] ) : array();

		foreach ( $rows as $i => &$row ) {
			$row['answer'] = isset( $posted[ $i ] ) ? sanitize_textarea_field( $posted[ $i ] ) : '';
		}
		unset( $row );

		srt_update_checklist( $post_id, $rows );
	}

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Review Tasks', 'rw-site-review-tasks' ),
					'singular_name' => __( 'Review Task', 'rw-site-review-tasks' ),
					'add_new_item'  => __( 'Add New Review Task', 'rw-site-review-tasks' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'menu_icon'       => 'dashicons-clipboard',
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'capabilities'    => srt_editor_post_type_capabilities(),
			)
		);
	}

	public static function columns( $columns ) {
		$columns['srt_company'] = __( 'Company', 'rw-site-review-tasks' );
		$columns['srt_maker']   = __( 'Maker', 'rw-site-review-tasks' );
		$columns['srt_period']  = __( 'Due', 'rw-site-review-tasks' );
		$columns['srt_tag']     = __( 'Status', 'rw-site-review-tasks' );
		return $columns;
	}

	public static function render_column( $column, $post_id ) {
		switch ( $column ) {
			case 'srt_company':
				$company_id = get_field( 'company', $post_id );
				echo $company_id ? esc_html( get_the_title( $company_id ) ) : '&#8212;';
				break;

			case 'srt_maker':
				$user_id = srt_get_assigned_user_id( 'maker', $post_id );
				$user    = $user_id ? get_userdata( $user_id ) : null;
				echo $user ? esc_html( $user->display_name ) : '&#8212;';
				break;

			case 'srt_period':
				echo esc_html( (string) get_field( 'period', $post_id ) );
				break;

			case 'srt_tag':
				echo esc_html( srt_get_task_tag( $post_id ) );
				break;
		}
	}
}

SRT_CPT_Task::init();
