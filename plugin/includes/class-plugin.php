<?php
/**
 * Plugin bootstrap.
 *
 * Loaded on the `plugins_loaded` action. Wires admin classes when in admin
 * context. Public-facing classes (shortcode, /out/ redirect, JSON exporter)
 * load later as their phases arrive.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Plugin {

	public static function boot() {
		load_plugin_textdomain(
			'supplement-compare',
			false,
			dirname( plugin_basename( SUPPLEMENT_COMPARE_PLUGIN_FILE ) ) . '/languages'
		);

		// Re-runs install() if the plugin was updated without a deactivate/
		// reactivate cycle. Idempotent when up to date.
		Supcomp_Installer::maybe_upgrade();

		if ( is_admin() ) {
			self::load_admin();
		}
	}

	private static function load_admin() {
		$admin_dir = SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/admin/';

		require_once $admin_dir . 'class-admin.php';
		require_once $admin_dir . 'class-settings.php';
		require_once $admin_dir . 'class-merchants-screen.php';
		require_once $admin_dir . 'class-ingredients-screen.php';
		require_once $admin_dir . 'class-canonical-products-screen.php';
		require_once $admin_dir . 'class-import-screen.php';
		require_once $admin_dir . 'class-pending-queue-screen.php';
		require_once $admin_dir . 'class-active-offers-screen.php';
		require_once $admin_dir . 'class-clicks-screen.php';

		add_action( 'admin_menu', array( 'Supcomp_Admin', 'register_menu' ) );
		add_action( 'admin_init', array( 'Supcomp_Settings', 'register' ) );
	}
}
