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
			'url_count'      => 0, // only used by the generic handler
			'totals'         => array(
				'inserted'          => 0,
				'updated'           => 0,
				'suppressed'        => 0,
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

		// Resolve handler. 'auto' tries Shopify → Woo → generic in order.
		$hint = $state['platform_hint'] !== '' ? $state['platform_hint'] : 'auto';
		if ( ! in_array( $hint, array( 'shopify', 'woocommerce', 'generic', 'wix', 'auto' ), true ) ) {
			self::fail_attempt(
				$state,
				sprintf(
					/* translators: %s = platform name */
					__( 'Platform "%s" is not supported.', 'supplement-compare' ),
					$hint
				)
			);
			return;
		}

		// On page 1: pick the platform, fetch store meta, open import_run.
		// Follow-on pages inherit state['platform_used'] from the first page
		// so the cascade only runs once per attempt.
		if ( (int) $state['page'] === 1 ) {
			$state['export_run_id'] = (string) $attempt->run_id;
			$state['exported_at']   = current_time( 'c', true );

			$detect = self::detect_and_fetch_first_page( $state, $hint );
			if ( $detect === null ) {
				// Detection failed — finalize_attempt_failed was called inside.
				return;
			}
			$state['platform_used'] = $detect['platform_used'];
			$state['store_name']    = $detect['store_name'];
			$state['currency']      = $detect['currency'];
			$state['import_run_id'] = (int) Supcomp_CSV_Importer::begin_run(
				array(
					'filename'      => sprintf( 'extractor:%s', $state['site_slug'] ),
					'export_run_id' => $state['export_run_id'],
					'exported_at'   => $state['exported_at'],
					'source_kind'   => 'extractor',
				)
			);
			$page_result = $detect['page_result'];
		} else {
			// Follow-on page: dispatch to the locked-in platform.
			$page_result = self::fetch_page_for_platform( $state );
		}

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

		// Defensive: a chained action enqueued before the v1.23.0 deploy may lack
		// the suppressed slot. Self-heals on the next full run.
		if ( ! isset( $state['totals']['suppressed'] ) ) {
			$state['totals']['suppressed'] = 0;
		}
		$state['totals']['inserted']   += (int) $batch['inserted'];
		$state['totals']['updated']    += (int) $batch['updated'];
		$state['totals']['suppressed'] += (int) ( $batch['suppressed'] ?? 0 );
		$state['totals']['errored']    += (int) $batch['errored'];
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
			(int) $batch['errored'],
			(int) ( $batch['suppressed'] ?? 0 )
		);

		// Continue paginating if the page came back full and we're under the cap.
		list( $page_size, $max_pages ) = self::pagination_for( $state['platform_used'] );
		$is_final_page = (
			(int) $page_result['batch_size'] < $page_size
			|| (int) $state['page'] >= $max_pages
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
				'suppressed' => (int) ( $state['totals']['suppressed'] ?? 0 ),
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

		// Drop the generic-handler URL transient if one was set for this attempt.
		delete_transient( self::generic_url_transient_key( (int) $state['attempt_id'] ) );
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
					'suppressed' => (int) ( $state['totals']['suppressed'] ?? 0 ),
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
		delete_transient( self::generic_url_transient_key( (int) $state['attempt_id'] ) );
	}

	private static function fail_attempt( array $state, $message ) {
		Supcomp_Extract_Runs_Repo::set_failed( (int) $state['attempt_id'], (string) $message );
		Supcomp_Extract_Sites_Repo::record_run_result(
			(int) $state['site_id'],
			'failed',
			0,
			(string) $message
		);
		delete_transient( self::generic_url_transient_key( (int) $state['attempt_id'] ) );
	}

	/**
	 * Run the page-1 detection cascade. Returns the platform that succeeded,
	 * its store meta, and its first page of rows. Returns null if all
	 * applicable handlers fail; in that case finalize_attempt_failed has
	 * already been called with a clear operator message.
	 *
	 * Order is hinted by the operator's platform_hint:
	 *   - 'shopify' → Shopify only.
	 *   - 'woocommerce' → Woo only.
	 *   - 'generic' → generic JSON-LD engine; rows labeled source='generic'.
	 *   - 'wix' → same generic JSON-LD engine, but Shopify/Woo probes are
	 *     skipped and rows are labeled source='wix'. Wix sites are mechanically
	 *     generic JSON-LD with quirky key casing, which the generic handler
	 *     tolerates regardless of label — so 'auto' also discovers Wix sites
	 *     (it just labels them 'generic', since it can't prove a site is Wix).
	 *   - 'auto' → Shopify, then Woo, then generic JSON-LD.
	 *
	 * @return array{
	 *     platform_used:string,
	 *     store_name:string,
	 *     currency:string,
	 *     page_result:array,
	 * }|null
	 */
	private static function detect_and_fetch_first_page( array &$state, $hint ) {
		$try_shopify = ( $hint === 'shopify' || $hint === 'auto' );
		$try_woo     = ( $hint === 'woocommerce' || $hint === 'auto' );
		$try_wix     = ( $hint === 'wix' );
		$try_generic = ( $hint === 'generic' || $hint === 'auto' || $try_wix );

		// The generic JSON-LD engine serves both 'generic' and 'wix'; the
		// only difference is the label stamped on the run and its offers.
		$generic_label = $try_wix ? 'wix' : 'generic';

		$last_failure = '';

		if ( $try_shopify ) {
			$meta = Supcomp_Extractor_Shopify::fetch_store_meta( $state['site_url'] );
			$page = Supcomp_Extractor_Shopify::fetch_page(
				$state['site_url'], 1,
				$state['export_run_id'], $state['exported_at'],
				$meta['store_name'], $meta['currency']
			);
			if ( $page['status'] === 'ok' ) {
				return array(
					'platform_used' => 'shopify',
					'store_name'    => $meta['store_name'],
					'currency'      => $meta['currency'],
					'page_result'   => $page,
				);
			}
			$last_failure = sprintf(
				/* translators: 1: status, 2: HTTP code */
				__( 'Shopify probe: %1$s (HTTP %2$d).', 'supplement-compare' ),
				$page['status'],
				(int) $page['http_status']
			);
		}

		if ( $try_woo ) {
			$meta = Supcomp_Extractor_Woo::fetch_store_meta( $state['site_url'] );
			$page = Supcomp_Extractor_Woo::fetch_page(
				$state['site_url'], 1,
				$state['export_run_id'], $state['exported_at'],
				$meta['store_name']
			);
			if ( $page['status'] === 'ok' ) {
				return array(
					'platform_used' => 'woocommerce',
					'store_name'    => $meta['store_name'],
					'currency'      => $meta['currency'],
					'page_result'   => $page,
				);
			}
			$woo_msg = sprintf(
				/* translators: 1: status, 2: HTTP code */
				__( 'Woo probe: %1$s (HTTP %2$d).', 'supplement-compare' ),
				$page['status'],
				(int) $page['http_status']
			);
			$last_failure = $last_failure !== '' ? ( $last_failure . ' ' . $woo_msg ) : $woo_msg;
		}

		if ( $try_generic ) {
			$deps_ok = Supcomp_Extractor_Generic::dependencies_ok();
			if ( $deps_ok !== true ) {
				$last_failure = $last_failure !== '' ? ( $last_failure . ' ' . (string) $deps_ok ) : (string) $deps_ok;
				$try_generic  = false;
			}
		}
		if ( $try_generic ) {
			$urls = Supcomp_Extractor_Generic::discover_product_urls( $state['site_url'] );
			if ( ! empty( $urls ) ) {
				// Pass the first product URL so fetch_store_meta can recover a
				// seller/brand name when the homepage returns a generic default
				// (e.g. Wix's "My Site").
				$meta  = Supcomp_Extractor_Generic::fetch_store_meta( $state['site_url'], $urls[0] );
				$slice = array_slice( $urls, 0, Supcomp_Extractor_Generic::CHUNK_SIZE );
				$page  = Supcomp_Extractor_Generic::fetch_chunk(
					$state['site_url'],
					$slice,
					$state['export_run_id'],
					$state['exported_at'],
					$meta['store_name'],
					$generic_label
				);
				// Persist the full URL list so follow-on pages can slice into
				// it without re-discovering from scratch.
				set_transient( self::generic_url_transient_key( (int) $state['attempt_id'] ), $urls, 6 * HOUR_IN_SECONDS );
				$state['url_count'] = count( $urls );
				return array(
					'platform_used' => $generic_label,
					'store_name'    => $meta['store_name'],
					'currency'      => $meta['currency'],
					'page_result'   => $page,
				);
			}
			$gen_msg = __( 'Generic probe: no product URLs discovered from sitemap candidates.', 'supplement-compare' );
			$last_failure = $last_failure !== '' ? ( $last_failure . ' ' . $gen_msg ) : $gen_msg;
		}

		$msg = $hint === 'auto'
			? sprintf(
				__( 'Auto-detect failed: Shopify, WooCommerce, and generic JSON-LD sitemap discovery all failed. %s', 'supplement-compare' ),
				$last_failure
			)
			: sprintf(
				/* translators: 1: platform, 2: detail */
				__( '%1$s endpoint did not return products. %2$s', 'supplement-compare' ),
				$hint,
				$last_failure
			);

		self::finalize_attempt_failed( $state, $msg );
		return null;
	}

	/**
	 * Fetch a follow-on page (page > 1) for the platform that won the page-1
	 * cascade. Reads state['platform_used'].
	 */
	private static function fetch_page_for_platform( array $state ) {
		if ( $state['platform_used'] === 'woocommerce' ) {
			return Supcomp_Extractor_Woo::fetch_page(
				$state['site_url'],
				(int) $state['page'],
				$state['export_run_id'],
				$state['exported_at'],
				$state['store_name']
			);
		}
		// 'generic' and 'wix' share the JSON-LD engine; platform_used carries
		// the label to stamp on each row's source column.
		if ( $state['platform_used'] === 'generic' || $state['platform_used'] === 'wix' ) {
			$urls = get_transient( self::generic_url_transient_key( (int) $state['attempt_id'] ) );
			if ( ! is_array( $urls ) ) {
				// Transient expired or evicted — gracefully end the run.
				return array( 'rows' => array(), 'batch_size' => 0, 'status' => 'transient_lost', 'http_status' => 0 );
			}
			$offset = ( (int) $state['page'] - 1 ) * Supcomp_Extractor_Generic::CHUNK_SIZE;
			$slice  = array_slice( $urls, $offset, Supcomp_Extractor_Generic::CHUNK_SIZE );
			return Supcomp_Extractor_Generic::fetch_chunk(
				$state['site_url'],
				$slice,
				$state['export_run_id'],
				$state['exported_at'],
				$state['store_name'],
				$state['platform_used']
			);
		}
		// Default to Shopify (covers 'shopify' and legacy state without the field).
		return Supcomp_Extractor_Shopify::fetch_page(
			$state['site_url'],
			(int) $state['page'],
			$state['export_run_id'],
			$state['exported_at'],
			$state['store_name'],
			$state['currency']
		);
	}

	/**
	 * Per-platform pagination ceiling (page size, max pages).
	 */
	private static function pagination_for( $platform_used ) {
		if ( $platform_used === 'woocommerce' ) {
			return array( Supcomp_Extractor_Woo::PAGE_SIZE, Supcomp_Extractor_Woo::MAX_PAGES );
		}
		if ( $platform_used === 'generic' || $platform_used === 'wix' ) {
			return array( Supcomp_Extractor_Generic::CHUNK_SIZE, Supcomp_Extractor_Generic::MAX_PAGES );
		}
		return array( Supcomp_Extractor_Shopify::PAGE_SIZE, Supcomp_Extractor_Shopify::MAX_PAGES );
	}

	private static function generic_url_transient_key( $attempt_id ) {
		return 'supcomp_extract_urls_' . (int) $attempt_id;
	}
}
