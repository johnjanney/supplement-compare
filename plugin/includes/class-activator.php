<?php
/**
 * Activation handler. Creates the database schema and seeds default options.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Activator {

	public static function activate() {
		Supcomp_Installer::install();
		Supcomp_Installer::seed_default_options();
	}
}
