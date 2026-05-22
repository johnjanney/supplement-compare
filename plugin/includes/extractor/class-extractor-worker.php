<?php
/**
 * Action Scheduler worker for the extractor.
 *
 * Each `supcomp_extract_page` AS action processes ONE platform page (≤250
 * Shopify products / ≤100 Woo products / one chunk of generic JSON-LD
 * URLs). Multi-page sites chain follow-on actions from inside the worker.
 *
 * State is passed through AS args, not stored externally — one $state array
 * per chained action. That keeps the worker self-contained and avoids
 * transient/option churn when the run is long.
 *
 * Phase B only wires Shopify (or 'auto', which attempts Shopify). Phase C
 * adds Woo; Phase D adds generic JSON-LD.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Extractor_Worker {

	const HOOK             = 'supcomp_extract_page';
	const AS_GROUP         = 'supcomp_extractor';
	const ROW_ERROR_SAMPLE = 25;

	public static function register_hooks() {
		add_action( self::HOOK, array( __CLASS__, 'execute_page' ), 10, 1 );
	}

	/**
	 * Enqueue the first page for an attempt. Called by the orchestrator.
	 * Returns the AS action id (0 on failure).
	 */
	public static function enqueue_initial( $attempt_id, $site_row, $triggered_by = 'manual' ) {
		$state = array(
			'attempt_id'     => (int) $attempt_id,
			'site_id'        => (int) $site_row->id,
			'site_slug'      => (string) $site_row->slug,
			'site_url'       => (string) $site_row->site_url,
			'platform_hint'  => (string) $site_row->platform_hint,
			'merchant_id'    => (int) $site_row->merchant_id,
			'triggered_by'   => $triggered_by,
			'page'           => 1,
			'platform_used'  => '', // filled in on page 1 once a handler succeeds
			'import_run_id'  => 0,
			'export_run_id'  => '',
			'exported_at'    => '',
			'store_name'     => '',
			'currency'       => '',
			'totals'         => array(
				'inserted'          => 0,
				'updated'           => 0,
				'errored'           => 0,
				'row_errors_sample' => array(),
			),
		);
		return self::enqueue_state( $state );
	}

	private static function enqueue_state( array $state ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return 0;
		}
		return as_enqueue_async_action( self::HOOK, array( $state ), self::AS_GROUP );
	}

	/**
	 * AS callback. Runs in a separate request via AS's queue runner so it's
	 * NOT bounded by the user's web-request execution time.
	 */
	public static function execute_page( $state ) {
		// AS occasionally re-fires with a stale or partial state; defensive parse.
		if ( ! is_array( $state ) || empty( $state['attempt_id'] ) || empty( $state['site_url'] ) ) {
			return;
		}

		$attempt = Supcomp_Extract_Runs_Repo::get( (int) $state['attempt_id'] );
		if ( ! $attempt || in_array( $attempt->status, array( 'complete', 'failed', 'canceled' ), true ) ) {
			return;
		}

		if ( (int) $state['page'] === 1 && $attempt->status === 'pending' ) {
			Supcomp_Extract_Runs_Repo::set_running( (int) $state['attempt_id'] );
		}

		// Merchant must be linked on the site row — without it /out/{id} can't
		// fire the affiliate template downstream.
		if ( (int) $state['merchant_id'] <= 0 ) {
			self::fail_attempt(
				$state,
				__( 'Site has no merchant linked. Edit the Extractor Sites row and pick a Merchant before re-running.', 'supplement-compare' )
			);
			return;
		}

		// Resolve handler. Phase B only does Shopify (or 'auto' → tries Shopify).
		$hint = $state['platform_hint'] !== '' ? $state['platform_hint'] : 'auto';
		if ( ! in_array( $hint, array( 'shopify', 'auto' ), true ) ) {
			self::fail_attempt(
				$state,
				sprintf(
					/* translators: %s = platform name */
					__( 'Platform "%s" is not yet supported. Phase B ships Shopify; Woo lands in Phase C, generic in Phase D.', 'supplement-compare' ),
					$hint
				)
			);
			return;
		}

		// On page 1: cache store meta, generate run_id, open import_run.
		if ( (int) $state['page'] === 1 ) {
			$meta = Supcomp_Extractor_Shopify::fetch_store_meta( $state['site_url'] );
			$state['store_name']    = $meta['store_name'];
			$state['currency']      = $meta['currency'];
			$state['export_run_id'] = (string) $attempt->run_id;
			$state['exported_at']   = current_time( 'c', true );
			$state['import_run_id'] = (int) Supcomp_CSV_Importer::begin_run(
				array(
					'filename'      => sprintf( 'extractor:%s', $state['site_slug'] ),
					'export_run_id' => $state['export_run_id'],
					'exported_at'   => $state['exported_at'],
					'source_kind'   => 'extractor',
				)
			);
		}

		// Fetch the page.
		$page_result = Supcomp_Extractor_Shopify::fetch_page(
			$state['site_url'],
			(int) $state['page'],
			$state['export_run_id'],
			$state['exported_at'],
			$state['store_name'],
			$state['currency']
		);

		// Page 1 platform-detect: if Shopify probe returns not_shopify or http_error,
		// the site genuinely isn't Shopify (or unreachable). Phase B fails here;
		// Phase C will fall through to Woo on this branch.
		if ( (int) $state['page'] === 1 && in_array( $page_result['status'], array( 'not_shopify', 'http_error', 'empty' ), true ) ) {
			$msg = $page_result['status'] === 'not_shopify'
				? sprintf( __( 'Site did not respond to Shopify /products.json (HTTP %d). Phase B only supports Shopify. Woo handler lands in Phase C.', 'supplement-compare' ), (int) $page_result['http_status'] )
				: sprintf( __( 'HTTP error fetching /products.json (status %d).', 'supplement-compare' ), (int) $page_result['http_status'] );
			self::finalize_attempt_failed( $state, $msg );
			return;
		}

		$state['platform_used'] = 'shopify';

		// Inject _merchant_id into every row so the importer can persist them.
		$rows = array();
		foreach ( $page_result['rows'] as $row ) {
			$row['_merchant_id'] = (int) $state['merchant_id'];
			$rows[] = $row;
		}

		// Empty page = final-page marker for Shopify (when batch came back empty
		// but the run already saw products earlier). Skip ingest, jump to finalize.
		if ( empty( $rows ) ) {
			self::finalize_attempt_complete( $state );
			return;
		}

		$batch = Supcomp_CSV_Importer::ingest_rows_into_run( $rows, (int) $state['import_run_id'] );

		$state['totals']['inserted'] += (int) $batch['inserted'];
		$state['totals']['updated']  += (int) $batch['updated'];
		$state['totals']['errored']  += (int) $batch['errored'];
		// Keep only a sample of row errors so action args don't bloat.
		if ( ! empty( $batch['row_errors'] ) ) {
			$slots = self::ROW_ERROR_SAMPLE - count( $state['totals']['row_errors_sample'] );
			if ( $slots > 0 ) {
				foreach ( array_slice( $batch['row_errors'], 0, $slots, true ) as $rn => $msg ) {
					$state['totals']['row_errors_sample'][ 'p' . $state['page'] . '_' . $rn ] = $msg;
				}
			}
		}

		// Flush per-batch counts so the operator can see progress mid-run on
		// the import-runs admin screen (Phase E will surface this in the
		// extractor-runs screen too).
		Supcomp_CSV_Importer::record_batch_counts(
			(int) $state['import_run_id'],
			(int) $batch['inserted'],
			(int) $batch['updated'],
			(int) $batch['errored']
		);

		// Continue paginating if the page came back full and we're under the cap.
		$is_final_page = (
			(int) $page_result['batch_size'] < Supcomp_Extractor_Shopify::PAGE_SIZE
			|| (int) $state['page'] >= Supcomp_Extractor_Shopify::MAX_PAGES
		);

		if ( ! $is_final_page ) {
			$state['page'] = (int) $state['page'] + 1;
			self::enqueue_state( $state );
			return;
		}

		self::finalize_attempt_complete( $state );
	}

	/**
	 * Final page reached cleanly — close out import_run with stale detection
	 * and mark the extract attempt complete.
	 */
	private static function finalize_attempt_complete( array $state ) {
		Supcomp_CSV_Importer::finalize_run(
			(int) $state['import_run_id'],
			array(
				'inserted'   => (int) $state['totals']['inserted'],
				'updated'    => (int) $state['totals']['updated'],
				'errored'    => (int) $state['totals']['errored'],
				'row_errors' => $state['totals']['row_errors_sample'],
			),
			array( (int) $state['merchant_id'] => true ),
			'extractor'
		);

		Supcomp_Extract_Runs_Repo::set_complete(
			(int) $state['attempt_id'],
			(string) $state['platform_used'],
			(int) $state['totals']['inserted'] + (int) $state['totals']['updated']
		);

		Supcomp_Extract_Sites_Repo::record_run_result(
			(int) $state['site_id'],
			'complete',
			(int) $state['totals']['inserted'] + (int) $state['totals']['updated'],
			null
		);
	}

	/**
	 * Failure path — used for page-1 detection failures, missing merchants, etc.
	 * If an import_run was opened (page > 1 already), close it; otherwise just
	 * fail the attempt.
	 */
	private static function finalize_attempt_failed( array $state, $message ) {
		if ( (int) $state['import_run_id'] > 0 ) {
			Supcomp_CSV_Importer::finalize_run(
				(int) $state['import_run_id'],
				array(
					'inserted'   => (int) $state['totals']['inserted'],
					'updated'    => (int) $state['totals']['updated'],
					'errored'    => (int) $state['totals']['errored'],
					'row_errors' => $state['totals']['row_errors_sample'],
				),
				array( (int) $state['merchant_id'] => true ),
				'extractor'
			);
		}

		Supcomp_Extract_Runs_Repo::set_failed( (int) $state['attempt_id'], (string) $message );
		Supcomp_Extract_Sites_Repo::record_run_result(
			(int) $state['site_id'],
			'failed',
			(int) $state['totals']['inserted'] + (int) $state['totals']['updated'],
			(string) $message
		);
	}

	private static function fail_attempt( array $state, $message ) {
		Supcomp_Extract_Runs_Repo::set_failed( (int) $state['attempt_id'], (string) $message );
		Supcomp_Extract_Sites_Repo::record_run_result(
			(int) $state['site_id'],
			'failed',
			0,
			(string) $message
		);
	}
}
