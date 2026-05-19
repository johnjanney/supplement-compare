<?php
/**
 * Deactivation handler.
 *
 * No-op by design: deactivation should never destroy operator data. If the
 * operator deletes the plugin from the Plugins screen entirely, uninstall.php
 * handles teardown (currently also a no-op; see that file's docblock).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Deactivator {

	public static function deactivate() {
		// Drop our rewrite rules so URL routing returns to defaults
		// without leaving stale entries in .htaccess / permalink state.
		flush_rewrite_rules();
	}
}
