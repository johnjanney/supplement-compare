<?php
/**
 * Re-run canonical matching for offers still waiting without one (v1.41.0).
 *
 * The importer matches an offer once, on first insert (normalization stays
 * sticky across re-imports so operator edits survive). That left offers
 * imported before their canonical — or before a learned mapping — existed
 * stuck at "no match" forever. This class closes that gap.
 *
 * Scope is deliberately narrow:
 *   - Only offers in the queue (pending / needs_review).
 *   - Only offers with NO canonical. A blank canonical reliably means nobody
 *     has set one, so nothing the operator chose is ever overwritten.
 *   - Writes canonical_product_id + match_confidence. Ingredient, form,
 *     strength and standardization are filled from the canonical ONLY where
 *     the offer's own field is still blank and the operator hasn't cleared
 *     it by hand (operator_fields_json, Q-020).
 *
 * Triggered by: recording a learned mapping (targeted to that title key),
 * creating/editing/re-statusing/importing canonical products (full pass), and
 * the Pending Queue's "Re-run matching" bulk action / button.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Rematcher {

	// Offers fetched per query, and the wall-clock budget for one pass, so a
	// large queue can't time out an admin request.
	const CHUNK_SIZE     = 200;
	const TIME_BUDGET_S  = 20;

	const CANONICAL_SOURCES = array( 'canonical_save', 'canonical_status', 'canonical_csv_import' );

	/**
	 * supcomp_data_changed listener: a canonical changed, so offers that
	 * previously had nothing to match against may now match.
	 */
	public static function on_data_changed( $context = array() ) {
		$source = is_array( $context ) && isset( $context['source'] ) ? (string) $context['source'] : '';
		if ( in_array( $source, self::CANONICAL_SOURCES, true ) ) {
			self::rematch_unmatched();
		}
	}

	/**
	 * Walks the queue in id order (a cursor, not OFFSET, so offers that stay
	 * unmatched don't stall later ones) until it runs out of offers or time.
	 *
	 * @param int[]|null $ids Restrict to these offer ids; null = whole queue.
	 * @return array{checked:int, matched:int, complete:bool}
	 */
	public static function rematch_unmatched( $ids = null ) {
		global $wpdb;
		$table = Supcomp_Offers_Repo::table();
		$base  = "SELECT * FROM {$table}
				  WHERE visibility_status IN ('pending', 'needs_review')
				    AND canonical_product_id IS NULL
				    AND id > %d";
		$extra = array();
		if ( is_array( $ids ) ) {
			$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
			if ( empty( $ids ) ) {
				return array( 'checked' => 0, 'matched' => 0, 'complete' => true );
			}
			$base .= ' AND id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$extra = $ids;
		}
		$base .= ' ORDER BY id ASC LIMIT %d';

		$started  = microtime( true );
		$cursor   = 0;
		$checked  = 0;
		$matched  = 0;
		$complete = true;
		do {
			$params = array_merge( array( $cursor ), $extra, array( self::CHUNK_SIZE ) );
			$offers = (array) $wpdb->get_results( $wpdb->prepare( $base, $params ) );
			foreach ( $offers as $offer ) {
				$cursor = (int) $offer->id;
				++$checked;
				if ( self::rematch_offer( $offer ) ) {
					++$matched;
				}
				if ( microtime( true ) - $started > self::TIME_BUDGET_S ) {
					$complete = false;
					break 2;
				}
			}
		} while ( count( $offers ) === self::CHUNK_SIZE );

		return array(
			'checked'  => $checked,
			'matched'  => $matched,
			'complete' => $complete,
		);
	}

	/**
	 * Cheap targeted pass after mappings are learned: only offers whose title
	 * key is one of $keys go through the matcher.
	 *
	 * @param string[] $keys
	 * @return array{checked:int, matched:int, complete:bool}
	 */
	public static function rematch_for_keys( array $keys ) {
		global $wpdb;
		$keys = array_filter( array_map( 'strval', $keys ), 'strlen' );
		if ( empty( $keys ) ) {
			return array( 'checked' => 0, 'matched' => 0, 'complete' => true );
		}
		$wanted = array_fill_keys( $keys, true );

		$table = Supcomp_Offers_Repo::table();
		$rows  = $wpdb->get_results(
			"SELECT id, product_title FROM {$table}
			 WHERE visibility_status IN ('pending', 'needs_review')
			   AND canonical_product_id IS NULL"
		);
		$ids = array();
		foreach ( (array) $rows as $r ) {
			if ( isset( $wanted[ Supcomp_Match_Memory_Repo::key_for_title( $r->product_title ) ] ) ) {
				$ids[] = (int) $r->id;
			}
		}
		return $ids ? self::rematch_unmatched( $ids ) : array( 'checked' => 0, 'matched' => 0, 'complete' => true );
	}

	/**
	 * @param object $offer Full offer row.
	 * @return bool True when a canonical was suggested.
	 */
	private static function rematch_offer( $offer ) {
		global $wpdb;

		// Matcher input: the offer's stored values (which include any operator
		// edits), with a fresh normalizer pass filling only the blanks — e.g.
		// an ingredient added to the catalog after this offer was imported.
		$fresh      = Supcomp_Normalizer::normalize( $offer );
		$normalized = array();
		foreach ( $fresh as $field => $value ) {
			$stored               = isset( $offer->$field ) ? $offer->$field : null;
			$normalized[ $field ] = ( $stored !== null && $stored !== '' ) ? $stored : $value;
		}

		$match = Supcomp_Matcher::match( $offer, $normalized );
		if ( empty( $match['canonical_product_id'] ) ) {
			return false;
		}
		$canonical = Supcomp_Canonical_Products_Repo::get( (int) $match['canonical_product_id'] );
		if ( ! $canonical ) {
			return false;
		}

		$data = array(
			'canonical_product_id' => (int) $canonical->id,
			'match_confidence'     => $match['confidence'],
			'updated_at'           => current_time( 'mysql', true ),
		);
		$fill = array(
			'ingredient_id'              => $canonical->ingredient_id,
			'ingredient_form'            => $canonical->ingredient_form,
			'strength_per_serving'       => $canonical->strength_per_serving,
			'standardization_percentage' => $canonical->standardization_percentage,
		);
		foreach ( $fill as $field => $value ) {
			$blank = ! isset( $offer->$field ) || $offer->$field === null || $offer->$field === '';
			// A field the operator cleared is blank but not unset (Q-020).
			if ( $blank && $value !== null && $value !== '' && ! Supcomp_Offers_Repo::is_operator_field( $offer, $field ) ) {
				$data[ $field ] = $value;
			}
		}

		// Guard on canonical_product_id IS NULL in case the operator set one
		// between our SELECT and this write.
		$sets   = array();
		$params = array();
		foreach ( $data as $field => $value ) {
			$sets[]   = "{$field} = %s";
			$params[] = (string) $value;
		}
		$params[] = (int) $offer->id;
		$table    = Supcomp_Offers_Repo::table();
		$updated  = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET " . implode( ', ', $sets ) . ' WHERE id = %d AND canonical_product_id IS NULL',
				$params
			)
		);
		if ( ! $updated ) {
			return false;
		}

		// Ingredient may have just been filled in, which changes the
		// standardization fallback — recompute the derived cost fields.
		$fresh_offer = Supcomp_Offers_Repo::get( (int) $offer->id );
		if ( $fresh_offer ) {
			$ingredient = $fresh_offer->ingredient_id ? Supcomp_Ingredients_Repo::get( (int) $fresh_offer->ingredient_id ) : null;
			Supcomp_Offers_Repo::apply_derivations( (int) $offer->id, Supcomp_Offer_Derivations::compute( $fresh_offer, $ingredient ) );
		}
		return true;
	}
}
