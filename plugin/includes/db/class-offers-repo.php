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
