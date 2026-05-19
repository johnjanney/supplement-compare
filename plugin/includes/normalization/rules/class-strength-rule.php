<?php
/**
 * Strength extraction rule. Recognizes patterns like "200mg", "200 mg",
 * "1.5 g", "50 mcg", "1000 IU", "100 billion CFU".
 *
 * Picks the LEFT-MOST match in the text (titles tend to read
 * "<brand> <ingredient> <strength> <form>" so the strength is to the left
 * of the form word). Caller is responsible for concatenating text fields
 * in an order that puts the most authoritative first (variant_title +
 * product_title + description).
 *
 * Misses are silent — when nothing matches, returns null and the operator
 * fills the field in via the pending queue (Phase 6).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Strength_Rule {

	/**
	 * @return array{value: float, unit: string}|null
	 */
	public static function extract( $text ) {
		if ( ! is_string( $text ) || $text === '' ) {
			return null;
		}

		// "100 billion CFU" (probiotic dosage). Check BEFORE the bare-unit regex
		// because the bare regex would otherwise match the "100" as e.g. 100 mg.
		if ( preg_match( '/(\d+(?:\.\d+)?)\s*billion\s*cfu/i', $text, $m ) ) {
			return array(
				'value' => (float) $m[1],
				'unit'  => 'billion_cfu',
			);
		}

		// "N (mg|mcg|g|IU)" with optional space, case-insensitive. Word-boundary
		// on the unit so "100mg" matches but "100mgz" doesn't, and so "1g" isn't
		// extracted from "1gallon".
		if ( preg_match_all( '/(\d+(?:\.\d+)?)\s*(mg|mcg|g|iu)\b/i', $text, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$unit = strtolower( $m[2] );
				// Skip "g" if value is suspiciously high (likely a count or
				// percentage misread). 99g per serving is plausible for some
				// powders but more than 1000g is almost certainly a parsing
				// mistake — leave it for the operator.
				$value = (float) $m[1];
				if ( $unit === 'g' && $value > 1000 ) {
					continue;
				}
				return array(
					'value' => $value,
					'unit'  => $unit === 'iu' ? 'IU' : $unit,
				);
			}
		}

		// "milligrams" / "micrograms" / "grams" — less common but seen on Woo.
		if ( preg_match( '/(\d+(?:\.\d+)?)\s*(milligrams?|micrograms?|grams?)\b/i', $text, $m ) ) {
			$word = strtolower( $m[2] );
			$unit = 'mg';
			if ( str_starts_with( $word, 'micro' ) ) {
				$unit = 'mcg';
			} elseif ( str_starts_with( $word, 'gram' ) ) {
				$unit = 'g';
			}
			return array(
				'value' => (float) $m[1],
				'unit'  => $unit,
			);
		}

		return null;
	}
}
