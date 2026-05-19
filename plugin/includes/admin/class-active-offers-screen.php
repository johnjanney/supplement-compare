<?php
/**
 * Active offers admin screen. Full implementation lands in Phase 6.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Active_Offers_Screen {

	public static function render() {
		Supcomp_Admin::render_placeholder(
			__( 'Active Offers', 'supplement-compare' ),
			__( 'Active offer management lands in Phase 6. This screen will show every offer currently visible on the public site, with edit / pause / re-review actions.', 'supplement-compare' )
		);
	}
}
