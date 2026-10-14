<?php
/**
 * Standalone tests for learned canonical matching (v1.41.0).
 *
 * No WordPress needed: a fake $wpdb answers the handful of queries the
 * matcher, match-memory repo and re-matcher issue. Run from the repo root:
 *
 *     php tests/match-memory-test.php
 *
 * Product names are invented placeholders (CLAUDE.md: no real brands in git).
 */

define( 'ABSPATH', __DIR__ . '/' );

// ---------- WordPress stubs ----------

function current_time( $type, $gmt = 0 ) {
	return '2026-01-01 00:00:00';
}
function absint( $v ) {
	return abs( (int) $v );
}
function update_option( $k, $v ) {
	$GLOBALS['options'][ $k ] = $v;
	return true;
}

class Fake_WPDB {
	public $prefix    = 'wp_';
	public $insert_id = 0;
	public $memory    = array(); // match_memory rows (objects)
	public $offers    = array(); // id => offer object
	public $queries   = array();

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
		$this->queries[] = $sql;
		if ( strpos( $sql, 'wp_supcomp_match_memory m' ) !== false && preg_match( "/m\.title_key = '([^']*)'/", $sql, $mm ) ) {
			$out = array();
			foreach ( $this->memory as $r ) {
				if ( $r->title_key === $mm[1] && empty( $r->retired ) ) {
					$out[] = $r;
				}
			}
			return $out;
		}
		if ( strpos( $sql, "visibility_status IN ('pending', 'needs_review')" ) !== false ) {
			$out = array();
			foreach ( $this->offers as $o ) {
				if ( in_array( $o->visibility_status, array( 'pending', 'needs_review' ), true ) && $o->canonical_product_id === null ) {
					if ( preg_match( '/AND id > (\d+)/', $sql, $cur ) && $o->id <= (int) $cur[1] ) {
						continue;
					}
					if ( preg_match( '/AND id IN \(([^)]*)\)/', $sql, $ids ) && ! in_array( (string) $o->id, array_map( 'trim', explode( ',', $ids[1] ) ), true ) ) {
						continue;
					}
					$out[] = clone $o;
				}
			}
			return $out;
		}
		return array(); // every peer / canonical tier: no hit
	}

	public function get_row( $sql ) {
		$this->queries[] = $sql;
		if ( strpos( $sql, 'FROM wp_supcomp_match_memory WHERE merchant_id' ) !== false ) {
			preg_match( "/merchant_id = (\d+) AND title_key = '([^']*)'/", $sql, $mm );
			foreach ( $this->memory as $r ) {
				if ( (int) $r->merchant_id === (int) $mm[1] && $r->title_key === $mm[2] ) {
					return $r;
				}
			}
		}
		return null;
	}

	public function get_var( $sql ) {
		return null;
	}

	public function insert( $table, $data ) {
		$this->insert_id = count( $this->memory ) + 1;
		$this->memory[]  = (object) array_merge( array( 'id' => $this->insert_id ), $data );
		return 1;
	}

	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( '/^UPDATE wp_supcomp_match_memory/', trim( $sql ) ) ) {
			preg_match( '/canonical_product_id = (\d+)/', $sql, $c );
			preg_match( '/WHERE id = (\d+)/', $sql, $i );
			foreach ( $this->memory as $r ) {
				if ( (int) $r->id === (int) $i[1] ) {
					$same                    = (int) $r->canonical_product_id === (int) $c[1];
					$r->canonical_product_id = (int) $c[1];
					$r->times_confirmed      = $same ? $r->times_confirmed + 1 : 1;
				}
			}
			return 1;
		}
		if ( preg_match( '/^UPDATE wp_supcomp_normalized_offers SET (.*) WHERE id = (\d+) AND canonical_product_id IS NULL/s', trim( $sql ), $m ) ) {
			$o = $this->offers[ (int) $m[2] ];
			if ( $o->canonical_product_id !== null ) {
				return 0;
			}
			preg_match_all( "/(\w+) = '([^']*)'/", $m[1], $sets, PREG_SET_ORDER );
			foreach ( $sets as $s ) {
				$o->{$s[1]} = $s[2];
			}
			return 1;
		}
		return 0;
	}
}

