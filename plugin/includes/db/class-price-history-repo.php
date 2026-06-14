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
				'old_current_price' => isset( $diff['old_current_price'] ) ? $diff['old_current_price'] : null,
				'new_current_price' => isset( $diff['new_current_price'] ) ? $diff['new_current_price'] : null,
				'old_stock_status'  => $diff['old_stock_status'],
				'new_stock_status'  => $diff['new_stock_status'],
				'import_run_id'     => (int) $import_run_id,
				'changed_at'        => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Most-recent effective-price move per offer, for the public price-direction
	 * indicator. For each offer in $offer_ids, returns the direction and
	 * magnitude of its latest current_price change — but only when that change
	 * happened within the last $window_days. Offers whose price last moved
	 * before the window (or never moved) are simply absent from the result.
	 *
	 * Returns a map: offer_id => array( 'dir' => 'up'|'down', 'pct' => float ).
	 * $window_days <= 0 disables the feature (returns an empty map).
	 *
	 * Rows predating the v1.25.0 schema upgrade have NULL current_price columns
	 * and are skipped — the indicator simply lights up as fresh history accrues.
	 */
	public static function price_moves_for_offers( $offer_ids, $window_days ) {
		global $wpdb;

		$offer_ids   = array_values( array_filter( array_map( 'absint', (array) $offer_ids ) ) );
		$window_days = (int) $window_days;
		if ( empty( $offer_ids ) || $window_days <= 0 ) {
			return array();
		}

		$table        = self::table();
		$threshold    = gmdate( 'Y-m-d H:i:s', time() - ( $window_days * DAY_IN_SECONDS ) );
		$placeholders = implode( ',', array_fill( 0, count( $offer_ids ), '%d' ) );
		$params       = array_merge( $offer_ids, array( $threshold ) );

		// Newest change first per offer, so the first row we see for an offer_id
		// is its last move. Only rows with a real, computable price delta count.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT offer_id, old_current_price, new_current_price
				 FROM {$table}
				 WHERE offer_id IN ({$placeholders})
				   AND changed_at >= %s
				   AND old_current_price IS NOT NULL
				   AND new_current_price IS NOT NULL
				   AND old_current_price > 0
				   AND ABS( new_current_price - old_current_price ) > 0.00005
				 ORDER BY offer_id ASC, changed_at DESC, id DESC",
				$params
			)
		);

		$out = array();
		foreach ( $rows as $r ) {
			$oid = (int) $r->offer_id;
			if ( isset( $out[ $oid ] ) ) {
				continue; // already captured this offer's most-recent move
			}
			$old = (float) $r->old_current_price;
			$new = (float) $r->new_current_price;
			if ( $old <= 0 ) {
				continue;
			}
			$out[ $oid ] = array(
				'dir' => $new > $old ? 'up' : 'down',
				'pct' => round( abs( ( $new - $old ) / $old ) * 100, 2 ),
			);
		}

		return $out;
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
