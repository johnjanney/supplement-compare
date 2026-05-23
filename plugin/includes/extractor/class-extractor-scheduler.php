<?php
/**
 * WP-Cron-backed scheduler for the in-plugin extractor.
 *
 * One option (`supcomp_extract_schedule`) holds the operator's chosen
 * recurrence — `off`, `daily`, `twicedaily`, or `weekly`. When set to
 * anything other than `off`, a WP-Cron event is registered that fires
 * Supcomp_Extractor::run() across all enabled sites.
 *
 * Reconciliation runs on every plugins_loaded — cheap if state is
 * consistent (option value matches registered cron schedule), idempotent
 * otherwise. This means activation, deactivation, or external option
 * edits all converge without manual intervention.
 *
 * Notes on WP-Cron reliability:
 *   - WP-Cron only fires when a visitor hits the site (or when an
 *     external pinger hits /wp-cron.php). Low-traffic sites should
 *     install an external heartbeat — INSTRUCTIONS.md §2 covers this.
 *   - The schedule is shared across all enabled sites; there's no
 *     per-site override. Operators who want different cadences per
 *     site can disable the global schedule and trigger manually.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Extractor_Scheduler {

	const OPTION_KEY = 'supcomp_extract_schedule';
	const HOOK       = 'supcomp_scheduled_extract';

	public static function valid_frequencies() {
		return array( 'off', 'daily', 'twicedaily', 'weekly' );
	}

	public static function register_hooks() {
		add_action( self::HOOK, array( __CLASS__, 'fire' ) );
		// Reconcile on every page load so option changes from external
		// tools (WP-CLI, REST, direct DB) re-register the cron event.
		add_action( 'plugins_loaded', array( __CLASS__, 'reconcile_schedule' ), 20 );
	}

	public static function get_schedule() {
		$val = (string) get_option( self::OPTION_KEY, 'off' );
		return in_array( $val, self::valid_frequencies(), true ) ? $val : 'off';
	}

	public static function set_schedule( $freq ) {
		$freq = in_array( $freq, self::valid_frequencies(), true ) ? $freq : 'off';
		update_option( self::OPTION_KEY, $freq );
		self::reconcile_schedule();
	}

	/**
	 * Make WP-Cron registration match the stored option:
	 *   - off          → no event registered.
	 *   - other freq   → exactly one event at the matching recurrence.
	 */
	public static function reconcile_schedule() {
		$freq      = self::get_schedule();
		$next      = wp_next_scheduled( self::HOOK );
		$registered_recurrence = self::registered_recurrence();

		if ( $freq === 'off' ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
			return;
		}

		if ( ! $next || $registered_recurrence !== $freq ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
			// First fire is at the next natural boundary (an hour out so the
			// operator can see the schedule register before it actually runs).
			wp_schedule_event( time() + HOUR_IN_SECONDS, $freq, self::HOOK );
		}
	}

	private static function registered_recurrence() {
		$cron = _get_cron_array();
		if ( ! is_array( $cron ) ) {
			return '';
		}
		foreach ( $cron as $timestamp => $hooks ) {
			if ( isset( $hooks[ self::HOOK ] ) && is_array( $hooks[ self::HOOK ] ) ) {
				foreach ( $hooks[ self::HOOK ] as $args ) {
					if ( isset( $args['schedule'] ) ) {
						return (string) $args['schedule'];
					}
				}
			}
		}
		return '';
	}

	/**
	 * WP-Cron callback. Fires Supcomp_Extractor::run() for all enabled
	 * sites; the orchestrator + worker handle the rest.
	 */
	public static function fire() {
		if ( ! class_exists( 'Supcomp_Extractor' ) ) {
			return;
		}
		Supcomp_Extractor::run( array(), 'schedule' );
	}

	public static function next_scheduled_at() {
		$ts = wp_next_scheduled( self::HOOK );
		return $ts ? (int) $ts : 0;
	}

	public static function schedule_label( $freq ) {
		$labels = array(
			'off'        => __( 'Off (manual triggers only)', 'supplement-compare' ),
			'daily'      => __( 'Daily', 'supplement-compare' ),
			'twicedaily' => __( 'Twice daily', 'supplement-compare' ),
			'weekly'     => __( 'Weekly', 'supplement-compare' ),
		);
		return $labels[ $freq ] ?? $freq;
	}
}
