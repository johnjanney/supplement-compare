<?php
/**
 * Total active / container auto-fill — single-container default (v1.43.0).
 *
 * Most of the catalogue is single-vial products: the amount on the label *is*
 * the total in the container, and there is no servings count. The 1.42.x
 * Total Active Diagnostic showed strength × servings got 6 of ~2,100
 * operator-typed totals right, while the amount itself matched about half.
 *
 * suggest() is pure and conservative. It proposes "total = the one amount
 * on the listing" only when every guard passes:
 *
 *   - no servings count, and not a capsule / tablet / softgel / gummy product;
 *   - no multi-container signal (packs, kits, bulk, "N vials", "x 10");
 *   - no blend signal ("blend", "10mg/10mg", "A + B", "A & B", "Tesa/Ipa").
 *     Slashes count only when unspaced: " / " separates Woo variant
 *     attributes ("10mg / Single Peptide");
 *   - no concentration ("5mg/ml" needs a volume to become a total);
 *   - exactly one distinct amount in the place it reads from: variant-level
 *     values first (variant title, Woo variation attributes, Shopify variant
 *     options); otherwise — only when the offer has no sibling variants
 *     sharing its title — the product title plus product-level attributes and
 *     URLs, which must all agree.
 *
 * Anything else returns null and the offer stays blank for the operator.
 * fill() writes a suggestion only on queue offers (pending / needs_review)
 * whose total is blank and not operator-set (Q-020), and records where it came
 * from in total_active_source so the queue can label it. Nothing is
 * auto-published (CLAUDE.md load-bearing #1).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Total_Filler {

	const CHUNK_SIZE    = 200;
	const TIME_BUDGET_S = 20;

	// total_active_source values. Later rules (packs, blends) add their own.
	const SOURCE_VARIANT   = 'single_variant';
	const SOURCE_TITLE     = 'single_title';
	const SOURCE_ATTRIBUTE = 'single_attribute';

	const DOSE_FORMS = array( 'capsule', 'tablet', 'softgel', 'gummy' );

	const DOSE_FORM_PATTERN = '/\b(?:capsules?|caps|tablets?|tabs|softgels?|gummies|gummy|lozenges?|chews?)\b/i';
	const PACK_COUNT_PATTERN = '/\b(\d+)\s*-?\s*(?:packs?|pk|vials?|kits?|ct|count|pcs|pieces|bottles?)\b/i';
	const PACK_WORD_PATTERN  = '/\bpack\s+of\b|\bbulk\b|\bkits?\b|\bcombo\b|\bbundle\b|(?<![a-z0-9\-])[x×]\s*\d|\b(?:two|three|four|five|six|seven|eight|nine|ten|twelve|twenty)[\s\-]*(?:packs?|vials?|kits?|bottles?)\b/i';
	const BLEND_PATTERN      = '/\bblend\b|\bstack\b|\d\s*(?:mg|mcg|µg)?\/\d|[a-z0-9)]\s*(?:\+|&|\band\b)\s*[a-z]{2,}|[a-z]{2,}\/[a-z]{2,}/i';
	const CONC_PATTERN       = '/\d\s*(?:mg|mcg|µg|iu)\s*(?:\/|per)\s*ml\b/i';

	/**
	 * @param array $in {
	 *     product_title, variant_title, raw_attributes_json: string,
	 *     ingredient_form: string|null, servings: int|null, has_siblings: bool,
	 *     variant_level_attributes?: bool
	 * }
	 * @return array{total: float, unit: string, source: string}|null
	 */
	public static function suggest( array $in ) {
		$product = Supcomp_Normalizer::decode_entities( isset( $in['product_title'] ) ? (string) $in['product_title'] : '' );
		$variant = Supcomp_Normalizer::decode_entities( isset( $in['variant_title'] ) ? (string) $in['variant_title'] : '' );
		list( $variant_vals, $product_vals ) = self::split_attributes( isset( $in['raw_attributes_json'] ) ? (string) $in['raw_attributes_json'] : '' );
		// JSON-API rows are one per variant, so their extras ("dosage": 50,
		// "dosage_unit": "mg") describe this variant, not the product.
		if ( ! empty( $in['variant_level_attributes'] ) ) {
			$variant_vals = array_merge( $variant_vals, $product_vals );
			$product_vals = array();
		}

		if ( ! empty( $in['servings'] ) ) {
			return null;
		}
		if ( isset( $in['ingredient_form'] ) && in_array( (string) $in['ingredient_form'], self::DOSE_FORMS, true ) ) {
			return null;
		}

		// Guards read the titles and the variant's own values — the text that
		// describes this listing. Product-level option lists are left out:
		// "Size: 5mg | 10mg | 3 vials" on the parent says nothing about this
		// variant being a pack.
		$listing = trim( $variant . ' | ' . $product . ' | ' . implode( ' | ', $variant_vals ), ' |' );
		if ( self::is_pack( $listing )
			|| preg_match( self::DOSE_FORM_PATTERN, $listing )
			|| preg_match( self::BLEND_PATTERN, $listing )
			|| preg_match( self::CONC_PATTERN, $listing )
			|| Supcomp_Count_Rule::extract( $listing ) !== null
		) {
			return null;
		}

		// 1. Variant-level values describe exactly this offer.
		$variant_text = trim( $variant . ' | ' . implode( ' | ', $variant_vals ), ' |' );
		$amounts      = self::distinct_amounts( $variant_text );
		if ( count( $amounts ) === 1 ) {
			return self::pack_result( $amounts[0], $variant !== '' && self::distinct_amounts( $variant ) ? self::SOURCE_VARIANT : self::SOURCE_ATTRIBUTE );
		}
		if ( count( $amounts ) > 1 ) {
			return null;
		}

		// 2. Product-level text is shared by every variant of the product, so
		// it only identifies this offer when there are no siblings.
		if ( ! empty( $in['has_siblings'] ) ) {
			return null;
		}
		$title_amounts = self::distinct_amounts( $product );
		$all_amounts   = self::distinct_amounts( trim( $product . ' | ' . implode( ' | ', $product_vals ), ' |' ) );
		if ( count( $all_amounts ) !== 1 ) {
			return null; // none, or the title and attributes/URL disagree
		}
		return self::pack_result( $all_amounts[0], $title_amounts ? self::SOURCE_TITLE : self::SOURCE_ATTRIBUTE );
	}

	/**
	 * Fill blank totals on queue offers.
	 *
	 * @param int[]|null $ids Restrict to these offer ids; null = whole queue.
	 * @return array{checked:int, filled:int, complete:bool}
	 */
	public static function fill( $ids = null ) {
		global $wpdb;
		$table = Supcomp_Offers_Repo::table();
		$base  = "SELECT * FROM {$table}
				  WHERE visibility_status IN ('pending', 'needs_review')
				    AND total_active_per_container IS NULL
				    AND id > %d";
		$extra = array();
		if ( is_array( $ids ) ) {
			$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
			if ( empty( $ids ) ) {
				return array( 'checked' => 0, 'filled' => 0, 'complete' => true );
			}
			$base .= ' AND id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$extra = $ids;
		}
		$base .= ' ORDER BY id ASC LIMIT %d';

		$started  = microtime( true );
		$cursor   = 0;
		$checked  = 0;
		$filled   = 0;
		$complete = true;
		do {
			$params = array_merge( array( $cursor ), $extra, array( self::CHUNK_SIZE ) );
			$offers = (array) $wpdb->get_results( $wpdb->prepare( $base, $params ) );
			foreach ( $offers as $offer ) {
				$cursor = (int) $offer->id;
				++$checked;
				if ( self::fill_offer( $offer ) ) {
					++$filled;
				}
				if ( microtime( true ) - $started > self::TIME_BUDGET_S ) {
					$complete = false;
					break 2;
				}
			}
		} while ( count( $offers ) === self::CHUNK_SIZE );

		return array(
			'checked'  => $checked,
			'filled'   => $filled,
			'complete' => $complete,
		);
	}

	/**
	 * Suggestion inputs for one stored offer: its latest raw merchant row
	 * (for attributes) plus its sibling status.
	 */
	public static function inputs_for( $offer ) {
		$raw     = Supcomp_Offers_Repo::latest_raw_for( $offer );
		$raw_row = $raw && $raw->raw_csv_row_json ? json_decode( (string) $raw->raw_csv_row_json, true ) : null;
		return array(
			'product_title'       => (string) $offer->product_title,
			'variant_title'       => (string) $offer->variant_title,
			'raw_attributes_json' => is_array( $raw_row ) && isset( $raw_row['raw_attributes_json'] ) ? (string) $raw_row['raw_attributes_json'] : '',
			'ingredient_form'     => $offer->ingredient_form,
			'servings'            => $offer->servings_per_container !== null ? (int) $offer->servings_per_container : null,
			'has_siblings'        => self::has_siblings( $offer ),
			'variant_level_attributes' => self::variant_level_attributes( is_array( $raw_row ) ? $raw_row : array() ),
		);
	}

	/**
	 * Whether a raw row's raw_attributes_json describes the variant itself:
	 * true for JSON-API handler rows that carry a variant id.
	 */
	public static function variant_level_attributes( array $raw_row ) {
		return isset( $raw_row['source'] ) && $raw_row['source'] === 'json'
			&& isset( $raw_row['source_variant_id'] ) && (string) $raw_row['source_variant_id'] !== '';
	}

	private static function fill_offer( $offer ) {
		if ( Supcomp_Offers_Repo::is_operator_field( $offer, 'total_active_per_container' ) ) {
			return false;
		}
		$s = self::suggest( self::inputs_for( $offer ) );
		if ( $s === null ) {
			return false;
		}

		// The total is stored in the offer's strength_unit. Convert into it
		// when one is set (and mass-compatible); otherwise adopt the amount's
		// unit — unless the operator owns the unit, in which case skip.
		$unit  = $offer->strength_unit !== null && $offer->strength_unit !== '' ? (string) $offer->strength_unit : '';
		$total = $s['total'];
		$data  = array();
		if ( $unit !== '' ) {
			$total = Supcomp_Total_Active_Probe::convert( $s['total'], $s['unit'], Supcomp_Total_Active_Probe::canonical_unit( $unit ) );
			if ( $total === null ) {
				if ( Supcomp_Offers_Repo::is_operator_field( $offer, 'strength_unit' ) ) {
					return false;
				}
				$total               = $s['total'];
				$data['strength_unit'] = $s['unit'];
			}
		} else {
			$data['strength_unit'] = $s['unit'];
		}
		$data['total_active_per_container'] = round( $total, 4 );
		$data['total_active_source']        = $s['source'];
		$data['updated_at']                 = current_time( 'mysql', true );

		// Guard on a still-blank total in case the operator saved one between
		// our SELECT and this write.
		global $wpdb;
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
				"UPDATE {$table} SET " . implode( ', ', $sets ) . ' WHERE id = %d AND total_active_per_container IS NULL',
				$params
			)
		);
		if ( ! $updated ) {
			return false;
		}

		$fresh = Supcomp_Offers_Repo::get( (int) $offer->id );
		if ( $fresh ) {
			$ingredient = $fresh->ingredient_id ? Supcomp_Ingredients_Repo::get( (int) $fresh->ingredient_id ) : null;
			Supcomp_Offers_Repo::apply_derivations( (int) $offer->id, Supcomp_Offer_Derivations::compute( $fresh, $ingredient ) );
		}
		return true;
	}

	/**
	 * Other offers from the same merchant that share this product id (a
	 * variation of the same parent) or, with no variant title to tell them
	 * apart, the same product title.
	 */
	public static function has_siblings( $offer ) {
		global $wpdb;
		$table = Supcomp_Offers_Repo::table();
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE merchant_id = %d AND id <> %d
				   AND ( ( source_product_id = %s AND source_product_id <> '' ) OR product_title = %s )
				 LIMIT 1",
				(int) $offer->merchant_id,
				(int) $offer->id,
				(string) $offer->source_product_id,
				(string) $offer->product_title
			)
		);
	}

	public static function source_label( $source ) {
		switch ( (string) $source ) {
			case self::SOURCE_VARIANT:
				return __( 'single container, from the variant', 'supplement-compare' );
			case self::SOURCE_TITLE:
				return __( 'single container, from the product title', 'supplement-compare' );
			case self::SOURCE_ATTRIBUTE:
				return __( 'single container, from a product attribute', 'supplement-compare' );
		}
		return '';
	}

	// ---------- helpers ----------

	/**
	 * Split raw_attributes_json into variant-level values (Woo
	 * variation_attributes, Shopify variant_options) and everything else.
	 *
	 * @return array{0: string[], 1: string[]}
	 */
	private static function split_attributes( $json ) {
		if ( $json === '' ) {
			return array( array(), array() );
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array( array(), array() );
		}
		$variant = array();
		foreach ( array( 'variation_attributes', 'variant_options' ) as $key ) {
			if ( isset( $decoded[ $key ] ) ) {
				$part = array( $key => $decoded[ $key ] );
				foreach ( Supcomp_Total_Active_Probe::attribute_pairs( (string) wp_json_encode( $part ) ) as $pair ) {
					$variant[] = Supcomp_Normalizer::decode_entities( $pair[1] );
				}
				unset( $decoded[ $key ] );
			}
		}
		$product = array();
		foreach ( Supcomp_Total_Active_Probe::attribute_pairs( (string) wp_json_encode( $decoded ) ) as $pair ) {
			$product[] = Supcomp_Normalizer::decode_entities( $pair[1] );
		}
		return array( $variant, $product );
	}

	private static function is_pack( $text ) {
		if ( preg_match_all( self::PACK_COUNT_PATTERN, $text, $m ) ) {
			foreach ( $m[1] as $n ) {
				if ( (int) $n > 1 ) {
					return true;
				}
			}
		}
		return (bool) preg_match( self::PACK_WORD_PATTERN, $text );
	}

	/**
	 * Distinct amounts in $text, mass units folded to mg so "1 g" and
	 * "1000 mg" count once.
	 *
	 * @return array<int, array{value: float, unit: string}>
	 */
	private static function distinct_amounts( $text ) {
		$seen = array();
		foreach ( Supcomp_Total_Active_Probe::scan_amounts( $text ) as $a ) {
			$mg  = Supcomp_Total_Active_Probe::convert( $a['value'], $a['unit'], 'mg' );
			$key = $mg !== null ? 'mg:' . round( $mg, 6 ) : $a['unit'] . ':' . round( $a['value'], 6 );
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = array( 'value' => $a['value'], 'unit' => $a['unit'] );
			}
		}
		return array_values( $seen );
	}

	private static function pack_result( array $amount, $source ) {
		$unit = $amount['unit'] === 'kg' ? 'g' : $amount['unit'];
		$val  = $amount['unit'] === 'kg' ? $amount['value'] * 1000 : $amount['value'];
		return array(
			'total'  => (float) $val,
			'unit'   => $unit,
			'source' => $source,
		);
	}
}
