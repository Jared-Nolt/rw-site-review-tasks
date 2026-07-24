<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRT_CPT_Checklist {

	const POST_TYPE = 'rwsrt_checklist';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Checklists', 'rw-site-review-tasks' ),
					'singular_name' => __( 'Checklist', 'rw-site-review-tasks' ),
					'add_new_item'  => __( 'Add New Checklist', 'rw-site-review-tasks' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . SRT_CPT_Company::POST_TYPE,
				'menu_icon'       => 'dashicons-list-view',
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
			)
		);
	}
}

SRT_CPT_Checklist::init();
