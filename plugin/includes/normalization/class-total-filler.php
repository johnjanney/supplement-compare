<?php
/**
 * Total active / container auto-fill — single containers (v1.43.0), packs
 * and blends (v1.44.0, see suggest_multi()).
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
	const SOURCE_PACK_COUNT   = 'pack_count';    // per-vial amount × vials
	const SOURCE_PACK_STATED  = 'pack_stated';   // listing states the pack total
	const SOURCE_BLEND_SUM    = 'blend_sum';     // sum of the blend's parts
	const SOURCE_BLEND_STATED = 'blend_stated';  // listing states the blend total

	const WORD_NUMBERS = array(
		'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7,
		'eight' => 8, 'nine' => 9, 'ten' => 10, 'twelve' => 12, 'twenty' => 20,
	);
	const MAX_PACK_COUNT = 100;

	const DOSE_FORMS = array( 'capsule', 'tablet', 'softgel', 'gummy' );

	const DOSE_FORM_PATTERN = '/\b(?:capsules?|caps|tablets?|tabs|softgels?|gummies|gummy|lozenges?|chews?)\b/i';
	const PACK_COUNT_PATTERN = '/\b(\d+)\s*-?\s*(?:packs?|pk|vials?|kits?|ct|count|pcs|pieces|bottles?)\b/i';
	const PACK_WORD_PATTERN  = '/\bpack\s+of\b|\bbulk\b|\bkits?\b|\bcombo\b|\bbundle\b|(?<![a-z0-9\-])[x×]\s*\d|\b(?:two|three|four|five|six|seven|eight|nine|ten|twelve|twenty)[\s\-]*(?:packs?|vials?|kits?|bottles?)\b/i';
	const BLEND_PATTERN      = '/\bblend\b|\bstack\b|\d\s*(?:mg|mcg|µg)?\/\d|[a-z0-9)]\s*(?:\+|&|\band\b)\s*[a-z]{2,}|[a-z]{2,}\/[a-z]{2,}/i';
	const CONC_PATTERN       = '/\d\s*(?:mg|mcg|µg|iu)\s*(?:\/|per)\s*ml\b/i';
	// Signals strong enough to add amounts together. A bare "5/10mg" is not
	// one — stores use it for an options list.
	const BLEND_SUM_PATTERN      = '/\bblend\b|\bstack\b|[a-z0-9)]\s*(?:\+|&|\band\b)\s*[a-z]{2,}|[a-z]{2,}\/[a-z]{2,}/i';
	const PACK_WORD_ONLY_PATTERN = '/\bbulk\b|\bkits?\b|\bcombo\b|\bbundle\b/i';

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

		if ( isset( $in['ingredient_form'] ) && in_array( (string) $in['ingredient_form'], self::DOSE_FORMS, true ) ) {
			return null;
		}

		// Guards read the titles and the variant's own values — the text that
		// describes this listing. Product-level option lists are left out:
		// "Size: 5mg | 10mg | 3 vials" on the parent says nothing about this
		// variant being a pack.
		$listing = trim( $variant . ' | ' . $product . ' | ' . implode( ' | ', $variant_vals ), ' |' );
		if ( preg_match( self::DOSE_FORM_PATTERN, $listing ) || preg_match( self::CONC_PATTERN, $listing ) ) {
			return null;
		}

		// Packs and blends (v1.44.0) have their own arithmetic.
		if ( self::is_pack( $listing ) || preg_match( self::BLEND_PATTERN, $listing ) ) {
			return self::suggest_multi( $in, $product, $variant, $variant_vals, $product_vals );
		}

		if ( ! empty( $in['servings'] ) || Supcomp_Count_Rule::extract( $listing ) !== null ) {
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
	 * Packs and blends (v1.44.0). Reads one scope — the variant's own text
	 * when it carries amounts, otherwise (no siblings only) the product
	 * title — and works out:
	 *
	 *   per-container amount: one amount → that amount; a blend's
	 *     components → their sum, or a stated total that equals the sum
	 *     ("50mg + 10mg + 10mg (70mg)"). "(5MG Each)" amounts are per
	 *     component and are not summed. Summing needs a real blend signal
	 *     (blend / stack / + / & / and / Name/Name); a bare "5/10mg" is
	 *     an options list, not a blend.
	 *   container count: "10 vials", "3-Pack", "pack of 5", "10mg x 10",
	 *     "2 x 10mg", "Ten Pack". Kit / bulk / combo with no number leave
	 *     the count unknown.
	 *   total: a stated pack total wins when it agrees ("10mg Kit (100mg)",
	 *     "5mg … 50mg (10 vials)"); otherwise per-container × count.
	 *
	 * Anything ambiguous — several counts, an unknown count without a stated
	 * total, amounts that don't reconcile — returns null.
	 */
	private static function suggest_multi( array $in, $product, $variant, array $variant_vals, array $product_vals ) {
		$variant_text = trim( $variant . ' | ' . implode( ' | ', $variant_vals ), ' |' );
		if ( self::amount_list( $variant_text ) ) {
			$text   = $variant_text;
			$source = 'variant';
		} elseif ( empty( $in['has_siblings'] ) ) {
			// Product attributes may list every option; they must not carry
			// an amount the title doesn't.
			$title_set = self::amount_set( $product );
			foreach ( self::amount_set( implode( ' | ', $product_vals ) ) as $key => $unused ) {
				if ( ! isset( $title_set[ $key ] ) ) {
					return null;
				}
			}
			$text   = $product;
			$source = 'title';
		} else {
			return null;
		}

		// The count may sit in the product title even when the variant holds
		// the amounts ("10-Pack Kits" product, "10mg Kit (100mg)" variant).
		$counts = self::pack_counts( $text );
		if ( ! $counts && $source === 'variant' ) {
			$counts = self::pack_counts( $product );
		}
		if ( count( $counts ) > 1 ) {
			return null;
		}
		$n = $counts ? (int) reset( $counts ) : 0; // 0 = no count found
		if ( ! empty( $in['servings'] ) && (int) $in['servings'] !== $n ) {
			return null; // a real servings count, not this pack's vial count
		}
		$count_unknown = $n === 0 && preg_match( self::PACK_WORD_ONLY_PATTERN, $text . ' | ' . $product );

		$amounts = self::amount_list( $text );
		if ( ! $amounts ) {
			return null;
		}
		$blend = (bool) preg_match( self::BLEND_SUM_PATTERN, $text . ' | ' . $product );

		$per    = null;
		$kind   = '';
		$values = array_map( static function ( $a ) { return $a['mg']; }, $amounts );
		$unit   = $amounts[0]['unit'];
		$family = $amounts[0]['family'];
		foreach ( $amounts as $a ) {
			if ( $a['family'] !== $family ) {
				return null; // mg alongside IU — can't add them up
			}
		}

		if ( count( $values ) >= 2 && $blend ) {
			// A stated blend total equal to the other components' sum.
			foreach ( $values as $i => $v ) {
				$rest = $values;
				unset( $rest[ $i ] );
				if ( count( $rest ) >= 2 && self::same( $v, array_sum( $rest ) ) ) {
					$per  = $v;
					$kind = self::SOURCE_BLEND_STATED;
					break;
				}
			}
			if ( $per === null ) {
				$max  = max( $values );
				$rest = $values;
				unset( $rest[ array_search( $max, $rest, true ) ] );
				if ( $n > 1 && self::same( $max, array_sum( $rest ) * $n ) ) {
					return self::multi_result( $max, $family, $unit, self::SOURCE_PACK_STATED );
				}
				$per  = array_sum( $values );
				$kind = self::SOURCE_BLEND_SUM;
			}
		} else {
			$distinct = array_values( array_unique( array_map( static function ( $v ) { return (string) round( $v, 6 ); }, $values ) ) );
			if ( count( $distinct ) === 1 ) {
				$per  = (float) $distinct[0];
				$kind = $blend ? self::SOURCE_BLEND_STATED : '';
			} elseif ( count( $distinct ) === 2 ) {
				$lo = min( (float) $distinct[0], (float) $distinct[1] );
				$hi = max( (float) $distinct[0], (float) $distinct[1] );
				if ( $n > 1 && self::same( $hi, $lo * $n ) ) {
					return self::multi_result( $hi, $family, $unit, self::SOURCE_PACK_STATED );
				}
				// "10mg Kit (100mg)": the larger amount in parentheses is the
				// kit's stated total when it's a whole multiple of the vial.
				$ratio = $hi / $lo;
				if ( $n === 0 && abs( $ratio - round( $ratio ) ) < 0.01 && round( $ratio ) >= 2
					&& preg_match( '/\(\s*' . preg_quote( self::fmt( $hi, $family, $amounts ), '/' ) . '\s*(?:mg|mcg|µg|g|iu)\s*\)/i', $text )
				) {
					return self::multi_result( $hi, $family, $unit, self::SOURCE_PACK_STATED );
				}
				return null;
			} else {
				return null;
			}
		}

		if ( $n > 1 ) {
			return self::multi_result( $per * $n, $family, $unit, $kind === '' ? self::SOURCE_PACK_COUNT : $kind . '_pack' );
		}
		if ( $count_unknown ) {
			return null; // "Kit" / "Bulk" with no count and no stated total
		}
		if ( $kind === '' ) {
			// Plain single amount that only reached here through a pack-ish
			// word; leave it to the operator rather than guess.
			return null;
		}
		return self::multi_result( $per, $family, $unit, $kind );
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
			case self::SOURCE_PACK_COUNT:
				return __( 'pack: per-vial amount × number of vials', 'supplement-compare' );
			case self::SOURCE_PACK_STATED:
				return __( 'pack: total stated on the listing', 'supplement-compare' );
			case self::SOURCE_BLEND_SUM:
				return __( 'blend: sum of the listed parts', 'supplement-compare' );
			case self::SOURCE_BLEND_STATED:
				return __( 'blend: total stated on the listing', 'supplement-compare' );
			case self::SOURCE_BLEND_SUM . '_pack':
				return __( 'blend pack: sum of the parts × number of vials', 'supplement-compare' );
			case self::SOURCE_BLEND_STATED . '_pack':
				return __( 'blend pack: stated blend total × number of vials', 'supplement-compare' );
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

	/**
	 * Container counts in $text (each > 1, ≤ MAX_PACK_COUNT). Numbers glued
	 * to a code ("CJC-1295 Kit") are not counts.
	 *
	 * @return int[] distinct counts
	 */
	private static function pack_counts( $text ) {
		$words = implode( '|', array_keys( self::WORD_NUMBERS ) );
		$found = array();
		$patterns = array(
			'/(?<![a-z0-9\-.,])(\d{1,3}|' . $words . ')\s*-?\s*(?:packs?|pk|vials?|kits?|bottles?|pcs|pieces)\b/i',
			'/\bpack\s+of\s+(\d{1,3}|' . $words . ')\b/i',
			'/(?:mg|mcg|µg|g|iu)\s*[x×]\s*(\d{1,3})(?![\d.])/i',
			'/(?<![a-z0-9\-])[x×]\s*(\d{1,3})\s*(?:vials?|pcs|pieces)\b/i',
			'/(?<![\d.,])(\d{1,2})\s*[x×]\s*\d/i',
		);
		foreach ( $patterns as $p ) {
			if ( preg_match_all( $p, $text, $m ) ) {
				foreach ( $m[1] as $raw ) {
					$k = strtolower( $raw );
					$n = isset( self::WORD_NUMBERS[ $k ] ) ? self::WORD_NUMBERS[ $k ] : (int) $raw;
					if ( $n > 1 && $n <= self::MAX_PACK_COUNT ) {
						$found[ $n ] = $n;
					}
				}
			}
		}
		return array_values( $found );
	}

	/**
	 * Every amount in $text, in order, with duplicates kept (a blend's
	 * "5mg & 5mg" is two components). Slash lists share a trailing unit
	 * ("13/3MG", "70/10/10mg"). Amounts followed by "each" are per
	 * component and dropped; molecular weights are skipped by the scanner.
	 *
	 * @return array<int, array{mg: float, unit: string, family: string, value: float}>
	 */
	private static function amount_list( $text ) {
		$out   = array();
		$spans = array();
		$num   = '(\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?)';
		$unit  = '(mcg|µg|ug|mg|g|iu)';
		if ( preg_match_all( '/(?<![\d.,])' . $num . '(?:\s*' . $unit . ')?(?:\s*\/\s*' . $num . '(?:\s*' . $unit . ')?)+(?![a-z])/iu', $text, $lists, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $lists as $l ) {
				preg_match_all( '/' . $num . '(?:\s*' . $unit . ')?/iu', $l[0][0], $parts, PREG_SET_ORDER );
				$last = '';
				foreach ( $parts as $part ) {
					if ( ! empty( $part[2] ) ) {
						$last = $part[2];
					}
				}
				if ( $last === '' ) {
					continue;
				}
				foreach ( $parts as $part ) {
					$a = self::amount_entry( (float) str_replace( ',', '', $part[1] ), ! empty( $part[2] ) ? $part[2] : $last );
					if ( $a ) {
						$out[] = $a;
					}
				}
				$spans[] = array( $l[0][1], $l[0][1] + strlen( $l[0][0] ) );
			}
		}
		foreach ( Supcomp_Total_Active_Probe::scan_amounts( $text ) as $a ) {
			foreach ( $spans as $sp ) {
				if ( $a['offset'] >= $sp[0] && $a['offset'] < $sp[1] ) {
					continue 2;
				}
			}
			if ( preg_match( '/^\s*each\b/i', substr( $text, $a['offset'] + $a['length'] ) ) ) {
				continue;
			}
			$e = self::amount_entry( $a['value'], $a['unit'] );
			if ( $e ) {
				$out[] = $e;
			}
		}
		return $out;
	}

	private static function amount_entry( $value, $raw_unit ) {
		$unit = Supcomp_Total_Active_Probe::canonical_unit( $raw_unit );
		if ( $unit === null ) {
			return null;
		}
		$mg = Supcomp_Total_Active_Probe::convert( $value, $unit, 'mg' );
		return array(
			'value'  => (float) $value,
			'unit'   => $unit === 'kg' ? 'g' : $unit,
			'family' => $mg !== null ? 'mass' : $unit,
			'mg'     => $mg !== null ? $mg : (float) $value,
		);
	}

	/** @return array<string, true> distinct amounts keyed like distinct_amounts(). */
	private static function amount_set( $text ) {
		$set = array();
		foreach ( self::amount_list( $text ) as $a ) {
			$set[ $a['family'] . ':' . round( $a['mg'], 6 ) ] = true;
		}
		return $set;
	}

	private static function same( $a, $b ) {
		return abs( (float) $a - (float) $b ) <= 0.01 * max( abs( (float) $b ), 1e-9 );
	}

	/** The literal number as written for $mg_value, for the "(100mg)" check. */
	private static function fmt( $mg_value, $family, array $amounts ) {
		foreach ( $amounts as $a ) {
			if ( abs( $a['mg'] - $mg_value ) < 1e-9 ) {
				$s = rtrim( rtrim( number_format( $a['value'], 4, '.', '' ), '0' ), '.' );
				return $s;
			}
		}
		return (string) $mg_value;
	}

	/**
	 * Build a result from an amount expressed in mg (mass) or in its own unit
	 * (IU / billion CFU). Mass results are reported in mg unless the
	 * listing's first unit was grams or mcg and the value is still whole in it.
	 */
	private static function multi_result( $value, $family, $unit, $source ) {
		if ( $family !== 'mass' ) {
			return array( 'total' => round( (float) $value, 4 ), 'unit' => $family, 'source' => $source );
		}
		return array( 'total' => round( (float) $value, 4 ), 'unit' => 'mg', 'source' => $source );
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
