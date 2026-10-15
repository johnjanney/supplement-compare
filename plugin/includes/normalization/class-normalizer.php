<?php
/**
 * Normalization orchestrator.
 *
 * Takes the CSV-direct columns of a single offer (product_title,
 * variant_title, brand, sku, barcode, description, raw_attributes_json),
 * runs the four built-in rules, and matches text against the canonical
 * ingredients table to suggest an ingredient_id.
 *
 * Returns a flat array of normalized fields ready to write to
 * `normalized_offers`. Any field the rules couldn't determine comes back
 * as null — the operator fills those in via the pending queue.
 *
 * What's NOT here:
 *   - Operator-editable rules / per-merchant overrides — deferred to a
 *     later sub-phase. Built-in rules + Phase 6's manual edit cover the
 *     common cases.
 *   - Canonical_product matching — see Supcomp_Matcher.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Normalizer {

	/**
	 * @param array $offer  Either a CSV row or an existing offer row as
	 *                      stdClass/array. Read fields: product_title,
	 *                      variant_title, brand, sku, description,
	 *                      raw_attributes_json.
	 * @return array{
	 *     ingredient_id: int|null,
	 *     ingredient_form: string|null,
	 *     strength_per_serving: float|null,
	 *     strength_unit: string|null,
	 *     servings_per_container: int|null,
	 *     standardization_percentage: float|null,
	 * }
	 */
	public static function normalize( $offer ) {
		$text = self::collect_text( $offer );

		// Variant-title strength wins when present. Each variant of a
		// strength-typed variable product (e.g. 10 MG vs 20 MG vs 50 MG)
		// carries its own dose in variant_title; the parent's
		// product_title and description are shared across all variants and
		// often have a strength figure with positive context that would
		// out-score the bare variant_title number in the full-text scan,
		// pinning every variant to the same wrong mg.
		$variant_title = self::decode_entities( self::field( $offer, 'variant_title' ) );
		$strength      = $variant_title !== '' ? Supcomp_Strength_Rule::extract( $variant_title ) : null;
		if ( $strength === null ) {
			$strength = Supcomp_Strength_Rule::extract( $text );
		}
		$count           = Supcomp_Count_Rule::extract( $text );
		$form            = Supcomp_Form_Rule::extract( $text );
		$standardization = Supcomp_Standardization_Rule::extract( $text );
		$ingredient_id   = self::match_ingredient( $text );

		return array(
			'ingredient_id'              => $ingredient_id,
			'ingredient_form'            => $form,
			'strength_per_serving'       => isset( $strength['value'] ) ? (float) $strength['value'] : null,
			'strength_unit'              => isset( $strength['unit'] ) ? (string) $strength['unit'] : null,
			'servings_per_container'     => $count !== null ? (int) $count : null,
			'standardization_percentage' => isset( $standardization['pct'] ) ? (float) $standardization['pct'] : null,
		);
	}

	/**
	 * Concatenate the fields the rules look at, in priority order. Variant
	 * title and product title go first because they are typically the most
	 * authoritative; description trails because it's marketing copy.
	 */
	private static function collect_text( $offer ) {
		$parts = array(
			self::decode_entities( self::field( $offer, 'variant_title' ) ),
			self::decode_entities( self::field( $offer, 'product_title' ) ),
			self::decode_entities( self::field( $offer, 'description' ) ),
		);

		// Flatten raw_attributes_json: pull out top-level string values so
		// e.g. a "Dosage: 200mg" attribute is still searchable.
		$raw = self::field( $offer, 'raw_attributes_json' );
		if ( $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$parts[] = self::decode_entities( self::flatten_for_search( $decoded ) );
			}
		}

		return implode( ' | ', array_filter( $parts, 'strlen' ) );
	}

	private static function field( $offer, $name ) {
		if ( is_array( $offer ) ) {
			return isset( $offer[ $name ] ) ? (string) $offer[ $name ] : '';
		}
		if ( is_object( $offer ) ) {
			return isset( $offer->$name ) ? (string) $offer->$name : '';
		}
		return '';
	}

	/**
	 * JSON keys whose subtrees never describe the product's dose: shipping
	 * weight and dimensions, prices, ratings, links. Matched lowercase.
	 * A shipping weight like {"value": 751.9, "unitText": "g"} would
	 * otherwise read as a 751.9 g strength.
	 */
	const FLATTEN_SKIP_KEYS = array(
		'weight', 'shippingweight', 'shippingdetails', 'height', 'width',
		'depth', 'length', 'dimensions', 'price', 'prices', 'pricespecification',
		'aggregaterating', 'review', 'reviews', 'url', 'permalink', 'image', 'images',
	);

	/**
	 * Walk a JSON structure and join its string leaf values with " | ".
	 * Used to expose attribute values to the rule scanners without binding
	 * the rules to platform-specific JSON shapes.
	 *
	 * Leaves are joined with a separator, not a bare space, so a number from
	 * one field can't fuse with a unit from the next into a fake amount.
	 * Bare numeric leaves (ids, positions, prices) are dropped for the same
	 * reason; a {"value": N, "unitText": "mg"} pair is kept as one "N mg".
	 */
	private static function flatten_for_search( $node, $depth = 0 ) {
		if ( $depth > 6 ) {
			return '';
		}
		if ( is_string( $node ) ) {
			return $node;
		}
		if ( ! is_array( $node ) ) {
			return '';
		}
		$paired = self::numeric_unit_pairs( $node );
		$parts  = array_values( $paired );
		foreach ( $node as $key => $v ) {
			if ( is_string( $key ) && ( in_array( strtolower( $key ), self::FLATTEN_SKIP_KEYS, true ) || isset( $paired[ $key ] ) ) ) {
				continue;
			}
			$parts[] = self::flatten_for_search( $v, $depth + 1 );
		}
		return implode( ' | ', array_filter( $parts, 'strlen' ) );
	}

	/**
	 * Numbers that carry their unit in a sibling field, joined into one
	 * "N unit" string: {"value": 10, "unitText": "mg"} (schema.org) and
	 * {"dosage": 50, "dosage_unit": "mg"} (JSON-API stores). Keyed by every
	 * key consumed, so callers can skip them.
	 *
	 * @return array<string, string> key => "N unit" (the unit key maps to '')
	 */
	public static function numeric_unit_pairs( array $node ) {
		$out = array();
		foreach ( $node as $key => $v ) {
			if ( ! is_string( $key ) || ! is_numeric( $v ) ) {
				continue;
			}
			$candidates = $key === 'value' ? array( 'unitText', 'unitCode' ) : array( $key . '_unit', $key . 'Unit', $key . '_units' );
			foreach ( $candidates as $uk ) {
				if ( isset( $node[ $uk ] ) && is_string( $node[ $uk ] ) && $node[ $uk ] !== '' ) {
					$out[ $key ] = $v . ' ' . $node[ $uk ];
					$out[ $uk ]  = '';
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Decode HTML entities ("&#8211;", "&amp;", "&#038;") in merchant text.
	 * Two passes cover the double-encoded "&amp;#8211;" some stores emit.
	 */
	public static function decode_entities( $text ) {
		$text = (string) $text;
		for ( $i = 0; $i < 2 && strpos( $text, '&' ) !== false; $i++ ) {
			$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return $text;
	}

	/**
	 * Decode entities in a CSV row's free-text fields. raw_attributes_json
	 * is left alone — decoding "&quot;" inside it would break the JSON; its
	 * values are decoded after json_decode in collect_text().
	 */
	public static function decode_row_text( array $row ) {
		foreach ( array( 'product_title', 'variant_title', 'brand', 'description' ) as $field ) {
			if ( isset( $row[ $field ] ) && is_string( $row[ $field ] ) ) {
				$row[ $field ] = self::decode_entities( $row[ $field ] );
			}
		}
		return $row;
	}

	/**
	 * Find the canonical ingredient whose name or alias appears in the
	 * text. Longest-match wins so "L-Theanine" beats "theanine". Returns
	 * the ingredient id or null when nothing matches.
	 *
	 * The candidate set comes from Supcomp_Ingredients_Repo::all_for_matching()
	 * which caches in a static for the duration of the request.
	 */
	public static function match_ingredient( $text ) {
		if ( ! is_string( $text ) || $text === '' ) {
			return null;
		}

		$lower      = strtolower( $text );
		$candidates = Supcomp_Ingredients_Repo::all_for_matching();

		$best_id     = null;
		$best_length = 0;

		foreach ( $candidates as $row ) {
			$names = array( $row['name'] );
			foreach ( $row['aliases'] as $alias ) {
				$names[] = $alias;
			}
			foreach ( $names as $name ) {
				$name = (string) $name;
				if ( $name === '' ) {
					continue;
				}
				$pattern = '/(?<![a-z0-9])' . preg_quote( strtolower( $name ), '/' ) . '(?![a-z0-9])/i';
				if ( preg_match( $pattern, $lower ) === 1 ) {
					$len = strlen( $name );
					if ( $len > $best_length ) {
						$best_length = $len;
						$best_id     = (int) $row['id'];
					}
				}
			}
		}

		return $best_id;
	}
}
