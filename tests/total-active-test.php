<?php
/**
 * Standalone tests for Q-020 provenance (v1.42.0) and the Total Active
 * Diagnostic probe. No WordPress needed. Run from the repo root:
 *
 *     php tests/total-active-test.php
 *
 * Product names are invented placeholders (CLAUDE.md: no real brands in git).
 */

define( 'ABSPATH', __DIR__ . '/' );

// ---------- WordPress stubs ----------

function wp_json_encode( $v ) {
	return json_encode( $v );
}
function current_time( $type, $gmt = 0 ) {
	return '2026-01-01 00:00:00';
}
function get_option( $k, $default = false ) {
	return isset( $GLOBALS['options'][ $k ] ) ? $GLOBALS['options'][ $k ] : $default;
}
function update_option( $k, $v ) {
	$GLOBALS['options'][ $k ] = $v;
	return true;
}

class Fake_WPDB {
	public $prefix  = 'wp_';
	public $queries = array();
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $a ) {
			$sql = preg_replace( '/%[dsf]/', is_string( $a ) ? "'" . addslashes( $a ) . "'" : (string) $a, $sql, 1 );
		}
		return $sql;
	}
	public function query( $sql ) {
		$this->queries[] = $sql;
		return 1;
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

$inc = dirname( __DIR__ ) . '/plugin/includes/';
require $inc . 'db/class-offers-repo.php';
require $inc . 'normalization/class-total-active-probe.php';

// ---------- Tiny assertion helper ----------

$failures = 0;
$passes   = 0;
function check( $label, $expected, $actual ) {
	global $failures, $passes;
	if ( $expected === $actual ) {
		++$passes;
		return;
	}
	++$failures;
	echo "FAIL: {$label}\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

// ---------- 1. Provenance: which fields an edit changes ----------

$before = (object) array(
	'ingredient_id'              => '7',
	'ingredient_form'            => 'capsule',
	'strength_per_serving'       => '200.0000',
	'strength_unit'              => 'mg',
	'servings_per_container'     => '60',
	'total_active_per_container' => null,
	'standardization_percentage' => null,
	'operator_fields_json'       => null,
);

check(
	'form posted back unchanged claims nothing',
	array(),
	Supcomp_Offers_Repo::changed_fields( $before, array(
		'ingredient_id'              => 7,
		'ingredient_form'            => 'capsule',
		'strength_per_serving'       => 200.0,
		'strength_unit'              => 'mg',
		'servings_per_container'     => 60,
		'total_active_per_container' => null,
		'standardization_percentage' => null,
	) )
);
check(
	'typed total + recomputed strength are claimed',
	array( 'strength_per_serving', 'total_active_per_container' ),
	Supcomp_Offers_Repo::changed_fields( $before, array(
		'strength_per_serving'       => 100.0,
		'total_active_per_container' => 6000.0,
	) )
);
check(
	'clearing a value counts as setting it',
	array( 'servings_per_container' ),
	Supcomp_Offers_Repo::changed_fields( $before, array( 'servings_per_container' => null ) )
);
check(
	'untracked fields ignored',
	array(),
	Supcomp_Offers_Repo::changed_fields( $before, array( 'operator_notes' => 'x', 'coa_url' => 'https://example.com' ) )
);

check( 'no json = nothing owned', array(), Supcomp_Offers_Repo::operator_fields( $before ) );
check( 'bad json = nothing owned', array(), Supcomp_Offers_Repo::operator_fields( (object) array( 'operator_fields_json' => '{nope' ) ) );
check(
	'unknown names filtered out',
	array( 'total_active_per_container' ),
	Supcomp_Offers_Repo::operator_fields( (object) array( 'operator_fields_json' => '["bogus","total_active_per_container"]' ) )
);
check( 'is_operator_field', true, Supcomp_Offers_Repo::is_operator_field( array( 'operator_fields_json' => '["strength_unit"]' ), 'strength_unit' ) );

Supcomp_Offers_Repo::backfill_operator_fields();
Supcomp_Offers_Repo::backfill_operator_fields();
check( 'backfill runs once', 1, count( $wpdb->queries ) );
check( 'backfill claims stored totals only', true, strpos( $wpdb->queries[0], 'total_active_per_container IS NOT NULL' ) !== false );

// ---------- 2. Probe: amount scanning ----------

$a = Supcomp_Total_Active_Probe::scan_amounts( 'Contains 12,000 mg total, 200mg per capsule, 1.5 g, 50 mcg, 5000 IU' );
check( 'comma thousands', 12000.0, $a[0]['value'] );
check( 'glued unit', array( 200.0, 'mg' ), array( $a[1]['value'], $a[1]['unit'] ) );
check( 'decimal grams', array( 1.5, 'g' ), array( $a[2]['value'], $a[2]['unit'] ) );
check( 'mcg', 'mcg', $a[3]['unit'] );
check( 'IU', 'IU', $a[4]['unit'] );
check( '"g" inside a word is not grams', array(), Supcomp_Total_Active_Probe::scan_amounts( '60 gummies' ) );

$p = Supcomp_Total_Active_Probe::scan_packs( 'Example Peptide 2 x 10mg vials' );
check( 'count-first pack', array( 20.0, 'mg' ), array( $p[0]['value'], $p[0]['unit'] ) );
$p = Supcomp_Total_Active_Probe::scan_packs( 'Example Peptide 5mg × 3' );
check( 'amount-first pack', array( 15.0, 'mg' ), array( $p[0]['value'], $p[0]['unit'] ) );

check( 'g → mg', 5000.0, Supcomp_Total_Active_Probe::convert( 5, 'g', 'mg' ) );
check( 'mcg → mg', 0.5, Supcomp_Total_Active_Probe::convert( 500, 'mcg', 'mg' ) );
check( 'IU ↛ mg', null, Supcomp_Total_Active_Probe::convert( 1000, 'IU', 'mg' ) );

// ---------- 3. Probe: attribute pairs keep their names ----------

$woo = json_encode( array(
	'attributes'           => array( array( 'name' => 'Total Amount', 'terms' => array( array( 'name' => '6000mg' ) ) ) ),
	'variation_attributes' => array( array( 'name' => 'Size', 'value' => '60 capsules' ) ),
) );
check(
	'woo named attributes',
	array( array( 'Total Amount', '6000mg' ), array( 'Size', '60 capsules' ) ),
	Supcomp_Total_Active_Probe::attribute_pairs( $woo )
);
$shopify = json_encode( array(
	'options'         => array( array( 'name' => 'Strength', 'values' => array( '10mg', '20mg' ) ) ),
	'variant_options' => array( 'option1' => '20mg' ),
) );
check(
	'shopify optionN mapped to option name',
	array( array( 'Strength', '10mg' ), array( 'Strength', '20mg' ), array( 'Strength', '20mg' ) ),
	Supcomp_Total_Active_Probe::attribute_pairs( $shopify )
);

// ---------- 4. Probe: classification ----------

$base = array(
	'truth'                 => 6000,
	'truth_unit'            => 'mg',
	'product_title'         => 'Example Compound 100mg 60 Capsules',
	'variant_title'         => '',
	'description'           => '',
	'raw_attributes_json'   => '',
	'current_strength'      => 100,
	'current_strength_unit' => 'mg',
	'current_servings'      => 60,
);

$r = Supcomp_Total_Active_Probe::probe( $base );
check( 'S × N matches → ok', 'ok', $r['current_status'] );
check( 'no explicit total → per-serving × count', 'per_serving_x_count', $r['best_source'] );

$r = Supcomp_Total_Active_Probe::probe( array_merge( $base, array(
	'product_title'       => 'Example Compound 60 Capsules',
	'raw_attributes_json' => $woo,
	'current_strength'    => 6000, // what today's normalizer picks up from "6000mg"
) ) );
check( 'attribute total is the best source', 'attribute', $r['best_source'] );
check( 'attribute key reported', 'Total Amount', $r['attribute_key'] );
check( 'total multiplied by count → wrong', 'wrong', $r['current_status'] );
check( 'strength_is_total flagged', true, $r['strength_is_total'] );
check( 'current total shows the blow-up', 360000.0, $r['current_total'] );

$r = Supcomp_Total_Active_Probe::probe( array_merge( $base, array( 'product_title' => 'Example Compound 6 g Powder', 'current_strength' => null, 'current_servings' => null ) ) );
check( 'grams in title match mg truth', 'title', $r['best_source'] );
check( 'missing strength → missing', 'missing', $r['current_status'] );

$r = Supcomp_Total_Active_Probe::probe( array_merge( $base, array(
	'product_title' => 'Example Compound',
	'description'   => 'Each bottle contains 6,000 mg total of example compound.',
) ) );
check( 'description-only total', 'description', $r['best_source'] );
check( 'total context detected', true, $r['context_says_total'] );

$r = Supcomp_Total_Active_Probe::probe( array_merge( $base, array( 'truth' => 20, 'product_title' => 'Example Peptide 2 x 10mg', 'current_strength' => 10, 'current_servings' => null ) ) );
check( 'pack notation total', 'pack', $r['best_source'] );

$r = Supcomp_Total_Active_Probe::probe( array_merge( $base, array( 'product_title' => 'Example Compound', 'current_strength' => null ) ) );
check( 'nothing anywhere → not_found', 'not_found', $r['best_source'] );

$r = Supcomp_Total_Active_Probe::probe( array_merge( $base, array( 'truth' => 0 ) ) );
check( 'no truth → inert', 'not_found', $r['best_source'] );

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures ? 1 : 0 );
