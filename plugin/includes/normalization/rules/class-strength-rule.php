<?php
/**
 * Strength extraction rule. Recognizes patterns like "200mg", "200 mg",
 * "1.5 g", "50 mcg", "1000 IU", "100 billion CFU".
 *
 * Picks the match most likely to be the per-serving dose by scoring nearby
 * context — positive when "per serving"/"each capsule"/"per dose" appears
 * nearby, negative when "total"/"per bottle"/"per container" appears nearby.
 * This prevents container-total figures (e.g. "12,000 mg total per bottle"
 * on a 60-count product) from contaminating `strength_per_serving` and
 * cascading into a wildly low `cost_per_active_unit`.
 *
 * Misses are silent — when nothing matches, returns null and the operator
 * fills the field in via the pending queue.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Strength_Rule {

	private const CONTEXT_RADIUS = 25;

	private const POSITIVE_TERMS = array(
		'per serving',
		'per dose',
		'per capsule',
		'per tablet',
		'per softgel',
		'per gummy',
		'per scoop',
		'each capsule',
		'each tablet',
		'each softgel',
		'each gummy',
		'each scoop',
		'each serving',
		'1 capsule',
		'1 tablet',
		'1 softgel',
		'1 scoop',
		'1 serving',
		'one capsule',
		'one tablet',
		'one softgel',
		'one scoop',
		'one serving',
	);

	private const NEGATIVE_TERMS = array(
		'total',
		'per bottle',
		'per container',
		'per package',
		'per jar',
		'per bag',
		'per box',
		'per tub',
		'per pouch',
		'net wt',
		'net weight',
		'net contents',
		'bottle contains',
		'container contains',
		'jar contains',
		'package contains',
	);

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

		$lower = strtolower( $text );
		$best  = self::best_mass_match( $text, $lower, '/(\d+(?:\.\d+)?)\s*(mg|mcg|g|iu)\b/i' );
		if ( $best !== null ) {
			$unit = strtolower( $best['unit'] );
			return array(
				'value' => $best['value'],
				'unit'  => $unit === 'iu' ? 'IU' : $unit,
			);
		}

		// "milligrams" / "micrograms" / "grams" — less common but seen on Woo.
		$best = self::best_mass_match( $text, $lower, '/(\d+(?:\.\d+)?)\s*(milligrams?|micrograms?|grams?)\b/i' );
		if ( $best !== null ) {
			$word = strtolower( $best['unit'] );
			$unit = 'mg';
			if ( str_starts_with( $word, 'micro' ) ) {
				$unit = 'mcg';
			} elseif ( str_starts_with( $word, 'gram' ) ) {
				$unit = 'g';
			}
			return array(
				'value' => $best['value'],
				'unit'  => $unit,
			);
		}

		return null;
	}

	/**
	 * Find all matches for $pattern, score each by surrounding context, and
	 * return the highest-scoring candidate. Ties broken by leftmost match
	 * (titles tend to read "<brand> <ingredient> <strength> <form>").
	 *
	 * @return array{value: float, unit: string, score: int, offset: int}|null
	 */
	private static function best_mass_match( $text, $lower, $pattern ) {
		if ( ! preg_match_all( $pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$best = null;
		foreach ( $matches as $m ) {
			$value      = (float) $m[1][0];
			$unit_raw   = $m[2][0];
			$offset     = $m[0][1];
			$unit_lower = strtolower( $unit_raw );

			// Suspiciously high "g" values are almost always a parsing mistake
			// (e.g. a count misread). Skip rather than store a wild value.
			if ( $unit_lower === 'g' && $value > 1000 ) {
				continue;
			}

			$score = self::score_context( $lower, $offset );

			// Container-total contamination is the primary failure mode this
			// rule is guarding against — drop strongly-negative matches outright
			// so the operator's pending-queue fix isn't pre-seeded with a wrong
			// number that compounds through derivations.
			if ( $score <= -5 ) {
				continue;
			}

			if ( $best === null
				|| $score > $best['score']
				|| ( $score === $best['score'] && $offset < $best['offset'] )
			) {
				$best = array(
					'value'  => $value,
					'unit'   => $unit_raw,
					'score'  => $score,
					'offset' => $offset,
				);
			}
		}

		return $best;
	}

	private static function score_context( $lower, $offset ) {
		$start  = max( 0, $offset - self::CONTEXT_RADIUS );
		$len    = self::CONTEXT_RADIUS * 2;
		$window = substr( $lower, $start, $len );

		$score = 0;
		foreach ( self::POSITIVE_TERMS as $term ) {
			if ( strpos( $window, $term ) !== false ) {
				$score += 10;
				break;
			}
		}
		foreach ( self::NEGATIVE_TERMS as $term ) {
			if ( strpos( $window, $term ) !== false ) {
				$score -= 10;
				break;
			}
		}
		return $score;
	}
}
