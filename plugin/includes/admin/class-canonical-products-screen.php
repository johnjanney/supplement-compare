<?php
/**
 * Canonical products admin screen. Full implementation lands in Phase 2.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Canonical_Products_Screen {

	public static function render() {
		Supcomp_Admin::render_placeholder(
			__( 'Canonical Products', 'supplement-compare' ),
			__( 'Canonical product management lands in Phase 2. This screen will let you add, edit, retire, and CSV-import the comparable SKU concepts (ingredient + form + strength + standardization).', 'supplement-compare' )
		);
	}
}
