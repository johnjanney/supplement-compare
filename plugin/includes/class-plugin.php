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

		// /out/{id} redirect + /compare/{slug}/ canonical page + sitemap
		// rewrite rules. Register on init so WP_Rewrite knows about them
		// even after a transient flush.
		add_action( 'init', array( 'Supcomp_Redirect', 'register_rewrite_rules' ) );
		add_action( 'init', array( 'Supcomp_Canonical_Page', 'register_rewrite_rules' ) );
		add_action( 'init', array( 'Supcomp_Sitemap', 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( 'Supcomp_Redirect', 'add_query_vars' ) );
		add_filter( 'query_vars', array( 'Supcomp_Canonical_Page', 'add_query_vars' ) );
		add_filter( 'query_vars', array( 'Supcomp_Sitemap', 'add_query_vars' ) );

		add_action( 'template_redirect', array( 'Supcomp_Redirect', 'maybe_handle' ) );
		add_action( 'template_redirect', array( 'Supcomp_Sitemap', 'maybe_handle' ) );
		add_filter( 'template_include', array( 'Supcomp_Canonical_Page', 'maybe_render' ), 99 );

		// Phase 8: public JSON cache invalidation listener + hourly cron.
		Supcomp_JSON_Exporter::register_hooks();

		// Phase 9: public-facing [supplement_compare] shortcode.
		Supcomp_Shortcode::register();

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
		require_once $inc . 'db/class-extract-sites-repo.php';
		require_once $inc . 'db/class-extract-runs-repo.php';

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

		require_once $inc . 'extractor/class-extractor-http.php';
		require_once $inc . 'extractor/class-extractor-offer.php';
		require_once $inc . 'extractor/class-extractor-shopify.php';
		require_once $inc . 'extractor/class-extractor-woo.php';
		require_once $inc . 'extractor/class-extractor-generic.php';
		require_once $inc . 'extractor/class-extractor-worker.php';
		require_once $inc . 'extractor/class-extractor.php';

		require_once $inc . 'deletion/class-deletion-service.php';

		// AS dispatches on its own hook from a queue runner request — register
		// the callback unconditionally (not gated on is_admin) so cron-fired
		// pages can land regardless of where AS is run from.
		Supcomp_Extractor_Worker::register_hooks();
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
		require_once $admin_dir . 'class-extract-sites-screen.php';
		require_once $admin_dir . 'class-deletion-admin.php';
		require_once $admin_dir . 'class-cleanup-screen.php';

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
		Supcomp_Settings::register_hooks();
		Supcomp_Extract_Sites_Screen::register_hooks();
		Supcomp_Deletion_Admin::register_hooks();
		Supcomp_Cleanup_Screen::register_hooks();
	}
}
