<?php
/**
 * CSV importer — orchestrates PROJECTBRIEF.md §8 Phase 4 step 1-6.
 *
 * Pipeline:
 *   1. Validator runs (separately, before this class is called).
 *   2. If dry_run, do nothing else — just echo what would happen.
 *   3. Create an import_run row (status=importing).
 *   4. For each validated row:
 *        a. Insert into raw_source_offers (audit table; never updated).
 *        b. Lookup existing normalized_offer by (merchant, product, variant).
 *        c. New → insert as 'pending' (Phase 6 operator approval needed).
 *           Existing → update CSV columns; if pricing or stock changed,
 *           log a price_history row. 'stale' offers are restored to 'active'.
 *   5. Stale detector runs against the set of merchants that participated.
 *   6. Update the import_run row with counts and status=complete.
 *
 * Errors at the row level (DB failures, bad rows the validator let through)
 * increment rows_errored but do not abort the run. The run's `error_log`
 * column collects the first ~50 row errors for the operator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_CSV_Importer {

	const MAX_ERRORS_IN_LOG = 50;

	/**
	 * @param array $validated  Output of Supcomp_CSV_Validator::validate().
	 * @param array $args       'dry_run' (bool), 'filename' (string).
	 * @return array{
	 *     run_id: int,           // 0 for dry-run
	 *     inserted: int,
	 *     updated: int,
	 *     stale: int,
	 *     errored: int,
	 *     row_errors: array<int,string>,
	 * }
	 */
	public static function import( $validated, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'dry_run'  => false,
				'filename' => '',
			)
		);

		$rows         = $validated['rows'];
		$row_errors   = array();
		$inserted     = 0;
		$updated      = 0;
		$stale        = 0;
		$errored      = 0;
		$merchant_ids = array();

		if ( $args['dry_run'] ) {
			// Without writes we can still report what *would* happen: every
			// row would either insert (no existing match) or update (existing).
			foreach ( $rows as $row_num => $row ) {
				$existing = Supcomp_Offers_Repo::find_by_natural_key(
					$row['_merchant_id'],
					(string) $row['source_product_id'],
					(string) ( $row['source_variant_id'] ?? '' )
				);
				if ( $existing ) {
					++$updated;
				} else {
					++$inserted;
				}
				$merchant_ids[ $row['_merchant_id'] ] = true;
			}
			return array(
				'run_id'     => 0,
				'inserted'   => $inserted,
				'updated'    => $updated,
				'stale'      => 0, // dry-run does not project stale counts (would require a hypothetical run id)
				'errored'    => 0,
				'row_errors' => array(),
			);
		}

		$run_id = Supcomp_Import_Runs_Repo::create_run( $args['filename'], count( $rows ) );
		Supcomp_Import_Runs_Repo::set_export_metadata(
			$run_id,
			$validated['export_run_id'],
			$validated['exported_at']
		);
		Supcomp_Import_Runs_Repo::set_status( $run_id, 'importing' );

		$now = current_time( 'mysql', true );

		foreach ( $rows as $row_num => $row ) {
			$merchant_id = (int) $row['_merchant_id'];
			$merchant_ids[ $merchant_id ] = true;

			try {
				self::insert_raw( $merchant_id, $row, $run_id, $now );

				$existing = Supcomp_Offers_Repo::find_by_natural_key(
					$merchant_id,
					(string) $row['source_product_id'],
					(string) ( $row['source_variant_id'] ?? '' )
				);

				if ( $existing ) {
					$restore_from_stale = ( $existing->visibility_status === 'stale' );
					$new_data           = Supcomp_Offers_Repo::update_csv_columns(
						(int) $existing->id,
						$row,
						$run_id,
						$now,
						$restore_from_stale
					);
					$diff = Supcomp_Offers_Repo::diff_for_price_history( $existing, $new_data );
					if ( $diff !== null ) {
						Supcomp_Price_History_Repo::record_change( (int) $existing->id, $diff, $run_id );
					}
					// Normalization + matching DO NOT re-run on updates — operator
					// edits in the pending queue (Phase 6) are sticky. Derivations
					// recompute every time so cost-per-active-unit tracks price.
					self::run_derivations_for( (int) $existing->id );
					++$updated;
				} else {
					$new_id = Supcomp_Offers_Repo::insert_from_csv( $merchant_id, $row, $run_id, $now );
					self::normalize_and_match( $new_id, $row );
					self::run_derivations_for( $new_id );
					++$inserted;
				}
			} catch ( Exception $e ) {
				++$errored;
				$row_errors[ $row_num ] = $e->getMessage();
			} catch ( Error $e ) {
				++$errored;
				$row_errors[ $row_num ] = $e->getMessage();
			}
		}

		// Stale detection only over merchants that participated in this run.
		if ( ! empty( $merchant_ids ) ) {
			$stale = Supcomp_Stale_Detector::mark_stale( array_keys( $merchant_ids ), $run_id );
		}

		Supcomp_Import_Runs_Repo::update_counts( $run_id, $inserted, $updated, $stale, $errored );
		Supcomp_Import_Runs_Repo::set_status(
			$run_id,
			$errored > 0 ? 'complete' : 'complete',
			self::truncate_error_log( $row_errors )
		);

		return array(
			'run_id'     => $run_id,
			'inserted'   => $inserted,
			'updated'    => $updated,
			'stale'      => $stale,
			'errored'    => $errored,
			'row_errors' => $row_errors,
		);
	}

	/**
	 * Run the normalizer + matcher for a freshly-inserted offer. Writes
	 * ingredient, form, strength, count, standardization, canonical match,
	 * and confidence onto the offer row.
	 */
	private static function normalize_and_match( $offer_id, $row ) {
		$normalized = Supcomp_Normalizer::normalize( $row );
		$match      = Supcomp_Matcher::match( $row, $normalized );
		Supcomp_Offers_Repo::apply_normalization_and_match( $offer_id, $normalized, $match );
	}

	/**
	 * Recompute the derived field set (total_strength, active_*, cost_*)
	 * from the offer's current DB state — covers operator edits made
	 * between imports.
	 */
	private static function run_derivations_for( $offer_id ) {
		$offer = Supcomp_Offers_Repo::get( $offer_id );
		if ( ! $offer ) {
			return;
		}
		$ingredient = $offer->ingredient_id ? Supcomp_Ingredients_Repo::get( (int) $offer->ingredient_id ) : null;
		$derived    = Supcomp_Offer_Derivations::compute( $offer, $ingredient );
		Supcomp_Offers_Repo::apply_derivations( $offer_id, $derived );
	}

	private static function insert_raw( $merchant_id, $row, $run_id, $now ) {
		global $wpdb;
		$table = $wpdb->prefix . 'supcomp_raw_source_offers';
		// Strip the validator-internal _merchant_id before snapshotting.
		$snapshot = $row;
		unset( $snapshot['_merchant_id'] );
		$wpdb->insert(
			$table,
			array(
				'import_run_id'     => (int) $run_id,
				'merchant_id'       => (int) $merchant_id,
				'source_platform'   => isset( $row['source'] ) ? (string) $row['source'] : '',
				'source_product_id' => isset( $row['source_product_id'] ) ? (string) $row['source_product_id'] : '',
				'source_variant_id' => isset( $row['source_variant_id'] ) ? (string) $row['source_variant_id'] : '',
				'raw_csv_row_json'  => wp_json_encode( $snapshot ),
				'imported_at'       => $now,
			)
		);
	}

	private static function truncate_error_log( $row_errors ) {
		if ( empty( $row_errors ) ) {
			return '';
		}
		$lines = array();
		$count = 0;
		foreach ( $row_errors as $row_num => $msg ) {
			if ( $count >= self::MAX_ERRORS_IN_LOG ) {
				$lines[] = sprintf( '… and %d more', count( $row_errors ) - $count );
				break;
			}
			$lines[] = sprintf( 'row %d: %s', $row_num, $msg );
			++$count;
		}
		return implode( "\n", $lines );
	}
}
