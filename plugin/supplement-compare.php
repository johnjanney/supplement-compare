<?php
/**
 * Plugin Name:       Supplement Compare
 * Plugin URI:        https://example.invalid/
 * Description:       Single-ingredient supplement affiliate comparison engine: CSV import, normalization, curation queue, click tracking, and static JSON export.
 * Version:           0.7.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Janney Solutions LLC
 * License:           TBD
 * Text Domain:       supplement-compare
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SUPPLEMENT_COMPARE_VERSION', '0.7.0' );
define( 'SUPPLEMENT_COMPARE_PLUGIN_FILE', __FILE__ );
define( 'SUPPLEMENT_COMPARE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/class-installer.php';
require_once SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/class-activator.php';
require_once SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/class-deactivator.php';
require_once SUPPLEMENT_COMPARE_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Supcomp_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Supcomp_Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Supcomp_Plugin', 'boot' ) );
