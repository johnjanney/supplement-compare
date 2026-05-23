<?php
/**
 * Standardization extraction. Returns the percentage and optionally the
 * marker compound name.
 *
 * Patterns recognized:
 *   - "50% bacosides"                      → ('pct' => 50.0, 'compound' => 'bacosides')
 *   - "(50% bacosides)"                    → same
 *   - "standardized to 50% bacosides"      → same
 *   - "standardized to 50%"                → ('pct' => 50.0, 'compound' => null)
 *
 * False positives to guard against:
 *   - "100% organic" / "100% vegan" / "100% natural" — purity claims, not
 *     standardization. We skip when the following word is one of these.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Standardization_Rule {

	const PURITY_WORDS = array(
		'organic', 'vegan', 'natural', 'pure', 'gmo', 'gluten', 'soy',
		'dairy', 'free', 'allergen', 'kosher', 'halal', 'vegetarian',
	);

	/**
	 * @return array{pct: float, compound: string|null}|null
	 */
	public static function extract( $text ) {
		if ( ! is_string( $text ) || $text === '' ) {
			return null;
		}

		// "standardized to N% compound?"
		if ( preg_match( '/standardized\s+to\s+(\d+(?:\.\d+)?)\s*%(?:\s+([a-zA-Z][\w-]+))?/i', $text, $m ) ) {
			$pct      = (float) $m[1];
			$compound = isset( $m[2] ) ? strtolower( $m[2] ) : null;
			if ( $compound !== null && in_array( $compound, self::PURITY_WORDS, true ) ) {
				$compound = null;
			}
			return array(
				'pct'      => $pct,
				'compound' => $compound,
			);
		}

		// "N% compound" — must be followed by a non-purity word to qualify as
		// a standardization claim (otherwise "100% organic" would trigger).
		if ( preg_match_all( '/(\d+(?:\.\d+)?)\s*%\s+([a-zA-Z][\w-]+)/u', $text, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$compound = strtolower( $m[2] );
				if ( in_array( $compound, self::PURITY_WORDS, true ) ) {
					continue;
				}
				return array(
					'pct'      => (float) $m[1],
					'compound' => $compound,
				);
			}
		}

		return null;
	}
}
