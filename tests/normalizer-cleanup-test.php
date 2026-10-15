<?php
/**
 * Standalone tests for the v1.42.1 normalizer input cleanup: HTML entities,
 * dates read as counts, attribute leaves fusing into fake amounts, comma
 * thousands, and the learned-mapping re-key. No WordPress needed. Run from
 * the repo root:
 *
 *     php tests/normalizer-cleanup-test.php
 *
 * Product names are invented placeholders (CLAUDE.md: no real brands in git).
 */

define( 'ABSPATH', __DIR__ . '/' );

// ---------- WordPress stubs ----------

function get_option( $k, $default = false ) {
	return isset( $GLOBALS['options'][ $k ] ) ? $GLOBALS['options'][ $k ] : $default;
}
function update_option( $k, $v ) {
	$GLOBALS['options'][ $k ] = $v;
	return true;
}

class Fake_WPDB {
	public $prefix = 'wp_';
	public $memory = array();
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $a ) {
			$sql = preg_replace( '/%[dsf]/', is_string( $a ) ? "'" . addslashes( $a ) . "'" : (string) $a, $sql, 1 );
		}
		return $sql;
	}
	public function get_results( $sql ) {
		return array_map( static function ( $r ) { return clone $r; }, $this->memory );
	}
	public function get_var( $sql ) {
		preg_match( "/merchant_id = (\d+) AND title_key = '([^']*)'/", $sql, $m );
		foreach ( $this->memory as $r ) {
			if ( (int) $r->merchant_id === (int) $m[1] && $r->title_key === stripslashes( $m[2] ) ) {
				return $r->id;
			}
		}
		return null;
	}
	public function query( $sql ) {
		preg_match( "/SET title_key = '([^']*)', sample_title = '([^']*)' WHERE id = (\d+)/", $sql, $m );
		foreach ( $this->memory as $r ) {
			if ( (int) $r->id === (int) $m[3] ) {
				$r->title_key    = stripslashes( $m[1] );
				$r->sample_title = stripslashes( $m[2] );
			}
		}
		return 1;
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

class Supcomp_Ingredients_Repo {
	public static function all_for_matching() {
		return array();
	}
}

$inc = dirname( __DIR__ ) . '/plugin/includes/';
foreach ( array( 'strength', 'count', 'form', 'standardization' ) as $rule ) {
	require $inc . "normalization/rules/class-{$rule}-rule.php";
}
require $inc . 'normalization/class-normalizer.php';
require $inc . 'normalization/class-matcher.php';
require $inc . 'db/class-match-memory-repo.php';

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
function norm( $title, $attrs = null, $extra = array() ) {
	return Supcomp_Normalizer::normalize( array_merge(
		array( 'product_title' => $title, 'raw_attributes_json' => $attrs === null ? '' : json_encode( $attrs ) ),
		$extra
	) );
}

// ---------- 1. HTML entities ----------

check( 'numeric entity', 'Example Peptide – 10mg', Supcomp_Normalizer::decode_entities( 'Example Peptide &#8211; 10mg' ) );
check( 'named entity', 'A & B Blend', Supcomp_Normalizer::decode_entities( 'A &amp; B Blend' ) );
check( 'double-encoded', 'A – B', Supcomp_Normalizer::decode_entities( 'A &amp;#8211; B' ) );
check( 'plain text untouched', 'Example 5mg', Supcomp_Normalizer::decode_entities( 'Example 5mg' ) );
$row = Supcomp_Normalizer::decode_row_text( array(
	'product_title'       => 'BULK PACK Example &#8211; 5mg',
	'brand'               => 'Example &amp; Co',
	'raw_attributes_json' => '{"a":"&quot;x&quot;"}',
) );
check( 'row title decoded', 'BULK PACK Example – 5mg', $row['product_title'] );
check( 'row brand decoded', 'Example & Co', $row['brand'] );
check( 'row JSON left alone', '{"a":"&quot;x&quot;"}', $row['raw_attributes_json'] );
check(
	'title key same encoded or decoded',
	Supcomp_Matcher::normalize_title( 'Example A & B Blend' ),
	Supcomp_Matcher::normalize_title( 'Example A &amp; B Blend' )
);

// ---------- 2. Counts: dates and amounts are not servings ----------

check( 'date is not a count', null, Supcomp_Count_Rule::extract( 'Example Peptide | updated 10/14/2025' ) );
check( 'URL year is not a count', null, Supcomp_Count_Rule::extract( 'https://example.com/2025/10/example' ) );
check( 'x60 still counts', 60, Supcomp_Count_Rule::extract( 'Example Compound x60' ) );
check( '× 30 still counts', 30, Supcomp_Count_Rule::extract( 'Example Compound × 30' ) );
check( 'pack amount is not a count', null, Supcomp_Count_Rule::extract( 'Example Peptide 2 x 10mg' ) );
check( 'x inside a code is not a count', null, Supcomp_Count_Rule::extract( 'Example-X10 Peptide' ) );
check( 'vessel words unchanged', 60, Supcomp_Count_Rule::extract( 'Example 100mg 60 capsules' ) );

// ---------- 3. Attribute flattening ----------

$n = norm( 'Example Peptide', array( 'jsonld_offer' => array( 'weight' => array( 'value' => 751.9, 'unitText' => 'g' ) ) ) );
check( 'shipping weight is not a strength', null, $n['strength_per_serving'] );

$n = norm( 'Example Peptide', array( 'attributes' => array( array( 'id' => 12, 'name' => 'Size', 'terms' => array( array( 'id' => 34, 'name' => 'mg' ) ) ) ) ) );
check( 'numeric ids do not fuse with a unit', null, $n['strength_per_serving'] );

$n = norm( 'Example Peptide', array( 'additionalProperty' => array( array( 'name' => 'Strength', 'value' => 10, 'unitText' => 'mg' ) ) ) );
check( 'value + unitText pair kept', array( 10.0, 'mg' ), array( $n['strength_per_serving'], $n['strength_unit'] ) );

$n = norm( 'Example Peptide', array( 'attributes' => array( array( 'name' => 'Size', 'terms' => array( array( 'name' => '20 MG' ) ) ) ) ) );
check( 'string attribute values still read', array( 20.0, 'mg' ), array( $n['strength_per_serving'], $n['strength_unit'] ) );

$n = norm( 'Example Peptide &#8211; 40mg' );
check( 'entity title parses', 40.0, $n['strength_per_serving'] );

// ---------- 4. Comma thousands (Q-021) ----------

$s = Supcomp_Strength_Rule::extract( 'Example Powder 1,500 mg per scoop' );
check( 'comma thousands', 1500.0, $s['value'] );
$s = Supcomp_Strength_Rule::extract( 'Example 2,500.5mg' );
check( 'comma thousands with decimal', 2500.5, $s['value'] );
$s = Supcomp_Strength_Rule::extract( 'Example Peptide 10mg' );
check( 'plain amount unchanged', 10.0, $s['value'] );
$s = Supcomp_Strength_Rule::extract( 'Example 12,000 mg' );
check( 'never "000 mg"', 12000.0, $s['value'] );

// ---------- 4b. Molecular weights are not amounts (v1.42.2) ----------

$s = Supcomp_Strength_Rule::extract( 'Example Peptide | Molecular Weight: 403.9 g/mol | Purity 99%' );
check( 'g/mol is not a strength', null, $s );
$s = Supcomp_Strength_Rule::extract( 'Example Peptide 403.9 g·mol and more' );
check( 'g·mol is not a strength', null, $s );
$s = Supcomp_Strength_Rule::extract( 'Example Peptide | Molar mass: 751.9 g' );
check( 'labelled molar mass without /mol', null, $s );
$s = Supcomp_Strength_Rule::extract( 'Example Peptide 10mg | Molecular Weight: 403.9 g/mol' );
check( 'real dose still read beside a molecular weight', 10.0, $s['value'] );
$n = norm( 'Example Peptide', null, array( 'description' => 'Research use only. Molecular Weight: 751.9 g/mol. Sequence: example.' ) );
check( 'description molecular weight ignored', null, $n['strength_per_serving'] );
$s = Supcomp_Strength_Rule::extract( 'Example Powder 5 g per scoop' );
check( 'plain grams unchanged', 5.0, $s['value'] );

// ---------- 5. Learned-mapping re-key ----------

$wpdb->memory = array(
	(object) array( 'id' => 1, 'merchant_id' => 5, 'title_key' => 'example a amp b blend', 'sample_title' => 'Example A &amp; B Blend' ),
	(object) array( 'id' => 2, 'merchant_id' => 5, 'title_key' => 'example c blend', 'sample_title' => 'Example C &nbsp;Blend' ),
	(object) array( 'id' => 3, 'merchant_id' => 5, 'title_key' => 'example c nbsp blend', 'sample_title' => 'Example C &nbsp;Blend' ),
	(object) array( 'id' => 4, 'merchant_id' => 6, 'title_key' => 'example d', 'sample_title' => 'Example D &#8211; 5mg' ),
);
check( 'rekeyed count', 1, Supcomp_Match_Memory_Repo::rekey_for_decoded_entities() );
check( 'named-entity key rewritten', 'example a b blend', $wpdb->memory[0]->title_key );
check( 'sample title decoded', 'Example A & B Blend', $wpdb->memory[0]->sample_title );
check( 'collision left alone', 'example c nbsp blend', $wpdb->memory[2]->title_key );
check( 'numeric-entity key already stable', 'example d', $wpdb->memory[3]->title_key );
check( 'runs once', 0, Supcomp_Match_Memory_Repo::rekey_for_decoded_entities() );

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures ? 1 : 0 );
