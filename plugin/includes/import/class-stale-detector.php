<?php
/**
 * Stale detection — PROJECTBRIEF.md §8 Phase 4 step 6.
 *
 * After an import completes, for each merchant that participated in the run,
 * any offer whose last_seen_import_run_id != current run AND whose visibility
 * is in {pending, active, needs_review} gets flipped to 'stale'. The other
 * states (paused, rejected, dead, already stale) are operator-set and stay
 * untouched.
 *
 * When an offer is flipped to 'stale' we stash its prior status in
 * pre_stale_status so the restore path can return it exactly where it was.
 * This matters because the tracked set includes 'pending' and
 * 'needs_review': an offer that the operator never approved can go stale
 * (e.g. a merchant endpoint drops it for one run) and later reappear. The
 * old code blindly restored every returning offer to 'active', which
 * auto-published offers that never cleared the pending queue (invariant #1).
 *
 * The corresponding restore-from-stale lives in
 * Supcomp_Offers_Repo::update_csv_columns(): when a 'stale' offer appears
 * again in a fresh import it goes back to pre_stale_status (falling back to
 * 'pending' — never 'active' — when that prior status is unknown).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Stale_Detector {

	const TRACKED_STATUSES = array( 'pending', 'active', 'needs_review' );

	/**
	 * @param int[] $merchant_ids Merchants that appeared in the current run.
	 * @param int   $run_id       The import_runs.id of the current run.
	 * @return int Number of offers flipped to 'stale'.
	 */
	public static function mark_stale( $merchant_ids, $run_id ) {
		global $wpdb;
		$merchant_ids = array_values( array_filter( array_map( 'intval', $merchant_ids ) ) );
		if ( empty( $merchant_ids ) ) {
			return 0;
		}
		$table = $wpdb->prefix . 'supcomp_normalized_offers';

		$merchant_placeholders = implode( ',', array_fill( 0, count( $merchant_ids ), '%d' ) );
		$status_placeholders   = implode( ',', array_fill( 0, count( self::TRACKED_STATUSES ), '%s' ) );

		$sql = "UPDATE {$table}
				SET pre_stale_status = visibility_status,
					visibility_status = 'stale',
					updated_at = %s
				WHERE merchant_id IN ({$merchant_placeholders})
				  AND (last_seen_import_run_id IS NULL OR last_seen_import_run_id <> %d)
				  AND visibility_status IN ({$status_placeholders})";

		$params = array_merge(
			array( current_time( 'mysql', true ) ),
			$merchant_ids,
			array( (int) $run_id ),
			self::TRACKED_STATUSES
		);

		$affected = $wpdb->query( $wpdb->prepare( $sql, $params ) );
		return is_numeric( $affected ) ? (int) $affected : 0;
	}
}
