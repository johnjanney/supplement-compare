<?php
/**
 * Click analytics admin screen. Full implementation lands in Phase 7.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Clicks_Screen {

	public static function render() {
		Supcomp_Admin::render_placeholder(
			__( 'Click Analytics', 'supplement-compare' ),
			__( 'Click analytics lands in Phase 7 alongside the /out/{offer_id} redirect endpoint. This screen will summarize clicks per offer, per merchant, and per canonical product across configurable time windows.', 'supplement-compare' )
		);
	}
}
