<?php
/**
 * Plugin Name: RW Site Review Tasks
 * Description: Auto-creates a monthly website review task per company, assigned to a maker, with a checklist, comments, screenshots, and a front-end maker dashboard.
 * Version: 0.2.3
 * Author: Rosewood Dev
 * Author URI: https://github.com/Jared-Nolt/rw-site-review-tasks
 * Text Domain: rw-site-review-tasks
 * Requires Plugins: advanced-custom-fields-pro
 */

if ( ! defined( 'ABSPATH' ) ) {
		exit;
}

define( 'SRT_PLUGIN_FILE', __FILE__ );
define( 'SRT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SRT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SRT_PLUGIN_DIR . 'includes/class-cpt-company.php';
require_once SRT_PLUGIN_DIR . 'includes/class-cpt-task.php';
require_once SRT_PLUGIN_DIR . 'includes/class-cpt-checklist.php';
require_once SRT_PLUGIN_DIR . 'includes/class-acf-fields.php';
require_once SRT_PLUGIN_DIR . 'includes/class-roles.php';
require_once SRT_PLUGIN_DIR . 'includes/class-mailer.php';
require_once SRT_PLUGIN_DIR . 'includes/class-cron.php';
require_once SRT_PLUGIN_DIR . 'includes/class-site-scanner.php';
require_once SRT_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once SRT_PLUGIN_DIR . 'includes/class-import.php';
require_once SRT_PLUGIN_DIR . 'includes/class-maker-dashboard.php';
require_once SRT_PLUGIN_DIR . 'includes/class-task-frontend.php';
require_once SRT_PLUGIN_DIR . 'includes/class-task-print.php';

/**
 * Admin notice if ACF Pro isn't active — every field group here depends on it.
 */
function srt_admin_notice_missing_acf() {
	if ( ! class_exists( 'ACF' ) ) {
		echo '<div class="notice notice-error"><p>' .
			esc_html__( 'RW Site Review Tasks requires Advanced Custom Fields PRO to be active.', 'rw-site-review-tasks' ) .
			'</p></div>';
	}
}
add_action( 'admin_notices', 'srt_admin_notice_missing_acf' );

function srt_activate() {
	SRT_Roles::install();
	update_option( SRT_Roles::VERSION_OPT, SRT_Roles::VERSION );
	SRT_Cron::schedule();
}
register_activation_hook( __FILE__, 'srt_activate' );

function srt_deactivate() {
	SRT_Cron::unschedule();
}
register_deactivation_hook( __FILE__, 'srt_deactivate' );