$GLOBALS['wpdb'] = new Fake_WPDB();

// ---------- Domain stubs ----------

class Supcomp_Canonical_Products_Repo {
	public static $rows = array();
	public static function table() {
		return 'wp_supcomp_canonical_products';
	}
	public static function get( $id ) {
		return isset( self::$rows[ $id ] ) ? self::$rows[ $id ] : null;
	}
}
class Supcomp_Ingredients_Repo {
	public static function all_for_matching() {
		return array( array( 'id' => 7, 'name' => 'Examplatide', 'aliases' => array() ) );
	}
	public static function get( $id ) {
		return null;
	}
}
class Supcomp_Offers_Repo {
	public static $derivations = array();
	public static function table() {
		return 'wp_supcomp_normalized_offers';
	}
	public static function get( $id ) {
		global $wpdb;
		return isset( $wpdb->offers[ $id ] ) ? $wpdb->offers[ $id ] : null;
	}
	public static function apply_derivations( $id, $d ) {
		self::$derivations[ $id ] = $d;
	}
	public static function is_operator_field( $offer, $field ) {
		$owned = isset( $offer->operator_fields_json ) ? json_decode( (string) $offer->operator_fields_json, true ) : array();
		return is_array( $owned ) && in_array( $field, $owned, true );
	}
}

$inc = dirname( __DIR__ ) . '/plugin/includes/';
foreach ( array( 'strength', 'count', 'form', 'standardization' ) as $rule ) {
	require $inc . "normalization/rules/class-{$rule}-rule.php";
}
require $inc . 'normalization/class-normalizer.php';
require $inc . 'normalization/class-matcher.php';
require $inc . 'normalization/class-offer-derivations.php';
require $inc . 'normalization/class-rematcher.php';
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
function reset_state() {
	global $wpdb;
	$wpdb->memory  = array();
	$wpdb->offers  = array();
	$wpdb->queries = array();
	Supcomp_Canonical_Products_Repo::$rows = array(
		10 => (object) array( 'id' => 10, 'status' => 'active', 'ingredient_id' => 7, 'ingredient_form' => null, 'strength_per_serving' => null, 'standardization_percentage' => null ),
		11 => (object) array( 'id' => 11, 'status' => 'active', 'ingredient_id' => 7, 'ingredient_form' => null, 'strength_per_serving' => null, 'standardization_percentage' => null ),
		12 => (object) array( 'id' => 12, 'status' => 'retired', 'ingredient_id' => 7, 'ingredient_form' => null, 'strength_per_serving' => null, 'standardization_percentage' => null ),
	);
}
function offer( $id, $merchant, $title, $extra = array() ) {
	return (object) array_merge(
		array(
			'id'                         => $id,
			'merchant_id'                => $merchant,
			'product_title'              => $title,
			'variant_title'              => '',
			'brand'                      => '',
			'sku'                        => '',
			'barcode'                    => '',
			'canonical_product_id'       => null,
			'visibility_status'          => 'pending',
			'ingredient_id'              => null,
			'ingredient_form'            => null,
			'strength_per_serving'       => null,
			'servings_per_container'     => null,
			'standardization_percentage' => null,
			'current_price'              => null,
			'total_active_per_container' => null,
		),
		$extra
	);
}

// ---------- 1. Title normalization ----------

