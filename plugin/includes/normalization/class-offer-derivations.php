<?php
/**
 * Offer-level derived field computation — PROJECTBRIEF.md §6.
 *
 * Pure function over (offer state, ingredient, optional canonical_product).
 *
 * Two equally-valid input modes per v1.15.0:
 *
 *   (A) Total-first (preferred): operator enters total_active_per_container.
 *       total_strength             = total_active_per_container
 *       active_compound_total      = total × pct/100
 *       active_compound_per_serving= active_compound_total / servings  (if servings)
 *       cost_per_active_unit       = current_price / active_compound_total
 *       (cost_per_serving requires servings; Total alone is enough for the
 *       per-container comparison columns.)
 *
 *   (B) Per-serving (legacy): operator enters strength_per_serving + servings.
 *       total_strength             = strength × servings
 *       active_compound_per_serving= strength × pct/100
 *       active_compound_total      = active_per_serving × servings
 *       cost_per_active_unit       = current_price / active_compound_total
 *
 * Mode A wins when total_active_per_container is set; otherwise falls back
 * to mode B. The pct multiplier (standardization) is resolved identically
 * in both modes: operator override → canonical std% → ingredient std% →
 * ingredient elemental% → 100%.
 *
 * Called by the importer on every CSV insert/update so price-driven fields
 * stay current. NULL inputs propagate (any missing piece → null output).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Offer_Derivations {

	/**
	 * @param array  $offer       Merged offer fields (CSV columns + normalized
	 *                            columns). Must include: current_price,
	 *                            strength_per_serving, servings_per_container,
	 *                            standardization_percentage.
	 * @param object|null $ingredient   Row from canonical_ingredients (or null).
	 * @return array<string,float|null> Keys: total_strength,
	 *                                   active_compound_per_serving,
	 *                                   active_compound_total,
	 *                                   cost_per_serving,
	 *                                   cost_per_active_unit
	 */
	public static function compute( $offer, $ingredient = null ) {
		$strength = self::nf( $offer, 'strength_per_serving' );
		$servings = self::ni( $offer, 'servings_per_container' );
		$total    = self::nf( $offer, 'total_active_per_container' );
		$price    = self::nf( $offer, 'current_price' );
		$pct      = self::resolve_pct( $offer, $ingredient );

		$out = array(
			'total_strength'              => null,
			'active_compound_per_serving' => null,
			'active_compound_total'       => null,
			'cost_per_serving'            => null,
			'cost_per_active_unit'        => null,
		);

		if ( $total !== null && $total > 0 ) {
			// Mode A: total_active_per_container is authoritative. Servings
			// becomes optional — the per-container columns work without it.
			$out['total_strength']        = round( $total, 4 );
			$out['active_compound_total'] = round( $total * ( $pct !== null ? $pct / 100 : 1 ), 4 );
			if ( $servings !== null && $servings > 0 ) {
				$out['active_compound_per_serving'] = round( $out['active_compound_total'] / $servings, 4 );
			}
		} else {
			// Mode B: derive total from per-serving × servings (legacy).
			if ( $strength !== null && $servings !== null && $servings > 0 ) {
				$out['total_strength'] = round( $strength * $servings, 4 );
			}
			if ( $strength !== null ) {
				$out['active_compound_per_serving'] = round( $strength * ( $pct !== null ? $pct / 100 : 1 ), 4 );
			}
			if ( $out['active_compound_per_serving'] !== null && $servings !== null && $servings > 0 ) {
				$out['active_compound_total'] = round( $out['active_compound_per_serving'] * $servings, 4 );
			}
		}

		if ( $price !== null && $servings !== null && $servings > 0 ) {
			$out['cost_per_serving'] = round( $price / $servings, 4 );
		}

		if ( $price !== null && $out['active_compound_total'] !== null && $out['active_compound_total'] > 0 ) {
			$out['cost_per_active_unit'] = round( $price / $out['active_compound_total'], 6 );
		}

		return $out;
	}

	/**
	 * Precedence: offer's standardization_percentage (operator-set/extracted)
	 * → ingredient's standardization_default_pct → ingredient's
	 * elemental_percentage → null (no scaling).
	 */
	private static function resolve_pct( $offer, $ingredient ) {
		$pct = self::nf( $offer, 'standardization_percentage' );
		if ( $pct !== null ) {
			return $pct;
		}
		if ( $ingredient ) {
			if ( isset( $ingredient->standardization_default_pct ) && $ingredient->standardization_default_pct !== null && $ingredient->standardization_default_pct !== '' ) {
				return (float) $ingredient->standardization_default_pct;
			}
			if ( isset( $ingredient->elemental_percentage ) && $ingredient->elemental_percentage !== null && $ingredient->elemental_percentage !== '' ) {
				return (float) $ingredient->elemental_percentage;
			}
		}
		return null;
	}

	private static function nf( $offer, $field ) {
		$v = self::raw( $offer, $field );
		if ( $v === null || $v === '' ) {
			return null;
		}
		return is_numeric( $v ) ? (float) $v : null;
	}

	private static function ni( $offer, $field ) {
		$v = self::raw( $offer, $field );
		if ( $v === null || $v === '' ) {
			return null;
		}
		return is_numeric( $v ) ? (int) $v : null;
	}

	private static function raw( $offer, $field ) {
		if ( is_array( $offer ) ) {
			return isset( $offer[ $field ] ) ? $offer[ $field ] : null;
		}
		if ( is_object( $offer ) ) {
			return isset( $offer->$field ) ? $offer->$field : null;
		}
		return null;
	}
}
