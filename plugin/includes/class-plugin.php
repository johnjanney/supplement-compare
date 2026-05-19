<?php
/**
 * Plugin bootstrap.
 *
 * Loaded on the `plugins_loaded` action. Always loads domain classes (repos +
 * shared importer). Loads admin classes only when in admin context, and lets
 * each admin screen register its own admin_post_* hooks.
 *
 * Public-facing classes (shortcode, /out/ redirect, JSON exporter) load later
 * as their phases arrive.
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

		self::load_domain();

		if ( is_admin() ) {
			self::load_admin();
		}
	}

	/**
	 * Domain classes (data access + import). Always loaded; admin-post.php is
	 * technically an admin endpoint, but its hooks need these classes by name
	 * before is_admin() context fully sets up.
	 */
	private static function load_domain() {
		$inc = SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/';

		require_once $inc . 'class-affiliate-url-template.php';

		require_once $inc . 'db/class-merchants-repo.php';
		require_once $inc . 'db/class-ingredients-repo.php';
		require_once $inc . 'db/class-canonical-products-repo.php';

		require_once $inc . 'import/class-canonical-csv-importer.php';
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

		// Each screen that handles form submissions registers its own
		// admin_post_* and admin-ajax / admin_enqueue_scripts hooks. Screens
		// without POST handlers omit register_hooks().
		Supcomp_Merchants_Screen::register_hooks();
		Supcomp_Ingredients_Screen::register_hooks();
		Supcomp_Canonical_Products_Screen::register_hooks();
	}
}
