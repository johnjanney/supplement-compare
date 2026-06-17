<?php
/**
 * Repository for `offer_suppressions` (v1.23.0).
 *
 * The suppression list is the durable memory behind the operator-facing
 * promise in INSTRUCTIONS.md §9 that rejecting an offer keeps it off the site
 * for good. A plain rejection is only sticky while the offer row survives;
 * once Cleanup hard-deletes a rejected offer, its natural-key memory is gone
 * and a re-extracted product would re-enter the pending queue. A suppression
 * row records the natural key independently of the offer, and the importer
 * checks it before inserting (see Supcomp_CSV_Importer::ingest_rows_into_run).
 *
 * Keyed on the same tuple the importer dedups on:
 *   (merchant_id, source_product_id, source_variant_id)
 *
 * Records are created automatically when a *rejected* offer is hard-deleted
 * (Supcomp_Deletion_Service::hard_delete_offer). Lifting a suppression is the
 * operator's escape hatch — the next import re-inserts the product as pending.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Suppressions_Repo {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'supcomp_offer_suppressions';
	}

	/**
	 * Is this exact natural key suppressed?
	 */
	public static function is_suppressed( $merchant_id, $source_product_id, $source_variant_id ) {
		global $wpdb;
		$table = self::table();
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE merchant_id = %d AND source_product_id = %s AND source_variant_id = %s",
				(int) $merchant_id,
				(string) $source_product_id,
				(string) ( $source_variant_id ?? '' )
			)
		);
		return $found !== null;
	}

	/**
	 * All suppressed natural keys for a merchant, as a lookup set keyed by
	 * "source_product_id|source_variant_id". Used by the importer to preload
	 * once per merchant instead of querying per row.
	 *
	 * @return array<string,true>
	 */
	public static function keys_for_merchant( $merchant_id ) {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_product_id, source_variant_id FROM {$table} WHERE merchant_id = %d",
				(int) $merchant_id
			)
		);
		$set = array();
		foreach ( (array) $rows as $r ) {
			$set[ $r->source_product_id . '|' . $r->source_variant_id ] = true;
		}
		return $set;
	}

	/**
	 * Record a suppression. Idempotent on the natural key — a repeat call
	 * refreshes the snapshot/reason rather than erroring on the unique index.
	 *
	 * @param array $snapshot  Optional 'product_title' / 'brand' for display.
	 * @return int  Suppression row id (existing id on a duplicate).
	 */
	public static function record( $merchant_id, $source_product_id, $source_variant_id, $snapshot = array(), $reason = 'rejected_cleanup', $source_offer_id = null ) {
		global $wpdb;
		$table = self::table();

		$merchant_id       = (int) $merchant_id;
		$source_product_id = (string) $source_product_id;
		$source_variant_id = (string) ( $source_variant_id ?? '' );

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE merchant_id = %d AND source_product_id = %s AND source_variant_id = %s",
				$merchant_id,
				$source_product_id,
				$source_variant_id
			)
		);

		$data = array(
			'product_title'   => isset( $snapshot['product_title'] ) ? self::trim_to( (string) $snapshot['product_title'], 512 ) : '',
			'brand'           => isset( $snapshot['brand'] ) ? self::trim_to( (string) $snapshot['brand'], 255 ) : '',
			'reason'          => in_array( $reason, Supcomp_Installer::SUPPRESSION_REASONS, true ) ? $reason : 'rejected_cleanup',
			'source_offer_id' => $source_offer_id !== null ? (int) $source_offer_id : null,
		);

		if ( $existing !== null ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $existing ) );
			return (int) $existing;
		}

		$data['merchant_id']       = $merchant_id;
		$data['source_product_id'] = $source_product_id;
		$data['source_variant_id'] = $source_variant_id;
		$data['created_at']        = current_time( 'mysql', true );
		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) );
	}

	/**
	 * Lift a suppression by id. The next import re-inserts the product as
	 * pending if it is still live on the merchant.
	 */
	public static function remove( $id ) {
		global $wpdb;
		return false !== $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Count suppressions, optionally narrowed by a free-text search (v1.31.2).
	 */
	public static function count_all( $search = '' ) {
		global $wpdb;
		$table = self::table();
		list( $where_sql, $params ) = self::search_where( $search );
		$sql = "SELECT COUNT(*) FROM {$table} s{$where_sql}";
		if ( empty( $params ) ) {
			return (int) $wpdb->get_var( $sql );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Page of suppressions joined to the merchant name for the admin list.
	 * Newest first. Optionally narrowed by a free-text search (v1.31.2)
	 * matching product title, brand, or source product id.
	 *
	 * @return array<object>
	 */
	public static function paginate( $page = 1, $per_page = 50, $search = '' ) {
		global $wpdb;
		$table     = self::table();
		$merchants = $wpdb->prefix . 'supcomp_merchants';
		$page      = max( 1, (int) $page );
		$per_page  = max( 1, (int) $per_page );
		$offset    = ( $page - 1 ) * $per_page;

		list( $where_sql, $params ) = self::search_where( $search );
		$params[] = $per_page;
		$params[] = $offset;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, m.name AS merchant_name
				 FROM {$table} s
				 LEFT JOIN {$merchants} m ON m.id = s.merchant_id
				 {$where_sql}
				 ORDER BY s.id DESC
				 LIMIT %d OFFSET %d",
				$params
			)
		);
	}

	/**
	 * Build the WHERE fragment for the admin free-text search. Returns
	 * [ ' WHERE …' | '', $params ]. Matches product_title, brand, and the
	 * source product id; merchant name is intentionally out of scope (search
	 * box only — see v1.31.2).
	 *
	 * @return array{0:string,1:array}
	 */
	private static function search_where( $search ) {
		global $wpdb;
		$search = trim( (string) $search );
		if ( $search === '' ) {
			return array( '', array() );
		}
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		return array(
			' WHERE (s.product_title LIKE %s OR s.brand LIKE %s OR s.source_product_id LIKE %s)',
			array( $like, $like, $like ),
		);
	}

	private static function trim_to( $val, $max ) {
		return strlen( $val ) > $max ? substr( $val, 0, $max ) : $val;
	}
}
