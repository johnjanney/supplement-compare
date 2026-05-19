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
		// Intentionally empty.
	}
}
