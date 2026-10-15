<?php
/**
 * Standalone tests for the single-container Total-active filler (v1.43.0).
 * No WordPress needed. Run from the repo root:
 *
 *     php tests/total-filler-test.php
 *
 * Product names are invented placeholders (CLAUDE.md: no real brands in git).
 */

define( 'ABSPATH', __DIR__ . '/' );

// ---------- WordPress stubs ----------

function wp_json_encode( $v, $flags = 0 ) {
	return json_encode( $v, $flags );
}
function current_time( $type, $gmt = 0 ) {
	return '2026-01-01 00:00:00';
}
function get_option( $k, $default = false ) {
	return $default;
}
function absint( $v ) {
	return abs( (int) $v );
}
function __( $s, $d = null ) {
	return $s;
}

class Fake_WPDB {
	public $prefix  = 'wp_';
	public $offers  = array(); // id => object
	public $raw     = array(); // id => raw_attributes_json
	public function prepare( $sql, ...$args ) {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $a ) {
			$sql = preg_replace( '/%[dsf]/', is_string( $a ) ? "'" . addslashes( $a ) . "'" : (string) $a, $sql, 1 );
		}
		return $sql;
	}
	public function get_results( $sql ) {
		$out = array();
		foreach ( $this->offers as $o ) {
			if ( in_array( $o->visibility_status, array( 'pending', 'needs_review' ), true ) && $o->total_active_per_container === null
				&& preg_match( '/id > (\d+)/', $sql, $c ) && $o->id > (int) $c[1]
				&& ( ! preg_match( '/AND id IN \(([^)]*)\)/', $sql, $in ) || in_array( (string) $o->id, array_map( 'trim', explode( ',', $in[1] ) ), true ) ) ) {
				$out[] = clone $o;
			}
		}
		return $out;
	}
	public function get_row( $sql ) {
		if ( strpos( $sql, 'raw_source_offers' ) !== false ) {
			preg_match( "/source_product_id = '([^']*)'/", $sql, $m );
			foreach ( $this->offers as $o ) {
				if ( $o->source_product_id === $m[1] ) {
					return (object) array( 'raw_csv_row_json' => json_encode( array( 'raw_attributes_json' => $this->raw[ $o->id ] ?? '' ) ) );
				}
			}
			return null;
		}
		preg_match( '/WHERE id = (\d+)/', $sql, $m );
		return isset( $this->offers[ (int) $m[1] ] ) ? clone $this->offers[ (int) $m[1] ] : null;
	}
	public function get_var( $sql ) {
		// has_siblings: same merchant, other id, same product id or title.
		preg_match( "/merchant_id = (\d+) AND id <> (\d+)\s+AND \( \( source_product_id = '([^']*)' AND source_product_id <> '' \) OR product_title = '([^']*)' \)/", $sql, $m );
		foreach ( $this->offers as $o ) {
			if ( (int) $o->merchant_id === (int) $m[1] && (int) $o->id !== (int) $m[2]
				&& ( ( $o->source_product_id === stripslashes( $m[3] ) && $m[3] !== '' ) || $o->product_title === stripslashes( $m[4] ) ) ) {
				return $o->id;
			}
		}
		return null;
	}
	public function query( $sql ) {
		if ( ! preg_match( '/SET (.*) WHERE id = (\d+) AND total_active_per_container IS NULL/s', $sql, $m ) ) {
			return 0;
		}
		$o = $this->offers[ (int) $m[2] ];
		if ( $o->total_active_per_container !== null ) {
			return 0;
		}
		preg_match_all( "/(\w+) = '([^']*)'/", $m[1], $sets, PREG_SET_ORDER );
		foreach ( $sets as $s ) {
			$o->{$s[1]} = $s[2];
		}
		return 1;
	}
	public function update( $table, $data, $where ) {
		return 1;
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

class Supcomp_Ingredients_Repo {
	public static function all_for_matching() {
		return array();
	}
	public static function get( $id ) {
		return null;
	}
}

$inc = dirname( __DIR__ ) . '/plugin/includes/';
foreach ( array( 'strength', 'count', 'form', 'standardization' ) as $rule ) {
	require $inc . "normalization/rules/class-{$rule}-rule.php";
}
require $inc . 'normalization/class-normalizer.php';
require $inc . 'normalization/class-offer-derivations.php';
require $inc . 'normalization/class-total-active-probe.php';
require $inc . 'normalization/class-total-filler.php';
require $inc . 'db/class-offers-repo.php';

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
function s( $title, $extra = array() ) {
	$r = Supcomp_Total_Filler::suggest( array_merge(
		array( 'product_title' => $title, 'variant_title' => '', 'raw_attributes_json' => '', 'ingredient_form' => null, 'servings' => null, 'has_siblings' => false ),
		$extra
	) );
	return $r === null ? null : array( $r['total'], $r['unit'], $r['source'] );
}
function attrs( array $a ) {
	return json_encode( $a );
}

// ---------- 1. Fills a plain single container ----------

check( 'title amount', array( 10.0, 'mg', 'single_title' ), s( 'Example Peptide 10mg' ) );
check( 'entity title', array( 40.0, 'mg', 'single_title' ), s( 'Example Peptide &#8211; 40mg' ) );
check( '"(1 vial)" is still single', array( 5.0, 'mg', 'single_title' ), s( 'Example Peptide 5 mg (1 vial)' ) );
check( 'NAD+ is not a blend', array( 500.0, 'mg', 'single_title' ), s( 'NAD+ 500mg' ) );
check( 'grams kept', array( 1.0, 'g', 'single_title' ), s( 'Example Compound 1 g' ) );
check( 'IU kept', array( 10.0, 'IU', 'single_title' ), s( 'Example Hormone 10 IU' ) );
check(
	'attribute only, no siblings',
	array( 20.0, 'mg', 'single_attribute' ),
	s( 'Example Peptide', array( 'raw_attributes_json' => attrs( array( 'attributes' => array( array( 'name' => 'Size', 'terms' => array( array( 'name' => '20 MG' ) ) ) ) ) ) ) )
);
check(
	'title and attribute agree',
	array( 10.0, 'mg', 'single_title' ),
	s( 'Example Peptide 10mg', array( 'raw_attributes_json' => attrs( array( 'attributes' => array( array( 'name' => 'Size', 'terms' => array( array( 'name' => '10 mg' ) ) ) ) ) ) ) )
);
check(
	'molecular weight attribute ignored',
	array( 10.0, 'mg', 'single_title' ),
	s( 'Example Peptide 10mg', array( 'raw_attributes_json' => attrs( array( 'attributes' => array( array( 'name' => 'Molecular Weight', 'terms' => array( array( 'name' => '403.9 g/mol' ) ) ) ) ) ) ) )
);

// ---------- 2. Variant-level values win, siblings block shared titles ----------

$var = attrs( array(
	'attributes'           => array( array( 'name' => 'Size', 'terms' => array( array( 'name' => '10mg' ), array( 'name' => '20mg' ) ) ) ),
	'variation_attributes' => array( array( 'name' => 'Size', 'value' => '20mg' ) ),
) );
check( 'variation attribute picks the variant', array( 20.0, 'mg', 'single_attribute' ), s( 'Example Peptide 10mg', array( 'raw_attributes_json' => $var, 'has_siblings' => true ) ) );
check( 'variant title', array( 20.0, 'mg', 'single_variant' ), s( 'Example Peptide', array( 'variant_title' => '20 MG', 'has_siblings' => true ) ) );
check( 'siblings + shared title → blank', null, s( 'Example Peptide 10mg', array( 'has_siblings' => true ) ) );
check(
	'parent option list alone → blank',
	null,
	s( 'Example Peptide', array( 'raw_attributes_json' => attrs( array( 'attributes' => array( array( 'name' => 'Size', 'terms' => array( array( 'name' => '10mg' ), array( 'name' => '20mg' ) ) ) ) ) ) ) )
);
check(
	'title disagrees with URL slug → blank',
	null,
	s( 'Example Peptide 10mg', array( 'raw_attributes_json' => attrs( array( 'jsonld_offer' => array( 'url' => 'https://example.com/product-page/example-peptide-5mg' ) ) ) ) )
);
check( 'two variant amounts → blank', null, s( 'Example', array( 'variant_title' => '10mg / 20mg' ) ) );

// ---------- 3. Guards ----------

check( 'N-pack', null, s( 'Example Peptide 30mg 3-Pack' ) );
check( 'N vials', null, s( 'BULK Example Peptide 5mg (10 VIALS)' ) );
check( 'singular VIAL pack', null, s( '10 VIAL COMBO PACK: Example Peptide 50mg' ) );
check( 'x count', null, s( 'Example Peptide 10mg x 10 vials' ) );
check( 'kit', null, s( 'Example Peptide 10mg Kit' ) );
check( 'blend word', null, s( 'Example Blend 20mg' ) );
check( 'A + B', null, s( 'Example-A 10mg + Example-B 10mg' ) );
check( 'slash amounts', null, s( 'Example-A/Example-B 13/3MG' ) );
check( 'name/name', null, s( 'Tesa/Ipa 10mg' ) );
check( 'concentration', null, s( 'Example Solution 5mg/ml' ) );
check( 'capsules in title', null, s( 'Example Compound 500mg 60 Capsules' ) );
check( 'servings known', null, s( 'Example Compound 500mg', array( 'servings' => 60 ) ) );
check( 'capsule form', null, s( 'Example Compound 500mg', array( 'ingredient_form' => 'capsule' ) ) );
check( 'no amount', null, s( 'Example Peptide' ) );

// ---------- 4. fill() writes only blank, non-operator queue totals ----------

function offer( $id, $title, $extra = array() ) {
	return (object) array_merge(
		array(
			'id' => $id, 'merchant_id' => 1, 'source_product_id' => 'p' . $id, 'source_variant_id' => '',
			'product_title' => $title, 'variant_title' => '', 'visibility_status' => 'pending',
			'ingredient_id' => null, 'ingredient_form' => null, 'strength_per_serving' => null, 'strength_unit' => null,
			'servings_per_container' => null, 'total_active_per_container' => null, 'total_active_source' => null,
			'standardization_percentage' => null, 'current_price' => '50', 'operator_fields_json' => null,
		),
		$extra
	);
}
$wpdb->offers = array(
	1 => offer( 1, 'Example Peptide 10mg' ),
	2 => offer( 2, 'Example Peptide 5mg', array( 'operator_fields_json' => '["total_active_per_container"]' ) ),
	3 => offer( 3, 'Example Peptide 2mg', array( 'strength_unit' => 'mcg' ) ),
	4 => offer( 4, 'Example Peptide 10mg', array( 'visibility_status' => 'active' ) ),
	5 => offer( 5, 'Example Peptide 7mg', array( 'total_active_per_container' => '9' ) ),
	6 => offer( 6, 'Example Other 10mg 3-Pack' ),
);
$r = Supcomp_Total_Filler::fill();
check( 'fill result (offer 1 blocked by same-title sibling 4)', array( 'checked' => 4, 'filled' => 1, 'complete' => true ), $r );
check( 'sibling-blocked offer left blank', null, $wpdb->offers[1]->total_active_per_container );
// Same title at another merchant is not a sibling.
$wpdb->offers[4]->merchant_id = 2;
$r = Supcomp_Total_Filler::fill( array( 1 ) );
check( 'shared title across merchants is not a sibling', array( 'checked' => 1, 'filled' => 1, 'complete' => true ), $r );
check( 'total written', '10', $wpdb->offers[1]->total_active_per_container );
check( 'unit adopted when blank', 'mg', $wpdb->offers[1]->strength_unit );
check( 'source recorded', 'single_title', $wpdb->offers[1]->total_active_source );
check( 'operator-cleared total untouched', null, $wpdb->offers[2]->total_active_per_container );
check( 'converted into existing unit', '2000', $wpdb->offers[3]->total_active_per_container );
check( 'existing unit kept', 'mcg', $wpdb->offers[3]->strength_unit );
check( 'approved offer untouched', null, $wpdb->offers[4]->total_active_per_container );
check( 'existing total untouched', '9', $wpdb->offers[5]->total_active_per_container );
check( 'pack untouched', null, $wpdb->offers[6]->total_active_per_container );
check( 'empty id list is a no-op', array( 'checked' => 0, 'filled' => 0, 'complete' => true ), Supcomp_Total_Filler::fill( array() ) );

// ---------- 5. Editing an auto total clears its label ----------

$before = offer( 9, 'x', array( 'total_active_per_container' => '10.0000', 'total_active_source' => 'single_title' ) );
check( 'changed total detected', array( 'total_active_per_container' ), Supcomp_Offers_Repo::changed_fields( $before, array( 'total_active_per_container' => 20.0 ) ) );

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures ? 1 : 0 );
