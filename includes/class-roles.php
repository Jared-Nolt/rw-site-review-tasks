<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Marketing Guide role.
 *
 * Deliberately minimal: `read` and nothing else. It exists so a marketing guide
 * can hold a login without gaining any authority over the rest of the site — no
 * posts, no pages, no media, no settings. In wp-admin they see only the WordPress
 * dashboard and their own profile.
 *
 * Their access to review work does not come from this role. It comes from being
 * named in a company's or task's Marketing Guide field, which
 * srt_user_can_access_task() checks by assignment rather than by capability. That
 * means the role is optional — an existing Subscriber assigned as a marketing
 * guide already works — but giving them this role makes the intent legible on the
 * Users screen and keeps them off the Subscriber pile.
 */
class SRT_Roles {

	const ROLE = 'srt_marketing_guide';

	const VERSION_OPT = 'srt_roles_version';
	const VERSION     = 1;

	public static function init() {
		// Covers updates where the plugin was never deactivated, so the activation
		// hook doesn't run. Guarded by a version option: add_role() writes to the
		// options table, so this must not fire on every request.
		add_action( 'init', array( __CLASS__, 'maybe_install' ) );
	}

	public static function maybe_install() {
		if ( (int) get_option( self::VERSION_OPT, 0 ) === self::VERSION ) {
			return;
		}

		self::install();
		update_option( self::VERSION_OPT, self::VERSION );
	}

	/**
	 * Creates the role, or repairs its capabilities if it already exists. Safe to
	 * run repeatedly — add_role() no-ops on an existing role, so the capabilities
	 * are then applied explicitly.
	 */
	public static function install() {
		$capabilities = self::capabilities();

		add_role( self::ROLE, self::label(), $capabilities );

		$role = get_role( self::ROLE );

		if ( ! $role ) {
			return;
		}

		foreach ( $capabilities as $capability => $granted ) {
			if ( $granted ) {
				$role->add_cap( $capability );
			}
		}
	}

	/**
	 * Not called on deactivation on purpose: removing the role would strip every
	 * assigned marketing guide of their login and silently orphan them. Remove it
	 * by hand if the plugin is being retired for good.
	 */
	public static function capabilities() {
		return array(
			'read' => true,
		);
	}

	public static function label() {
		return __( 'Marketing Guide', 'rw-site-review-tasks' );
	}
}

SRT_Roles::init();
