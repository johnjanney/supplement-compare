<?php
/**
 * Merchants admin screen. Full implementation lands in Phase 3.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Merchants_Screen {

	public static function render() {
		Supcomp_Admin::render_placeholder(
			__( 'Merchants', 'supplement-compare' ),
			__( 'Merchant management lands in Phase 3. This screen will let you add, edit, pause, and configure affiliate URL templates for each participating merchant.', 'supplement-compare' )
		);
	}
}
