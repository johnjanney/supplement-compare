<?php
/**
 * Stale-run reaper for the in-plugin extractor.
 *
 * The worker's try/finally (class-extractor-worker.php) closes out an attempt
 * on every normal or thrown exit. But a hard PHP fatal — OOM, the host killing
 * a long request, a `max_execution_time` cut — can terminate the queue-runner
 * process before `finally` runs, leaving the `extract_runs` row stuck at
 * `running`/`pending` forever. The Extractor Sites screen then shows the site
 * as permanently "in flight", and each re-trigger piles on another orphan.
 *
 * This reaper is the backstop. It fails any open attempt that is BOTH:
 *   1. older than the operator-configured threshold (default 30 min), AND
 *   2. has no live Action Scheduler page action still queued for it.
 *
 * The second condition is what keeps the reaper from killing a slow-but-live
 * run: a chain that is still paginating always has a pending/in-progress action
 * (the worker enqueues page N+1 before page N's action completes), so it is
 * never reaped no matter how long the whole run takes. Only genuinely dead
 * chains — no queued action, status still open — get failed.
 *
 * Two triggers (both wired in Supcomp_Plugin::load_domain / the Extractor Sites
 * screen):
 *   - a recurring Action Scheduler action (hourly), for unattended healing; and
 *   - a throttled lazy sweep when the operator loads the Extractor Sites screen,
 *     so the dashboard self-heals the moment it is viewed.
 *
 * The threshold is stored in the `supcomp_extract_stale_minutes` option,
 * editable on the Settings screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Extractor_Reaper {

	const OPTION_KEY       = 'supcomp_extract_stale_minutes';
	const DEFAULT_MINUTES  = 30;
	const MIN_MINUTES      = 5;
	const MAX_MINUTES      = 1440; // 24h ceiling — guards a fat-fingered entry.
	const REAP_HOOK        = 'supcomp_extract_reap';
	const LAZY_THROTTLE    = 'supcomp_extract_reap_throttle';

	public static function register_hooks() {
		add_action( self::REAP_HOOK, array( __CLASS__, 'reap_scheduled' ) );
		// Reconcile the recurring action on every load — cheap when already
		// scheduled, idempotent otherwise. Mirrors the extractor scheduler.
		add_action( 'plugins_loaded', array( __CLASS__, 'ensure_scheduled' ), 25 );
	}

	/**
	 * Effective threshold in minutes, clamped to a sane band. The Settings
	 * sanitizer enforces the same band on write; this re-clamps on read so a
	 * value poked in directly (WP-CLI, REST, DB) still behaves.
	 */
	public static function get_threshold_minutes() {
		$v = (int) get_option( self::OPTION_KEY, self::DEFAULT_MINUTES );
		if ( $v <= 0 ) {
			return self::DEFAULT_MINUTES;
		}
		return max( self::MIN_MINUTES, min( self::MAX_MINUTES, $v ) );
	}

	/**
	 * Make sure the recurring reap action is scheduled exactly once.
	 */
	public static function ensure_scheduled() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::REAP_HOOK, null, Supcomp_Extractor_Worker::AS_GROUP ) ) {
			as_schedule_recurring_action(
				time() + HOUR_IN_SECONDS,
				HOUR_IN_SECONDS,
				self::REAP_HOOK,
				array(),
				Supcomp_Extractor_Worker::AS_GROUP
			);
		}
	}

	/**
	 * Recurring-action callback. Reaps using the configured threshold.
	 */
	public static function reap_scheduled() {
		self::reap( self::get_threshold_minutes() );
	}

	/**
	 * Lazy sweep on Extractor Sites screen load, throttled so rapid page
	 * reloads don't re-query Action Scheduler every time.
	 */
	public static function maybe_reap_on_load() {
		if ( get_transient( self::LAZY_THROTTLE ) ) {
			return;
		}
		set_transient( self::LAZY_THROTTLE, 1, MINUTE_IN_SECONDS );
		self::reap( self::get_threshold_minutes() );
	}

	/**
	 * Fail every orphaned open attempt older than $minutes. Pass 0 to consider
	 * all open attempts regardless of age (the manual "Clear stuck runs"
	 * action) — the live-action guard still protects in-flight chains.
	 *
	 * @return array{considered:int, deleted:int} considered = open attempts
	 *         examined; deleted = attempts actually reaped (failed).
	 */
	public static function reap( $minutes ) {
		$candidates = Supcomp_Extract_Runs_Repo::open_attempts_older_than( (int) $minutes );
		$considered = count( $candidates );
		if ( $considered === 0 ) {
			return array( 'considered' => 0, 'deleted' => 0 );
		}

		// If Action Scheduler isn't queryable we can't tell live from dead, so
		// stay conservative and reap nothing rather than risk failing a live run.
		$live = Supcomp_Extractor_Worker::live_attempt_ids();
		if ( $live === null ) {
			return array( 'considered' => $considered, 'deleted' => 0 );
		}

		$reaped  = 0;
		$message = __( 'Reaped by the stale-run safety net: no progress and no queued action — the worker died mid-run (host timeout / out-of-memory). Re-run the site.', 'supplement-compare' );

		foreach ( $candidates as $att ) {
			if ( isset( $live[ (int) $att->id ] ) ) {
				continue; // chain still advancing — leave it alone
			}

			Supcomp_Extract_Runs_Repo::set_failed( (int) $att->id, $message );
			Supcomp_Extract_Sites_Repo::record_run_result(
				(int) $att->site_id,
				'failed',
				(int) $att->offer_count,
				$message
			);
			// Close the orphaned import_run (if one was opened) and drop the
			// generic-handler URL transient so nothing dangles behind the row.
			Supcomp_Import_Runs_Repo::fail_open_for_export_run( (string) $att->run_id, $message );
			delete_transient( Supcomp_Extractor_Worker::generic_url_transient_key( (int) $att->id ) );
			++$reaped;
		}

		return array( 'considered' => $considered, 'deleted' => $reaped );
	}
}
