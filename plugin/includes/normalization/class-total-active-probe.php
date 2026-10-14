<?php
/**
 * Total-active diagnostic probe (v1.42.0).
 *
 * Read-only analysis used by the Total Active Diagnostic screen. Given an
 * offer whose total active / container is already known (operator-entered or
 * approved), it reports where in the merchant's raw data that total can be
 * found and whether today's normalizer (strength × servings) reproduces it.
 * The point is to measure which source patterns are common before building
 * Total-active auto-extraction, not to extract anything.
 *
 * Pure: no DB, no WordPress calls. The screen feeds it the offer's current
 * normalizer output and its latest raw CSV row.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Total_Active_Probe {

	/** Relative tolerance for "same amount" (rounding on labels, g ↔ mg). */
	const TOLERANCE = 0.01;

	const CONTEXT_RADIUS  = 25;
	const SNIPPET_RADIUS  = 40;

	const TOTAL_TERMS = array(
		'total', 'per bottle', 'per container', 'per package', 'per jar',
		'per bag', 'per box', 'per tub', 'per pouch', 'per vial', 'net wt',
		'net weight', 'net contents', 'contains',
	);

	// Number: "12,000", "1.5", "200". Unit words longest-first so "mg" wins over "g".
	const AMOUNT_PATTERN = '/(?<![\d.,])(\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?)\s*(billion\s*cfu|milligrams?|micrograms?|kilograms?|grams?|mcg|µg|ug|mg|kg|iu|g)(?![a-z])/iu';

	// "2 x 10mg", "2×10 mg" (count first) and "10mg x 2" (amount first).
	const PACK_COUNT_FIRST  = '/(?<![\d.,])(\d{1,3})\s*[x×]\s*(\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?)\s*(milligrams?|micrograms?|grams?|mcg|µg|ug|mg|iu|g)(?![a-z])/iu';
	const PACK_AMOUNT_FIRST = '/(?<![\d.,])(\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?)\s*(milligrams?|micrograms?|grams?|mcg|µg|ug|mg|iu|g)\s*[x×]\s*(\d{1,3})(?![\d.])/iu';

	/**
	 * @param array $in {
	 *     truth: float, truth_unit: string,
	 *     product_title, variant_title, description, raw_attributes_json: string,
	 *     current_strength: float|null, current_strength_unit: string|null,
	 *     current_servings: int|null
	 * }
	 * @return array{
	 *     current_total: float|null,
	 *     current_status: string,      ok | wrong | missing
	 *     strength_is_total: bool,     today's strength already equals the total (then gets × servings)
	 *     found_in: string[],          subset of title, attribute, pack, description
	 *     best_source: string,         title | attribute | pack | description | per_serving_x_count | not_found
	 *     attribute_key: string,
	 *     context_says_total: bool,    the best hit sits next to "total" / "per bottle" / …
	 *     snippet: string
	 * }
	 */
	public static function probe( array $in ) {
		$truth      = isset( $in['truth'] ) ? (float) $in['truth'] : 0.0;
		$truth_unit = self::canonical_unit( isset( $in['truth_unit'] ) ? (string) $in['truth_unit'] : '' );

		$out = array(
			'current_total'      => null,
			'current_status'     => 'missing',
			'strength_is_total'  => false,
			'found_in'           => array(),
			'best_source'        => 'not_found',
			'attribute_key'      => '',
			'context_says_total' => false,
			'snippet'            => '',
		);
		if ( $truth <= 0 || $truth_unit === null ) {
			return $out;
		}

		// --- What the normalizer does today: strength × servings.
		$servings = isset( $in['current_servings'] ) && $in['current_servings'] !== null ? (int) $in['current_servings'] : 0;
		$strength = null;
		if ( isset( $in['current_strength'] ) && $in['current_strength'] !== null && $in['current_strength'] !== '' ) {
			$s_unit   = self::canonical_unit( isset( $in['current_strength_unit'] ) && $in['current_strength_unit'] ? (string) $in['current_strength_unit'] : (string) $in['truth_unit'] );
			$strength = $s_unit !== null ? self::convert( (float) $in['current_strength'], $s_unit, $truth_unit ) : null;
		}
		if ( $strength !== null && $servings > 0 ) {
			$out['current_total']  = round( $strength * $servings, 4 );
			$out['current_status'] = self::same( $out['current_total'], $truth ) ? 'ok' : 'wrong';
		}
		if ( $strength !== null && $servings > 1 && self::same( $strength, $truth ) ) {
			$out['strength_is_total'] = true;
		}

		// --- Where an explicit total appears in the raw data.
		$titles = trim( (string) ( $in['variant_title'] ?? '' ) . ' | ' . (string) ( $in['product_title'] ?? '' ), ' |' );
		$desc   = (string) ( $in['description'] ?? '' );
		$pairs  = self::attribute_pairs( (string) ( $in['raw_attributes_json'] ?? '' ) );

		$hits = array(); // source => array(snippet, context_says_total, attribute_key)

		$h = self::find_amount( $titles, $truth, $truth_unit );
		if ( $h ) {
			$hits['title'] = $h;
		}
		foreach ( $pairs as $pair ) {
			$h = self::find_amount( $pair[1], $truth, $truth_unit );
			if ( $h ) {
				$h['attribute_key'] = $pair[0];
				$hits['attribute']  = $h;
				break;
			}
		}
		$attr_text = implode( ' | ', array_map( static function ( $p ) { return $p[1]; }, $pairs ) );
		foreach ( array( $titles, $attr_text, $desc ) as $text ) {
			$h = self::find_pack( $text, $truth, $truth_unit );
			if ( $h ) {
				$hits['pack'] = $h;
				break;
			}
		}
		$h = self::find_amount( $desc, $truth, $truth_unit );
		if ( $h ) {
			$hits['description'] = $h;
		}

		$out['found_in'] = array_keys( $hits );
		foreach ( array( 'title', 'attribute', 'pack', 'description' ) as $source ) {
			if ( isset( $hits[ $source ] ) ) {
				$out['best_source']        = $source;
				$out['snippet']            = $hits[ $source ]['snippet'];
				$out['context_says_total'] = $hits[ $source ]['context_says_total'];
				$out['attribute_key']      = isset( $hits[ $source ]['attribute_key'] ) ? $hits[ $source ]['attribute_key'] : '';
				return $out;
			}
		}

		// No explicit total anywhere: could some per-serving figure × the
		// parsed count reach it (i.e. the normalizer just picked the wrong number)?
		if ( $servings > 0 ) {
			foreach ( array( $titles, $attr_text, $desc ) as $text ) {
				foreach ( self::scan_amounts( $text ) as $a ) {
					$v = self::convert( $a['value'], $a['unit'], $truth_unit );
					if ( $v !== null && self::same( $v * $servings, $truth ) ) {
						$out['best_source'] = 'per_serving_x_count';
						$out['snippet']     = self::snippet( $text, $a['offset'], $a['length'] );
						return $out;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Every "<number> <unit>" in $text, with commas handled ("12,000 mg").
	 *
	 * @return array<int, array{value: float, unit: string, offset: int, length: int}>
	 */
	public static function scan_amounts( $text ) {
		$out = array();
		if ( ! is_string( $text ) || $text === '' ) {
			return $out;
		}
		if ( preg_match_all( self::AMOUNT_PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $m ) {
				$unit = self::canonical_unit( $m[2][0] );
				if ( $unit === null ) {
					continue;
				}
				$out[] = array(
					'value'  => (float) str_replace( ',', '', $m[1][0] ),
					'unit'   => $unit,
					'offset' => $m[0][1],
					'length' => strlen( $m[0][0] ),
				);
			}
		}
		return $out;
	}

	/**
	 * Pack notation totals: "2 x 10mg" → 20 mg.
	 *
	 * @return array<int, array{value: float, unit: string, offset: int, length: int}>
	 */
	public static function scan_packs( $text ) {
		$out = array();
		if ( ! is_string( $text ) || $text === '' ) {
			return $out;
		}
		foreach ( array( self::PACK_COUNT_FIRST => array( 1, 2, 3 ), self::PACK_AMOUNT_FIRST => array( 3, 1, 2 ) ) as $pattern => $idx ) {
			if ( ! preg_match_all( $pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $matches as $m ) {
				$unit  = self::canonical_unit( $m[ $idx[2] ][0] );
				$count = (int) $m[ $idx[0] ][0];
				if ( $unit === null || $count < 2 ) {
					continue;
				}
				$out[] = array(
					'value'  => $count * (float) str_replace( ',', '', $m[ $idx[1] ][0] ),
					'unit'   => $unit,
					'offset' => $m[0][1],
					'length' => strlen( $m[0][0] ),
				);
			}
		}
		return $out;
	}

	/**
	 * Flatten raw_attributes_json into (label, value) pairs, keeping the
	 * attribute name a value belongs to. Handles the shapes the in-plugin
	 * extractors emit: name/value objects (Woo variation attributes, JSON-LD
	 * additionalProperty), name + terms/options/values lists (Woo product
	 * attributes, Shopify options), and Shopify optionN keys (mapped back to
	 * the option's name). Anything else is labelled with its JSON key path.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function attribute_pairs( $json ) {
		if ( ! is_string( $json ) || $json === '' ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		// Shopify: variant_options { option1: "60 Capsules" } → options[0].name.
		$option_names = array();
		if ( isset( $decoded['options'] ) && is_array( $decoded['options'] ) ) {
			foreach ( array_values( $decoded['options'] ) as $i => $opt ) {
				if ( is_array( $opt ) && isset( $opt['name'] ) && is_string( $opt['name'] ) ) {
					$option_names[ 'option' . ( $i + 1 ) ] = $opt['name'];
				}
			}
		}

		$pairs = array();
		self::walk_pairs( $decoded, '', $option_names, $pairs, 0 );
		return $pairs;
	}

	private static function walk_pairs( $node, $label, array $option_names, array &$pairs, $depth ) {
		if ( $depth > 6 ) {
			return;
		}
		if ( is_scalar( $node ) ) {
			if ( is_string( $node ) && $node !== '' && ! is_bool( $node ) ) {
				$pairs[] = array( $label, $node );
			}
			return;
		}
		if ( ! is_array( $node ) ) {
			return;
		}

		// Named attribute object: label every value-ish child with the name.
		if ( isset( $node['name'] ) && is_string( $node['name'] ) && $node['name'] !== '' ) {
			$value_keys = array( 'value', 'values', 'option', 'options', 'terms' );
			$present    = array_intersect( $value_keys, array_keys( $node ) );
			if ( $present ) {
				foreach ( $present as $vk ) {
					self::collect_values( $node[ $vk ], $node['name'], $pairs, $depth + 1 );
				}
				return;
			}
		}

		foreach ( $node as $key => $child ) {
			$child_label = is_string( $key ) ? $key : $label;
			if ( is_string( $key ) && isset( $option_names[ $key ] ) ) {
				$child_label = $option_names[ $key ];
			}
			self::walk_pairs( $child, $child_label, $option_names, $pairs, $depth + 1 );
		}
	}

	/** Values under a named attribute: strings, or term objects with a name. */
	private static function collect_values( $node, $label, array &$pairs, $depth ) {
		if ( $depth > 6 ) {
			return;
		}
		if ( is_string( $node ) || is_int( $node ) || is_float( $node ) ) {
			if ( (string) $node !== '' ) {
				$pairs[] = array( $label, (string) $node );
			}
			return;
		}
		if ( ! is_array( $node ) ) {
			return;
		}
		if ( isset( $node['name'] ) && is_string( $node['name'] ) ) {
			$pairs[] = array( $label, $node['name'] );
			return;
		}
		foreach ( $node as $child ) {
			self::collect_values( $child, $label, $pairs, $depth + 1 );
		}
	}

	/**
	 * mg / mcg / g / kg convert among themselves; IU and billion CFU only
	 * match themselves.
	 */
	public static function convert( $value, $from, $to ) {
		if ( $from === $to ) {
			return (float) $value;
		}
		$mass = array( 'mcg' => 0.001, 'mg' => 1.0, 'g' => 1000.0, 'kg' => 1000000.0 );
		if ( isset( $mass[ $from ], $mass[ $to ] ) ) {
			return (float) $value * $mass[ $from ] / $mass[ $to ];
		}
		return null;
	}

	public static function canonical_unit( $raw ) {
		$u = strtolower( preg_replace( '/\s+/', ' ', trim( (string) $raw ) ) );
		if ( $u === '' ) {
			return null;
		}
		if ( strpos( $u, 'billion' ) === 0 || $u === 'billion_cfu' ) {
			return 'billion_cfu';
		}
		if ( $u === 'iu' ) {
			return 'IU';
		}
		if ( in_array( $u, array( 'mcg', 'µg', 'ug' ), true ) || strpos( $u, 'microgram' ) === 0 ) {
			return 'mcg';
		}
		if ( $u === 'mg' || strpos( $u, 'milligram' ) === 0 ) {
			return 'mg';
		}
		if ( $u === 'kg' || strpos( $u, 'kilogram' ) === 0 ) {
			return 'kg';
		}
		if ( $u === 'g' || strpos( $u, 'gram' ) === 0 ) {
			return 'g';
		}
		return null;
	}

	private static function same( $a, $b ) {
		$b = (float) $b;
		if ( $b == 0.0 ) {
			return false;
		}
		return abs( (float) $a - $b ) / abs( $b ) <= self::TOLERANCE;
	}

	private static function find_amount( $text, $truth, $truth_unit ) {
		foreach ( self::scan_amounts( $text ) as $a ) {
			$v = self::convert( $a['value'], $a['unit'], $truth_unit );
			if ( $v !== null && self::same( $v, $truth ) ) {
				return self::hit( $text, $a );
			}
		}
		return null;
	}

	private static function find_pack( $text, $truth, $truth_unit ) {
		foreach ( self::scan_packs( $text ) as $a ) {
			$v = self::convert( $a['value'], $a['unit'], $truth_unit );
			if ( $v !== null && self::same( $v, $truth ) ) {
				return self::hit( $text, $a );
			}
		}
		return null;
	}

	private static function hit( $text, array $a ) {
		$start   = max( 0, $a['offset'] - self::CONTEXT_RADIUS );
		$context = strtolower( substr( $text, $start, $a['length'] + 2 * self::CONTEXT_RADIUS ) );
		$says    = false;
		foreach ( self::TOTAL_TERMS as $term ) {
			if ( strpos( $context, $term ) !== false ) {
				$says = true;
				break;
			}
		}
		return array(
			'snippet'            => self::snippet( $text, $a['offset'], $a['length'] ),
			'context_says_total' => $says,
		);
	}

	private static function snippet( $text, $offset, $length ) {
		$start = max( 0, $offset - self::SNIPPET_RADIUS );
		$end   = min( strlen( $text ), $offset + $length + self::SNIPPET_RADIUS );
		$s     = substr( $text, $start, $end - $start );
		// Byte offsets can split a multibyte character at the edges, and
		// esc_html() blanks invalid UTF-8 entirely — scrub before returning.
		$s     = function_exists( 'mb_scrub' ) ? mb_scrub( $s, 'UTF-8' ) : $s;
		$s     = preg_replace( '/\s+/u', ' ', $s );
		return ( $start > 0 ? '…' : '' ) . trim( $s ) . ( $end < strlen( $text ) ? '…' : '' );
	}
}