check( 'glued strength stripped', 'example tz', Supcomp_Matcher::normalize_title( 'Example-Tz 10mg' ) );
check( 'spaced strength stripped', 'example tz', Supcomp_Matcher::normalize_title( 'Example-Tz 10 mg' ) );
check( 'decimal strength stripped', 'example tz', Supcomp_Matcher::normalize_title( 'Example-Tz 2.5mg' ) );
check( 'mcg / ml stripped', 'widget peptide', Supcomp_Matcher::normalize_title( 'Widget Peptide 500mcg 3ml' ) );
check( 'form words stripped', 'example blend', Supcomp_Matcher::normalize_title( 'Example Blend 60 Capsules' ) );
check( 'code-style name kept whole', 'ex290', Supcomp_Matcher::normalize_title( 'EX-290 10mg' ) );
check( 'code-style names stay distinct', true, Supcomp_Matcher::normalize_title( 'EX-290' ) !== Supcomp_Matcher::normalize_title( 'EX-300' ) );
check( 'count with hyphen still stripped', 'example blend', Supcomp_Matcher::normalize_title( 'Example Blend 60-count' ) );
check( 'too-generic key is empty', '', Supcomp_Match_Memory_Repo::key_for_title( 'X 10mg' ) );
check( 'key is strength-agnostic', Supcomp_Match_Memory_Repo::key_for_title( 'Example-Tz 10mg' ), Supcomp_Match_Memory_Repo::key_for_title( 'Example-Tz 20 MG' ) );

// ---------- 2. Recording + lookup ----------

reset_state();
$k = Supcomp_Match_Memory_Repo::learn_from_offer( offer( 1, 5, 'Example-Tz 10mg', array( 'canonical_product_id' => 10 ) ) );
check( 'learn returns key', 'example tz', $k );
check( 'one mapping stored', 1, count( $wpdb->memory ) );
check( 'same merchant → 0.90', array( 'canonical_product_id' => 10, 'confidence' => 0.90 ), Supcomp_Match_Memory_Repo::lookup( 5, 'example tz' ) );
check( 'other merchant → 0.80', array( 'canonical_product_id' => 10, 'confidence' => 0.80 ), Supcomp_Match_Memory_Repo::lookup( 9, 'example tz' ) );
check( 'unknown key → null', null, Supcomp_Match_Memory_Repo::lookup( 5, 'something else' ) );

Supcomp_Match_Memory_Repo::learn_from_offer( offer( 2, 5, 'Example-Tz 20mg', array( 'canonical_product_id' => 10 ) ) );
check( 're-confirm bumps count, no duplicate', array( 1, 2 ), array( count( $wpdb->memory ), $wpdb->memory[0]->times_confirmed ) );

Supcomp_Match_Memory_Repo::learn_from_offer( offer( 3, 5, 'Example-Tz 30mg', array( 'canonical_product_id' => 11 ) ) );
check( 'different canonical overwrites + resets count', array( 11, 1 ), array( $wpdb->memory[0]->canonical_product_id, $wpdb->memory[0]->times_confirmed ) );

check( 'no canonical → nothing learned', null, Supcomp_Match_Memory_Repo::learn_from_offer( offer( 4, 5, 'Other Thing' ) ) );
check( 'retired canonical → nothing learned', null, Supcomp_Match_Memory_Repo::learn_from_offer( offer( 4, 5, 'Other Thing', array( 'canonical_product_id' => 12 ) ) ) );

// Cross-merchant disagreement → no suggestion for a third merchant.
reset_state();
Supcomp_Match_Memory_Repo::record( 5, 'example tz', 10, 'Example-Tz', 'approval' );
Supcomp_Match_Memory_Repo::record( 6, 'example tz', 11, 'Example-Tz', 'approval' );
check( 'others disagree → null', null, Supcomp_Match_Memory_Repo::lookup( 9, 'example tz' ) );
check( 'own merchant still wins', 11, Supcomp_Match_Memory_Repo::lookup( 6, 'example tz' )['canonical_product_id'] );

// ---------- 3. Matcher tier order ----------

reset_state();
Supcomp_Match_Memory_Repo::record( 5, 'example tz', 10, 'Example-Tz', 'approval' );
$m = Supcomp_Matcher::match( offer( 20, 5, 'Example-Tz 40mg' ), Supcomp_Normalizer::normalize( offer( 20, 5, 'Example-Tz 40mg' ) ) );
check( 'matcher uses memory (merchant_id)', array( 'canonical_product_id' => 10, 'confidence' => 0.90 ), $m );
$m = Supcomp_Matcher::match( array( '_merchant_id' => 5, 'product_title' => 'Example-Tz 5mg' ), array() );
check( 'matcher uses memory (CSV _merchant_id)', 0.90, $m['confidence'] );
$m = Supcomp_Matcher::match( offer( 21, 5, 'Unknown Product' ), array() );
check( 'no memory, no tiers → no match', array( 'canonical_product_id' => null, 'confidence' => null ), $m );

