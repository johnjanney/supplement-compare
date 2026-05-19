<?php
/**
 * Repository for `price_history`.
 *
 * Caller supplies the precomputed diff (from Supcomp_Offers_Repo::diff_for_price_history).
 * No-op when diff is null — the public `record_change` is safe to call
 * unconditionally on every row update.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Price_History_Repo {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'supcomp_price_history';
	}

	public static function record_change( $offer_id, $diff, $import_run_id ) {
		if ( $diff === null ) {
			return null;
		}
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'offer_id'          => (int) $offer_id,
				'old_regular_price' => $diff['old_regular_price'],
				'new_regular_price' => $diff['new_regular_price'],
				'old_sale_price'    => $diff['old_sale_price'],
				'new_sale_price'    => $diff['new_sale_price'],
				'old_stock_status'  => $diff['old_stock_status'],
				'new_stock_status'  => $diff['new_stock_status'],
				'import_run_id'     => (int) $import_run_id,
				'changed_at'        => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function for_offer( $offer_id, $limit = 50 ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE offer_id = %d ORDER BY changed_at DESC LIMIT %d",
				absint( $offer_id ),
				(int) $limit
			)
		);
	}
}
