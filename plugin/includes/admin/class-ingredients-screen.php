<?php
/**
 * Canonical ingredients admin screen. Full implementation lands in Phase 2.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Ingredients_Screen {

	public static function render() {
		Supcomp_Admin::render_placeholder(
			__( 'Canonical Ingredients', 'supplement-compare' ),
			__( 'Ingredient management lands in Phase 2. This screen will let you add, edit, retire, and CSV-import the compound database (slug, name, aliases, category, default unit, elemental and standardization percentages).', 'supplement-compare' )
		);
	}
}
