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
				$user_id = get_field( 'maker', $post_id );
				$user    = $user_id ? get_userdata( is_array( $user_id ) ? reset( $user_id ) : $user_id ) : null;
				echo $user ? esc_html( $user->display_name ) : '&#8212;';
				break;

			case 'srt_period':
				echo esc_html( get_field( 'period', $post_id ) );
				break;

			case 'srt_tag':
				echo esc_html( srt_get_task_tag( $post_id ) );
				break;
		}
	}
}

SRT_CPT_Task::init();
