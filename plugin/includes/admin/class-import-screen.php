<?php
/**
 * CSV import admin screen. Full implementation lands in Phase 4.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Import_Screen {

	public static function render() {
		Supcomp_Admin::render_placeholder(
			__( 'CSV Import', 'supplement-compare' ),
			__( 'CSV import lands in Phase 4. This screen will accept the CSV produced by the Python extractor, validate it, run a dry-run on request, and import rows into the raw and normalized offers tables.', 'supplement-compare' )
		);
	}
}
