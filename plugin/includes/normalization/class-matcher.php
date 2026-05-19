<?php
/**
 * Canonical-product matching per PROJECTBRIEF.md §7.
 *
 * Tries multiple strategies in confidence order and returns the FIRST
 * positive match. The §7 strata explicitly use peer offers (offers that
 * have already been canonical-matched by the operator), so as soon as
 * the operator approves a few cases the matcher gets steadily smarter.
 *
 *   1.00  Barcode (UPC/GTIN/EAN) match against a peer offer with that barcode.
 *   0.95  Brand + SKU match against a peer offer.
 *   0.85  Direct canonical match: extracted (ingredient, form, strength)
 *         finds exactly one canonical_product. Brief-strict reading of §7
 *         doesn't list this tier, but without it new ingredients ship with
 *         no canonical suggestion until the operator does the first manual
 *         match — which defeats the deliverable.
 *   0.85  Brand + normalized title against a peer offer.
 *   0.75  Brand + normalized title + strength + count against a peer offer.
 *   0.65  Normalized title + strength + count against a peer offer (no brand).
 *   null  No suggestion.
 *
 * Returns array{canonical_product_id: int|null, confidence: float|null}.
 * Caller is responsible for writing both fields back to the offer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Matcher {

	const STOP_TOKENS = array(
		'capsules', 'capsule', 'caps', 'cap',
		'tablets', 'tablet', 'tabs', 'tab',
		'softgels', 'softgel', 'softgel', 'soft-gels', 'softgel',
		'powder', 'powdered',
		'liquid', 'tincture', 'tinctures', 'drops',
		'gummies', 'gummy',
		'count', 'ct', 'serving', 'servings', 'pieces', 'piece', 'scoop', 'scoops',
		'sublingual',
	);

	/**
	 * @param array $offer       Offer fields. Read: barcode, brand, sku,
	 *                           product_title.
	 * @param array $normalized  Output of Supcomp_Normalizer::normalize().
	 * @return array{canonical_product_id: int|null, confidence: float|null}
	 */
	public static function match( $offer, $normalized ) {
		$barcode = self::s( $offer, 'barcode' );
		$brand   = self::s( $offer, 'brand' );
		$sku     = self::s( $offer, 'sku' );
		$title   = self::s( $offer, 'product_title' );

		// 1.00: barcode peer
		if ( $barcode !== '' ) {
			$peer = self::peer_by_barcode( $barcode );
			if ( $peer ) {
				return self::pack( (int) $peer->canonical_product_id, 1.00 );
			}
		}

		// 0.95: brand + SKU peer
		if ( $brand !== '' && $sku !== '' ) {
			$peer = self::peer_by_brand_sku( $brand, $sku );
			if ( $peer ) {
				return self::pack( (int) $peer->canonical_product_id, 0.95 );
			}
		}

		// 0.85: direct canonical lookup by ingredient+form+strength
		if ( isset( $normalized['ingredient_id'], $normalized['ingredient_form'], $normalized['strength_per_serving'] )
			&& $normalized['ingredient_id']
			&& $normalized['ingredient_form']
			&& $normalized['strength_per_serving']
		) {
			$cp = self::canonical_by_attrs(
				(int) $normalized['ingredient_id'],
				(string) $normalized['ingredient_form'],
				(float) $normalized['strength_per_serving'],
				isset( $normalized['standardization_percentage'] ) ? $normalized['standardization_percentage'] : null
			);
			if ( $cp ) {
				return self::pack( (int) $cp->id, 0.85 );
			}
		}

		$nt = self::normalize_title( $title );

		// 0.85: brand + normalized title peer
		if ( $brand !== '' && $nt !== '' ) {
			$peer = self::peer_by_brand_title( $brand, $nt );
			if ( $peer ) {
				return self::pack( (int) $peer->canonical_product_id, 0.85 );
			}
		}

		$strength = isset( $normalized['strength_per_serving'] ) ? $normalized['strength_per_serving'] : null;
		$count    = isset( $normalized['servings_per_container'] ) ? $normalized['servings_per_container'] : null;

		// 0.75: brand + title + strength + count peer
		if ( $brand !== '' && $nt !== '' && $strength !== null && $count !== null ) {
			$peer = self::peer_by_brand_title_strength_count( $brand, $nt, $strength, $count );
			if ( $peer ) {
				return self::pack( (int) $peer->canonical_product_id, 0.75 );
			}
		}

		// 0.65: title + strength + count peer (no brand)
		if ( $nt !== '' && $strength !== null && $count !== null ) {
			$peer = self::peer_by_title_strength_count( $nt, $strength, $count );
			if ( $peer ) {
				return self::pack( (int) $peer->canonical_product_id, 0.65 );
			}
		}

		return array(
			'canonical_product_id' => null,
			'confidence'           => null,
		);
	}

	/**
	 * Normalize a product title for matching per §7: lowercase, strip
	 * punctuation, collapse whitespace, drop stop tokens (form words,
	 * counting words). Brand and ingredient names are NOT stripped here —
	 * Callers that strip brand separately should do so before passing the
	 * title in.
	 */
	public static function normalize_title( $title ) {
		$t = strtolower( (string) $title );
		// Replace anything non-alphanumeric with a space.
		$t = preg_replace( '/[^a-z0-9]+/', ' ', $t );
		$tokens = preg_split( '/\s+/', $t, -1, PREG_SPLIT_NO_EMPTY );
		$kept   = array();
		foreach ( $tokens as $tok ) {
			// Drop pure numbers (strength/count are matched separately) and
			// stop tokens.
			if ( in_array( $tok, self::STOP_TOKENS, true ) ) {
				continue;
			}
			if ( ctype_digit( $tok ) ) {
				continue;
			}
			// "mg" / "mcg" / "iu" — drop unit tokens.
			if ( in_array( $tok, array( 'mg', 'mcg', 'g', 'iu' ), true ) ) {
				continue;
			}
			$kept[] = $tok;
		}
		return implode( ' ', $kept );
	}

	// ---------- Peer / canonical queries ----------

	private static function peer_by_barcode( $barcode ) {
		global $wpdb;
		$table = Supcomp_Offers_Repo::table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT canonical_product_id FROM {$table}
				 WHERE barcode = %s AND canonical_product_id IS NOT NULL
				 ORDER BY id ASC LIMIT 1",
				$barcode
			)
		);
	}

	private static function peer_by_brand_sku( $brand, $sku ) {
		global $wpdb;
		$table = Supcomp_Offers_Repo::table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT canonical_product_id FROM {$table}
				 WHERE LOWER(brand) = LOWER(%s) AND sku = %s AND canonical_product_id IS NOT NULL
				 ORDER BY id ASC LIMIT 1",
				$brand,
				$sku
			)
		);
	}

	private static function peer_by_brand_title( $brand, $normalized_title ) {
		global $wpdb;
		$table = Supcomp_Offers_Repo::table();
		// Compare normalized title at query time by matching the LIKE pattern
		// of each candidate. We narrow by brand first (cheap), then PHP-compare.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, canonical_product_id, product_title FROM {$table}
				 WHERE LOWER(brand) = LOWER(%s) AND canonical_product_id IS NOT NULL",
				$brand
			)
		);
		foreach ( (array) $rows as $r ) {
			if ( self::normalize_title( $r->product_title ) === $normalized_title ) {
				return $r;
			}
		}
		return null;
	}

	private static function peer_by_brand_title_strength_count( $brand, $normalized_title, $strength, $count ) {
		global $wpdb;
		$table = Supcomp_Offers_Repo::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, canonical_product_id, product_title FROM {$table}
				 WHERE LOWER(brand) = LOWER(%s)
				   AND canonical_product_id IS NOT NULL
				   AND ABS(strength_per_serving - %f) < 0.0001
				   AND servings_per_container = %d",
				$brand,
				(float) $strength,
				(int) $count
			)
		);
		foreach ( (array) $rows as $r ) {
			if ( self::normalize_title( $r->product_title ) === $normalized_title ) {
				return $r;
			}
		}
		return null;
	}

	private static function peer_by_title_strength_count( $normalized_title, $strength, $count ) {
		global $wpdb;
		$table = Supcomp_Offers_Repo::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, canonical_product_id, product_title FROM {$table}
				 WHERE canonical_product_id IS NOT NULL
				   AND ABS(strength_per_serving - %f) < 0.0001
				   AND servings_per_container = %d",
				(float) $strength,
				(int) $count
			)
		);
		foreach ( (array) $rows as $r ) {
			if ( self::normalize_title( $r->product_title ) === $normalized_title ) {
				return $r;
			}
		}
		return null;
	}

	private static function canonical_by_attrs( $ingredient_id, $form, $strength, $standardization_pct ) {
		global $wpdb;
		$table = Supcomp_Canonical_Products_Repo::table();
		$sql   = "SELECT id FROM {$table}
				  WHERE ingredient_id = %d
				    AND ingredient_form = %s
				    AND ABS(strength_per_serving - %f) < 0.0001
				    AND status <> 'retired'";
		$params = array( (int) $ingredient_id, (string) $form, (float) $strength );

		// If standardization% is detected, require it to match (within 0.5%).
		// If not detected, accept any canonical (the canonical's own std% may
		// or may not be set; can't be more specific).
		if ( $standardization_pct !== null ) {
			$sql      .= ' AND ABS(IFNULL(standardization_percentage, -1) - %f) < 0.5';
			$params[]  = (float) $standardization_pct;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' LIMIT 2', $params ) );
		if ( count( $rows ) === 1 ) {
			return $rows[0];
		}
		return null;
	}

	private static function pack( $canonical_product_id, $confidence ) {
		return array(
			'canonical_product_id' => $canonical_product_id,
			'confidence'           => $confidence,
		);
	}

	private static function s( $offer, $field ) {
		if ( is_array( $offer ) ) {
			return isset( $offer[ $field ] ) ? (string) $offer[ $field ] : '';
		}
		if ( is_object( $offer ) ) {
			return isset( $offer->$field ) ? (string) $offer->$field : '';
		}
		return '';
	}
}
