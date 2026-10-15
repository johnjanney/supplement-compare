<?php
/**
 * Count / servings_per_container extraction. Matches patterns like
 * "60 capsules", "120 tablets", "30 ct", "60 servings", "60 count".
 *
 * Returns the first match. Counts in the wild range from 1 (single dose
 * trial bottles) to ~500 (bulk powder scoops). Anything ≥ 5000 is treated
 * as suspicious and skipped — likely a parsed price or strength misread.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Count_Rule {

	const VESSEL_WORDS = '(?:capsules?|caps?|tablets?|tabs?|softgels?|sgels?|servings?|servs?|gummies|count|ct|pieces|doses?|scoops?)';

	const MAX_PLAUSIBLE = 5000;

	/**
	 * @return int|null
	 */
	public static function extract( $text ) {
		if ( ! is_string( $text ) || $text === '' ) {
			return null;
		}

		// "60 capsules", "60-count", "60ct", "60 ct"
		if ( preg_match_all( '/(\d+)[\s\-]*' . self::VESSEL_WORDS . '\b/i', $text, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$n = (int) $m[1];
				if ( $n > 0 && $n < self::MAX_PLAUSIBLE ) {
					return $n;
				}
			}
		}

		// "x60" / "× 60" (some merchants use these to denote count). The
		// x must stand alone — not the tail of a word or code — and the
		// number must not run on into a date or decimal. "/60" used to count
		// too, but it read dates ("10/14/2025") and URLs as 2025 servings.
		// A number followed by a unit is an amount, not a count ("2 x 10mg").
		if ( preg_match( '/(?<![a-z0-9\-])[x×]\s*(\d{1,4})(?![\d.,\/\-])(?!\s*(?:mg|mcg|µg|g|iu|ml)\b)/iu', $text, $m ) ) {
			$n = (int) $m[1];
			if ( $n > 0 && $n < self::MAX_PLAUSIBLE ) {
				return $n;
			}
		}

		return null;
	}
}
