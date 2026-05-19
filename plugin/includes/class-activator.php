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

		// Register the /out/{id} rewrite rule and flush so .htaccess /
		// permalink state picks it up immediately. Re-flushing on every
		// page load would be expensive, so we only do it here.
		Supcomp_Redirect::register_rewrite_rules();
		flush_rewrite_rules();

		// Phase 8: schedule the hourly public-JSON regeneration cron.
		// Don't generate immediately here — the install just ran and there
		// are no offers to publish yet. The first real generate fires after
		// the first import or the first cron tick, whichever comes first.
		Supcomp_JSON_Exporter::schedule_cron();
	}
}
