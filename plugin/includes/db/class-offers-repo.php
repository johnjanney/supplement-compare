<?php
/**
 * Repository for `normalized_offers`.
 *
 * The table mixes three layers of data:
 *
 *   1. CSV-touchable columns (rewritten on every import): identification,
 *      pricing, stock, source URLs, sync timestamps.
 *   2. Operator-curated columns (never touched by import): ingredient_id /
 *      canonical_product_id / strength / standardization / trust signals /
 *      operator_notes / match_confidence.
 *   3. State columns: visibility_status (operator-set mostly, but a 'stale'
 *      offer that reappears in a fresh import is auto-restored to 'active').
 *
 * `insert_from_csv()` and `update_csv_columns()` enforce this split — the
 * caller only passes a CSV row and the repo builds the right column set.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Offers_Repo {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'supcomp_normalized_offers';
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) );
	}

	/**
	 * Same as get() but also joins merchant / canonical_product / ingredient
	 * names. Use for admin views.
	 */
	public static function get_with_joins( $id ) {
		global $wpdb;
		$no = self::table();
		$m  = $wpdb->prefix . 'supcomp_merchants';
		$cp = $wpdb->prefix . 'supcomp_canonical_products';
		$ci = $wpdb->prefix . 'supcomp_canonical_ingredients';
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT o.*,
						m.name AS merchant_name, m.slug AS merchant_slug, m.affiliate_url_template AS merchant_affiliate_url_template,
						cp.display_name AS canonical_display_name, cp.slug AS canonical_slug,
						ci.name AS ingredient_name, ci.default_unit AS ingredient_unit
				 FROM {$no} o
				 LEFT JOIN {$m} m ON m.id = o.merchant_id
				 LEFT JOIN {$cp} cp ON cp.id = o.canonical_product_id
				 LEFT JOIN {$ci} ci ON ci.id = o.ingredient_id
				 WHERE o.id = %d",
				absint( $id )
			)
		);
	}

	/**
	 * Pending/active queue query. Joins for display.
	 *
	 * @param array $args Recognized keys:
	 *   visibility       string|string[] — filter to these visibility_status values
	 *   merchant_id      int
	 *   ingredient_id    int
	 *   min_confidence   float (0–1)
	 *   has_canonical    'yes'|'no'|''
	 *   search           string — searches product_title, variant_title, brand, sku
	 *   orderby          allowed: id, brand, product_title, current_price,
	 *                    match_confidence, cost_per_active_unit, updated_at
	 *   order            ASC|DESC
	 *   limit            int (default 20)
	 *   offset           int (default 0)
	 */
	public static function query_for_admin( $args = array() ) {
		global $wpdb;
		list( $sql, $params ) = self::build_admin_query( $args, false );
		if ( empty( $params ) ) {
			return $wpdb->get_results( $sql );
		}
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	public static function count_for_admin( $args = array() ) {
		global $wpdb;
		list( $sql, $params ) = self::build_admin_query( $args, true );
		if ( empty( $params ) ) {
			return (int) $wpdb->get_var( $sql );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	private static function build_admin_query( $args, $count_only ) {
		global $wpdb;
		$defaults = array(
			'visibility'     => array(),
			'merchant_id'    => 0,
			'ingredient_id'  => 0,
			'min_confidence' => 0,
			'has_canonical'  => '',
			'search'         => '',
			'orderby'        => 'updated_at',
			'order'          => 'DESC',
			'limit'          => 20,
			'offset'         => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		$no = self::table();
		$m  = $wpdb->prefix . 'supcomp_merchants';
		$cp = $wpdb->prefix . 'supcomp_canonical_products';
		$ci = $wpdb->prefix . 'supcomp_canonical_ingredients';

		$where  = array();
		$params = array();

		$visibility = is_array( $args['visibility'] ) ? $args['visibility'] : array_filter( array( $args['visibility'] ) );
		if ( ! empty( $visibility ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $visibility ), '%s' ) );
			$where[]      = "o.visibility_status IN ({$placeholders})";
			$params       = array_merge( $params, $visibility );
		}
		if ( $args['merchant_id'] > 0 ) {
			$where[]  = 'o.merchant_id = %d';
			$params[] = (int) $args['merchant_id'];
		}
		if ( $args['ingredient_id'] > 0 ) {
			$where[]  = 'o.ingredient_id = %d';
			$params[] = (int) $args['ingredient_id'];
		}
		if ( $args['min_confidence'] > 0 ) {
			$where[]  = 'o.match_confidence >= %f';
			$params[] = (float) $args['min_confidence'];
		}
		if ( $args['has_canonical'] === 'yes' ) {
			$where[] = 'o.canonical_product_id IS NOT NULL';
		} elseif ( $args['has_canonical'] === 'no' ) {
			$where[] = 'o.canonical_product_id IS NULL';
		}
		if ( $args['search'] !== '' ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(o.product_title LIKE %s OR o.variant_title LIKE %s OR o.brand LIKE %s OR o.sku LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = empty( $where ) ? '1=1' : implode( ' AND ', $where );

		if ( $count_only ) {
			$sql = "SELECT COUNT(*) FROM {$no} o WHERE {$where_sql}";
			return array( $sql, $params );
		}

		$allowed_orderby = array(
			'id'                   => 'o.id',
			'brand'                => 'o.brand',
			'product_title'        => 'o.product_title',
			'current_price'        => 'o.current_price',
			'match_confidence'     => 'o.match_confidence',
			'cost_per_active_unit' => 'o.cost_per_active_unit',
			'updated_at'           => 'o.updated_at',
		);
		$orderby_sql = isset( $allowed_orderby[ $args['orderby'] ] ) ? $allowed_orderby[ $args['orderby'] ] : 'o.updated_at';
		$order       = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$sql      = "SELECT o.*,
							m.name AS merchant_name, m.slug AS merchant_slug,
							cp.display_name AS canonical_display_name, cp.slug AS canonical_slug,
							ci.name AS ingredient_name, ci.default_unit AS ingredient_unit
					 FROM {$no} o
					 LEFT JOIN {$m} m ON m.id = o.merchant_id
					 LEFT JOIN {$cp} cp ON cp.id = o.canonical_product_id
					 LEFT JOIN {$ci} ci ON ci.id = o.ingredient_id
					 WHERE {$where_sql}
					 ORDER BY {$orderby_sql} {$order}
					 LIMIT %d OFFSET %d";
		$params[] = max( 1, (int) $args['limit'] );
		$params[] = max( 0, (int) $args['offset'] );

		return array( $sql, $params );
	}

	/**
	 * Operator-edit: writes operator-curated fields from the offer detail
	 * form. Sanitizes against enums and the canonical/ingredient tables.
	 * Caller is responsible for recomputing derivations afterwards.
	 *
	 * @return array{updated:bool, written:array} The cleaned data actually written.
	 */
	public static function manual_update( $id, $data ) {
		global $wpdb;
		$clean = self::sanitize_operator_edit( $data );
		$clean['updated_at'] = current_time( 'mysql', true );
		$result = $wpdb->update( self::table(), $clean, array( 'id' => (int) $id ) );
		return array(
			'updated' => $result !== false,
			'written' => $clean,
		);
	}

	private static function sanitize_operator_edit( $data ) {
		$clean = array();

		if ( array_key_exists( 'canonical_product_id', $data ) ) {
			$val = $data['canonical_product_id'];
			$clean['canonical_product_id'] = ( $val === '' || $val === null ) ? null : absint( $val );
		}
		if ( array_key_exists( 'ingredient_id', $data ) ) {
			$val = $data['ingredient_id'];
			$clean['ingredient_id'] = ( $val === '' || $val === null ) ? null : absint( $val );
		}
		if ( isset( $data['ingredient_form'] ) ) {
			$f                       = sanitize_key( $data['ingredient_form'] );
			$clean['ingredient_form'] = in_array( $f, Supcomp_Installer::PRODUCT_FORMS, true ) ? $f : null;
		}
		if ( array_key_exists( 'strength_per_serving', $data ) ) {
			$v                              = trim( (string) $data['strength_per_serving'] );
			$clean['strength_per_serving'] = $v === '' ? null : (float) $v;
		}
		if ( isset( $data['strength_unit'] ) ) {
			$u                      = trim( (string) $data['strength_unit'] );
			$clean['strength_unit'] = in_array( $u, Supcomp_Installer::INGREDIENT_UNITS, true ) ? $u : null;
		}
		if ( array_key_exists( 'servings_per_container', $data ) ) {
			$v                                = trim( (string) $data['servings_per_container'] );
			$clean['servings_per_container'] = $v === '' ? null : (int) $v;
		}
		if ( array_key_exists( 'standardization_percentage', $data ) ) {
			$v                                    = trim( (string) $data['standardization_percentage'] );
			$clean['standardization_percentage'] = $v === '' ? null : (float) $v;
		}
		if ( array_key_exists( 'third_party_tested', $data ) ) {
			$clean['third_party_tested'] = self::truthy( $data['third_party_tested'] ) ? 1 : 0;
		}
		if ( array_key_exists( 'coa_available', $data ) ) {
			$clean['coa_available'] = self::truthy( $data['coa_available'] ) ? 1 : 0;
		}
		if ( array_key_exists( 'coa_url', $data ) ) {
			$v                = trim( (string) $data['coa_url'] );
			$clean['coa_url'] = $v === '' ? null : esc_url_raw( $v );
		}
		if ( array_key_exists( 'certifications', $data ) || array_key_exists( 'certifications_json', $data ) ) {
			$raw = array_key_exists( 'certifications', $data ) ? $data['certifications'] : $data['certifications_json'];
			if ( is_array( $raw ) ) {
				$list = $raw;
			} elseif ( is_string( $raw ) && $raw !== '' ) {
				$decoded = json_decode( $raw, true );
				$list    = is_array( $decoded ) ? $decoded : preg_split( '/\s*[|;,]\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY );
			} else {
				$list = array();
			}
			$list                          = array_values( array_filter( array_map( 'sanitize_text_field', (array) $list ), 'strlen' ) );
			$clean['certifications_json'] = wp_json_encode( $list );
		}
		if ( isset( $data['operator_notes'] ) ) {
			$clean['operator_notes'] = sanitize_textarea_field( $data['operator_notes'] );
		}
		if ( isset( $data['match_confidence'] ) && $data['match_confidence'] !== '' ) {
			$mc                       = (float) $data['match_confidence'];
			$clean['match_confidence'] = max( 0, min( 1, $mc ) );
		}

		return $clean;
	}

	private static function truthy( $val ) {
		if ( is_bool( $val ) ) {
			return $val;
		}
		$v = strtolower( trim( (string) $val ) );
		return in_array( $v, array( '1', 'true', 'yes', 'y', 't', 'on' ), true );
	}

	public static function set_visibility( $id, $visibility ) {
		global $wpdb;
		if ( ! in_array( $visibility, Supcomp_Installer::VISIBILITY_STATUSES, true ) ) {
			return false;
		}
		return false !== $wpdb->update(
			self::table(),
			array(
				'visibility_status' => $visibility,
				'updated_at'        => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
	}

	public static function bulk_set_visibility( $ids, $visibility ) {
		global $wpdb;
		if ( ! in_array( $visibility, Supcomp_Installer::VISIBILITY_STATUSES, true ) ) {
			return 0;
		}
		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = self::table();
		$sql          = "UPDATE {$table}
						 SET visibility_status = %s, updated_at = %s
						 WHERE id IN ({$placeholders})";
		$params       = array_merge(
			array( $visibility, current_time( 'mysql', true ) ),
			$ids
		);
		$affected = $wpdb->query( $wpdb->prepare( $sql, $params ) );
		return is_numeric( $affected ) ? (int) $affected : 0;
	}

	/**
	 * Joined query for the public JSON exporter (Phase 8). Returns active,
	 * canonical-matched offers within the hide threshold, with merchant +
	 * canonical_product + ingredient fields attached. Ordered by canonical
	 * then ascending cost-per-active-unit so the exporter can walk the
	 * result set once and accumulate per-canonical rollups in order.
	 */
	public static function for_export( $hide_threshold_mysql ) {
		global $wpdb;
		$o  = self::table();
		$m  = $wpdb->prefix . 'supcomp_merchants';
		$cp = $wpdb->prefix . 'supcomp_canonical_products';
		$ci = $wpdb->prefix . 'supcomp_canonical_ingredients';
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.*,
						m.slug AS merchant_slug, m.name AS merchant_name,
						cp.slug AS canonical_slug, cp.display_name AS canonical_display_name,
						cp.ingredient_form AS canonical_form,
						cp.strength_per_serving AS canonical_strength,
						cp.standardization_compound AS canonical_std_compound,
						cp.standardization_percentage AS canonical_std_pct,
						ci.id AS ingredient_id_join, ci.name AS ingredient_name,
						ci.category AS ingredient_category, ci.default_unit AS ingredient_unit
				 FROM {$o} o
				 INNER JOIN {$m} m ON m.id = o.merchant_id AND m.status = 'active'
				 INNER JOIN {$cp} cp ON cp.id = o.canonical_product_id AND cp.status <> 'retired'
				 INNER JOIN {$ci} ci ON ci.id = cp.ingredient_id AND ci.status <> 'retired'
				 WHERE o.visibility_status = 'active'
				   AND o.canonical_product_id IS NOT NULL
				   AND o.last_synced_at >= %s
				 ORDER BY cp.id ASC, o.cost_per_active_unit ASC, o.id ASC",
				$hide_threshold_mysql
			)
		);
	}

	/**
	 * Latest raw_source_offers row for an offer's natural key. Used by the
	 * detail view's side-by-side display.
	 */
	public static function latest_raw_for( $offer ) {
		if ( ! $offer ) {
			return null;
		}
		global $wpdb;
		$raw = $wpdb->prefix . 'supcomp_raw_source_offers';
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$raw}
				 WHERE merchant_id = %d AND source_product_id = %s AND source_variant_id = %s
				 ORDER BY id DESC LIMIT 1",
				(int) $offer->merchant_id,
				(string) $offer->source_product_id,
				(string) $offer->source_variant_id
			)
		);
	}

	public static function decode_certifications( $json ) {
		if ( empty( $json ) ) {
			return array();
		}
		$decoded = json_decode( (string) $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Match the natural key from the CSV contract (§3.5).
	 */
	public static function find_by_natural_key( $merchant_id, $source_product_id, $source_variant_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE merchant_id = %d AND source_product_id = %s AND source_variant_id = %s",
				(int) $merchant_id,
				(string) $source_product_id,
				(string) $source_variant_id
			)
		);
	}

	/**
	 * Insert a new offer from a CSV row. The row has already been validated.
	 * Returns the new offer id.
	 */
	public static function insert_from_csv( $merchant_id, $row, $import_run_id, $now ) {
		global $wpdb;
		$data                       = self::csv_columns( $row );
		$data['merchant_id']        = (int) $merchant_id;
		$data['visibility_status']  = 'pending';
		$data['last_seen_import_run_id'] = (int) $import_run_id;
		$data['first_seen_at']      = $now;
		$data['last_synced_at']     = $now;
		$data['updated_at']         = $now;

		$wpdb->insert( self::table(), $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update the CSV-touchable columns of an existing offer. Operator-curated
	 * columns are left alone. Returns the array actually written so the
	 * caller can diff against the existing row for price_history logging.
	 */
	public static function update_csv_columns( $existing_id, $row, $import_run_id, $now, $restore_from_stale ) {
		global $wpdb;
		$data                            = self::csv_columns( $row );
		$data['last_seen_import_run_id'] = (int) $import_run_id;
		$data['last_synced_at']          = $now;
		$data['updated_at']              = $now;
		if ( $restore_from_stale ) {
			$data['visibility_status'] = 'active';
		}
		$wpdb->update( self::table(), $data, array( 'id' => (int) $existing_id ) );
		return $data;
	}

	/**
	 * Build the column array for an offer row from a validated CSV row.
	 * Every field here is in the CSV-touchable set — caller adds merchant_id,
	 * state, and timestamps separately.
	 */
	private static function csv_columns( $row ) {
		return array(
			'source_platform'             => self::s( $row, 'source' ),
			'source_product_id'           => self::s( $row, 'source_product_id' ),
			'source_variant_id'           => self::s( $row, 'source_variant_id' ),
			'product_title'               => self::trim_to( self::s( $row, 'product_title' ), 512 ),
			'variant_title'               => self::trim_to( self::s( $row, 'variant_title' ), 255 ),
			'brand'                       => self::trim_to( self::s( $row, 'brand' ), 255 ),
			'sku'                         => self::trim_to( self::s( $row, 'sku' ), 255 ),
			'barcode'                     => self::trim_to( self::s( $row, 'barcode' ), 64 ),
			'regular_price'               => self::decimal_or_null( $row, 'regular_price' ),
			'sale_price'                  => self::decimal_or_null( $row, 'sale_price' ),
			'current_price'               => self::decimal_or_null( $row, 'current_price' ),
			'on_sale'                     => self::bool_int( $row, 'on_sale' ),
			'currency'                    => self::currency( $row ),
			'stock_status'                => self::stock_status( $row ),
			'source_product_url'          => self::trim_to( self::s( $row, 'source_product_url' ), 512 ),
			'source_variant_url'          => self::trim_to_nullable( self::s( $row, 'source_variant_url' ), 512 ),
			'variation_retrieval_status'  => self::variation_status( $row ),
		);
	}

	private static function s( $row, $key ) {
		return isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
	}

	private static function trim_to( $value, $max ) {
		$value = (string) $value;
		return strlen( $value ) > $max ? substr( $value, 0, $max ) : $value;
	}

	private static function trim_to_nullable( $value, $max ) {
		$value = (string) $value;
		if ( $value === '' ) {
			return null;
		}
		return self::trim_to( $value, $max );
	}

	private static function decimal_or_null( $row, $key ) {
		if ( ! isset( $row[ $key ] ) ) {
			return null;
		}
		$raw = trim( (string) $row[ $key ] );
		if ( $raw === '' ) {
			return null;
		}
		if ( ! is_numeric( $raw ) ) {
			return null;
		}
		return (float) $raw;
	}

	private static function bool_int( $row, $key ) {
		if ( ! isset( $row[ $key ] ) ) {
			return 0;
		}
		$v = strtolower( trim( (string) $row[ $key ] ) );
		return in_array( $v, array( '1', 'true', 'yes', 't', 'y', 'on' ), true ) ? 1 : 0;
	}

	private static function currency( $row ) {
		$raw = isset( $row['currency'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $row['currency'] ) ) : '';
		$raw = substr( (string) $raw, 0, 3 );
		return $raw !== '' ? $raw : (string) get_option( 'supcomp_default_currency', 'USD' );
	}

	private static function stock_status( $row ) {
		$v = isset( $row['stock_status'] ) ? strtolower( trim( (string) $row['stock_status'] ) ) : '';
		return in_array( $v, Supcomp_Installer::STOCK_STATUSES, true ) ? $v : 'unknown';
	}

	private static function variation_status( $row ) {
		$v = isset( $row['variation_retrieval_status'] ) ? strtolower( trim( (string) $row['variation_retrieval_status'] ) ) : '';
		return in_array( $v, Supcomp_Installer::VARIATION_RETRIEVAL_STATUSES, true ) ? $v : 'not_applicable';
	}

	/**
	 * Write the normalizer's output and the matcher's suggestion onto a
	 * fresh offer. Called only on first import — operator edits in the
	 * Phase 6 pending queue are sticky. When the matcher proposed a
	 * canonical_product_id, the canonical's authoritative
	 * (ingredient_id, form, strength, std%) override the normalizer's
	 * guesses.
	 */
	public static function apply_normalization_and_match( $offer_id, $normalized, $match ) {
		global $wpdb;

		$data = array(
			'ingredient_id'              => isset( $normalized['ingredient_id'] ) ? $normalized['ingredient_id'] : null,
			'ingredient_form'            => isset( $normalized['ingredient_form'] ) ? $normalized['ingredient_form'] : null,
			'strength_per_serving'       => isset( $normalized['strength_per_serving'] ) ? $normalized['strength_per_serving'] : null,
			'strength_unit'              => isset( $normalized['strength_unit'] ) ? $normalized['strength_unit'] : null,
			'servings_per_container'     => isset( $normalized['servings_per_container'] ) ? $normalized['servings_per_container'] : null,
			'standardization_percentage' => isset( $normalized['standardization_percentage'] ) ? $normalized['standardization_percentage'] : null,
			'canonical_product_id'       => isset( $match['canonical_product_id'] ) ? $match['canonical_product_id'] : null,
			'match_confidence'           => isset( $match['confidence'] ) ? $match['confidence'] : null,
			'updated_at'                 => current_time( 'mysql', true ),
		);

		if ( ! empty( $match['canonical_product_id'] ) ) {
			$canonical = Supcomp_Canonical_Products_Repo::get( (int) $match['canonical_product_id'] );
			if ( $canonical ) {
				$data['ingredient_id']        = (int) $canonical->ingredient_id;
				$data['ingredient_form']      = (string) $canonical->ingredient_form;
				$data['strength_per_serving'] = (float) $canonical->strength_per_serving;
				if ( $canonical->standardization_percentage !== null && $canonical->standardization_percentage !== '' ) {
					$data['standardization_percentage'] = (float) $canonical->standardization_percentage;
				}
			}
		}

		$wpdb->update( self::table(), $data, array( 'id' => (int) $offer_id ) );
	}

	/**
	 * Write the derived field set (total_strength, active_*, cost_*).
	 * Run on every import — these depend on price which can change every run.
	 */
	public static function apply_derivations( $offer_id, $derivations ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'total_strength'              => $derivations['total_strength'],
				'active_compound_per_serving' => $derivations['active_compound_per_serving'],
				'active_compound_total'       => $derivations['active_compound_total'],
				'cost_per_serving'            => $derivations['cost_per_serving'],
				'cost_per_active_unit'        => $derivations['cost_per_active_unit'],
				'updated_at'                  => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $offer_id )
		);
	}

	/**
	 * Detect price/stock differences between an existing row (stdClass from
	 * the DB) and the new CSV-derived data array. Returns the old/new fields
	 * needed for a price_history row, or null if nothing changed.
	 */
	public static function diff_for_price_history( $existing, $new_data ) {
		$old_regular = self::decimal_or_null_value( $existing->regular_price );
		$old_sale    = self::decimal_or_null_value( $existing->sale_price );
		$old_stock   = (string) $existing->stock_status;

		$new_regular = $new_data['regular_price'];
		$new_sale    = $new_data['sale_price'];
		$new_stock   = (string) $new_data['stock_status'];

		$regular_changed = self::decimal_changed( $old_regular, $new_regular );
		$sale_changed    = self::decimal_changed( $old_sale, $new_sale );
		$stock_changed   = $old_stock !== $new_stock;

		if ( ! $regular_changed && ! $sale_changed && ! $stock_changed ) {
			return null;
		}

		return array(
			'old_regular_price' => $old_regular,
			'new_regular_price' => $new_regular,
			'old_sale_price'    => $old_sale,
			'new_sale_price'    => $new_sale,
			'old_stock_status'  => $old_stock,
			'new_stock_status'  => $new_stock,
		);
	}

	private static function decimal_or_null_value( $val ) {
		if ( $val === null || $val === '' ) {
			return null;
		}
		return (float) $val;
	}

	private static function decimal_changed( $old, $new ) {
		if ( $old === null && $new === null ) {
			return false;
		}
		if ( $old === null xor $new === null ) {
			return true;
		}
		return abs( (float) $old - (float) $new ) > 0.00005; // schema precision is 4 decimals
	}
}