// ---------- 4. Re-matcher ----------

reset_state();
$wpdb->offers[30] = offer( 30, 5, 'Example-Tz 10mg' );
$wpdb->offers[31] = offer( 31, 5, 'Example-Tz 20mg', array( 'ingredient_id' => '99' ) );
$wpdb->offers[32] = offer( 32, 5, 'Example-Tz 30mg', array( 'canonical_product_id' => 11 ) );
$wpdb->offers[33] = offer( 33, 5, 'Example-Tz 40mg', array( 'visibility_status' => 'active' ) );
$wpdb->offers[34] = offer( 34, 5, 'Unrelated Item' );
Supcomp_Match_Memory_Repo::record( 5, 'example tz', 10, 'Example-Tz', 'approval' );

$r = Supcomp_Rematcher::rematch_for_keys( array( 'example tz' ) );
check( 'targeted pass checks only pending+unmatched with the key', array( 'checked' => 2, 'matched' => 2, 'complete' => true ), $r );
check( 'canonical set on blank offer', '10', (string) $wpdb->offers[30]->canonical_product_id );
check( 'confidence written', '0.9', (string) $wpdb->offers[30]->match_confidence );
check( 'blank ingredient filled from canonical', '7', (string) $wpdb->offers[30]->ingredient_id );
check( 'existing ingredient NOT overwritten', '99', (string) $wpdb->offers[31]->ingredient_id );
check( 'offer with canonical untouched', 11, $wpdb->offers[32]->canonical_product_id );
check( 'approved offer untouched', null, $wpdb->offers[33]->canonical_product_id );
check( 'unrelated offer untouched', null, $wpdb->offers[34]->canonical_product_id );
check( 'derivations recomputed for matched offers', array( 30, 31 ), array_keys( Supcomp_Offers_Repo::$derivations ) );

$r = Supcomp_Rematcher::rematch_unmatched( array( 34 ) );
check( 'id-restricted pass', array( 'checked' => 1, 'matched' => 0, 'complete' => true ), $r );
check( 'empty id list is a no-op', array( 'checked' => 0, 'matched' => 0, 'complete' => true ), Supcomp_Rematcher::rematch_unmatched( array() ) );

// Cursor: more unmatched offers than one chunk, the matchable one last.
reset_state();
Supcomp_Match_Memory_Repo::record( 5, 'example tz', 10, 'Example-Tz', 'approval' );
for ( $i = 100; $i < 100 + Supcomp_Rematcher::CHUNK_SIZE + 5; $i++ ) {
	$wpdb->offers[ $i ] = offer( $i, 5, 'Never Seen ' . $i );
}
$wpdb->offers[999] = offer( 999, 5, 'Example-Tz 5mg' );
$r = Supcomp_Rematcher::rematch_unmatched();
check( 'cursor walks past a full chunk of misses', array( 'checked' => Supcomp_Rematcher::CHUNK_SIZE + 6, 'matched' => 1, 'complete' => true ), $r );
check( 'late offer matched', '10', (string) $wpdb->offers[999]->canonical_product_id );

// Q-020: a field the operator cleared stays blank even though the canonical has a value.
reset_state();
Supcomp_Match_Memory_Repo::record( 5, 'example tz', 10, 'Example-Tz', 'approval' );
$wpdb->offers[40] = offer( 40, 5, 'Example-Tz 10mg', array( 'operator_fields_json' => '["ingredient_id"]' ) );
Supcomp_Rematcher::rematch_unmatched( array( 40 ) );
check( 'operator-cleared offer still gets a canonical', '10', (string) $wpdb->offers[40]->canonical_product_id );
check( 'operator-cleared ingredient NOT refilled', null, $wpdb->offers[40]->ingredient_id );

check( 'non-canonical data_changed ignored', null, Supcomp_Rematcher::on_data_changed( array( 'source' => 'offer_form' ) ) );

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures ? 1 : 0 );
