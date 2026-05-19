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

		// /out/{id} redirect lives on both front-end and back-end; register
		// the rewrite rule + query var early so WP_Rewrite knows about it.
		add_action( 'init', array( 'Supcomp_Redirect', 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( 'Supcomp_Redirect', 'add_query_vars' ) );
		add_action( 'template_redirect', array( 'Supcomp_Redirect', 'maybe_handle' ) );

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

		// class-affiliate-url-template, class-offers-repo, class-clicks-repo
		// and class-redirect are already loaded by supplement-compare.php
		// (activator needs them before plugins_loaded fires).

		require_once $inc . 'db/class-merchants-repo.php';
		require_once $inc . 'db/class-ingredients-repo.php';
		require_once $inc . 'db/class-canonical-products-repo.php';
		require_once $inc . 'db/class-import-runs-repo.php';
		require_once $inc . 'db/class-price-history-repo.php';

		require_once $inc . 'import/class-canonical-csv-importer.php';
		require_once $inc . 'import/class-csv-validator.php';
		require_once $inc . 'import/class-stale-detector.php';

		require_once $inc . 'normalization/rules/class-strength-rule.php';
		require_once $inc . 'normalization/rules/class-count-rule.php';
		require_once $inc . 'normalization/rules/class-form-rule.php';
		require_once $inc . 'normalization/rules/class-standardization-rule.php';
		require_once $inc . 'normalization/class-normalizer.php';
		require_once $inc . 'normalization/class-matcher.php';
		require_once $inc . 'normalization/class-offer-derivations.php';

		require_once $inc . 'import/class-csv-importer.php';
	}

	private static function load_admin() {
		$admin_dir = SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/admin/';

		require_once $admin_dir . 'class-admin.php';
		require_once $admin_dir . 'class-settings.php';
		require_once $admin_dir . 'class-offer-form.php';
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
		Supcomp_Import_Screen::register_hooks();
		Supcomp_Pending_Queue_Screen::register_hooks();
		Supcomp_Offer_Form::register_hooks();
	}
}
