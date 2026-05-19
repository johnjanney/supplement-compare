<?php
/**
 * Pending queue admin screen. Default landing for the plugin's top-level menu.
 * Full implementation lands in Phase 6.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Pending_Queue_Screen {

	public static function render() {
		Supcomp_Admin::render_placeholder(
			__( 'Pending Queue', 'supplement-compare' ),
			__( 'The pending queue is the operator\'s main daily workflow: review imported offers, edit normalization, assign canonical matches, and approve or reject. Full implementation lands in Phase 6. Phase 1 has built the data model and admin shell only.', 'supplement-compare' )
		);
	}
}
