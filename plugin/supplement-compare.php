<?php
/**
 * Plugin Name:       Supplement Compare
 * Plugin URI:        https://example.invalid/
 * Description:       Single-ingredient supplement affiliate comparison engine: CSV import, normalization, curation queue, click tracking, and static JSON export.
 * Version:           0.1.0
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

define( 'SUPPLEMENT_COMPARE_VERSION', '0.1.0' );
define( 'SUPPLEMENT_COMPARE_PLUGIN_FILE', __FILE__ );
define( 'SUPPLEMENT_COMPARE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

register_activation_hook( __FILE__, 'supcomp_activate' );

/**
 * Plugin activation handler.
 *
 * Phase 0 placeholder. Database table creation lands in Phase 1.
 */
function supcomp_activate() {
	// Intentionally empty for Phase 0.
}
