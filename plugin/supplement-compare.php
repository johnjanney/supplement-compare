<?php
/**
 * Plugin Name:       Supplement Compare
 * Plugin URI:        https://example.invalid/
 * Description:       Single-ingredient supplement affiliate comparison engine: CSV import, normalization, curation queue, click tracking, and static JSON export.
 * Version:           1.35.1
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Cornflower
 * License:           Proprietary
 * Text Domain:       supplement-compare
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SUPPLEMENT_COMPARE_VERSION', '1.35.1' );
define( 'SUPPLEMENT_COMPARE_PLUGIN_FILE', __FILE__ );
define( 'SUPPLEMENT_COMPARE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Action Scheduler — vendored (GPLv3, github.com/woocommerce/action-scheduler).
// Loaded before our classes so any plugin file that calls as_enqueue_async_action
// or hooks into Action Scheduler can do so safely. AS uses a version-arbitration
// shim: if a later-loading plugin also bundles AS, the highest-versioned copy
// wins, so vendoring is safe alongside WooCommerce or other AS consumers.
require_once SUPPLEMENT_COMPARE_PLUGIN_DIR . 'vendor/action-scheduler/action-scheduler.php';

require_once SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/class-installer.php';
// Activator needs Supcomp_Redirect to register the rewrite rule before flush.
// Bundle the minimal redirect chain here so activation works on a fresh
// install before plugins_loaded fires.
require_once SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/class-affiliate-url-template.php';
require_once SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/db/class-offers-repo.php';
require_once SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/db/class-clicks-repo.php';
require_once SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/public/class-redirect.php';
require_once SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/db/class-canonical-products-repo.php';
require_once SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/db/class-ingredients-repo.php';
require_once SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/public/class-json-exporter.php';
require_once SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/public/class-shortcode.php';
require_once SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/public/class-canonical-page.php';
require_once SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/public/class-sitemap.php';
require_once SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/class-activator.php';
require_once SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/class-deactivator.php';
require_once SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Supcomp_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Supcomp_Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Supcomp_Plugin', 'boot' ) );
