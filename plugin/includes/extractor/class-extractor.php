<?php
/**
 * Orchestrator for the in-plugin product extractor.
 *
 * Phase A: skeleton only. `run()` resolves the site list, creates per-site
 * attempt rows in extract_runs (status=pending), and returns the run_id.
 * It does NOT actually scrape anything yet — Phase B fills in the Action
 * Scheduler enqueue + per-platform handler dispatch.
 *
 * Future shape (Phase B+):
 *   run( $site_ids )
 *     → generate run_id
 *     → for each site, create_attempt(run_id, site_id) → pending row
 *     → enqueue one AS action per site (action: 'supcomp_extract_site')
 *     → each AS action calls execute_site_attempt( $attempt_id )
 *           sets_running → tries Shopify → Woo → generic → batches rows into
 *           Supcomp_CSV_Importer::ingest_rows() → set_complete | set_failed
 *           → updates extract_sites.last_run_* telemetry.
 *
 * Phase A callers can use run() to verify the plumbing reaches the DB; the
 * resulting attempts will sit in 'pending' forever until Phase B's worker
 * is wired up.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Extractor {

	/**
	 * Kick off a new extractor run.
	 *
	 * @param int[]  $site_ids       Optional. If empty, all enabled sites.
	 * @param string $triggered_by   'manual' (admin button) | 'schedule' | 'api'.
	 * @return array{run_id:string, attempt_ids:int[], skipped:int}
	 */
	public static function run( array $site_ids = array(), $triggered_by = 'manual' ) {
		$sites = self::resolve_sites( $site_ids );

		$run_id      = Supcomp_Extract_Runs_Repo::generate_run_id();
		$attempt_ids = array();
		$skipped     = 0;

		foreach ( $sites as $site ) {
			if ( (int) $site->enabled !== 1 && empty( $site_ids ) ) {
				++$skipped;
				continue;
			}
			$attempt_id = Supcomp_Extract_Runs_Repo::create_attempt( $run_id, (int) $site->id, $triggered_by );
			if ( $attempt_id > 0 ) {
				$attempt_ids[] = $attempt_id;
			} else {
				++$skipped;
			}
		}

		do_action(
			'supcomp_extract_run_started',
			array(
				'run_id'      => $run_id,
				'attempt_ids' => $attempt_ids,
				'triggered_by'=> $triggered_by,
			)
		);

		return array(
			'run_id'      => $run_id,
			'attempt_ids' => $attempt_ids,
			'skipped'     => $skipped,
		);
	}

	/**
	 * Resolve a list of site ids (or all enabled sites if empty) into a
	 * list of site row objects.
	 */
	private static function resolve_sites( array $site_ids ) {
		if ( empty( $site_ids ) ) {
			return Supcomp_Extract_Sites_Repo::list_all( true );
		}
		$out = array();
		foreach ( $site_ids as $id ) {
			$site = Supcomp_Extract_Sites_Repo::get( (int) $id );
			if ( $site ) {
				$out[] = $site;
			}
		}
		return $out;
	}
}
