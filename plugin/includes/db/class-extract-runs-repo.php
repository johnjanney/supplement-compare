<?php
/**
 * Repository for `extract_runs` — one row per (run_id, site) attempt.
 *
 * Lifecycle of an attempt row:
 *
 *   create_attempt(run_id, site_id, triggered_by) → status='pending'
 *   set_running(id)                                → status='running', started_at=NOW
 *   set_complete(id, platform_used, offer_count)   → status='complete', finished_at=NOW
 *     or
 *   set_failed(id, error_text)                     → status='failed',   finished_at=NOW
 *     or
 *   set_canceled(id, reason)                       → status='canceled', finished_at=NOW
 *
 * `run_id` is a short hex string (matches Python's `run_{uuid4.hex[:12]}`)
 * that groups all per-site attempts initiated by one orchestrator call.
 * `site_id` is NULL only for orchestrator-level rows (not used in Phase A).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Extract_Runs_Repo {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'supcomp_extract_runs';
	}

	/**
	 * Generate a fresh run_id. Format matches Python's `run_` prefix +
	 * 12-char hex so existing eyeballed log greps still work across both
	 * pipelines.
	 */
	public static function generate_run_id() {
		return 'run_' . bin2hex( random_bytes( 6 ) );
	}

	public static function create_attempt( $run_id, $site_id, $triggered_by = 'manual' ) {
		global $wpdb;
		$trigger = in_array( $triggered_by, Supcomp_Installer::EXTRACT_RUN_TRIGGERS, true ) ? $triggered_by : 'manual';
		$wpdb->insert(
			self::table(),
			array(
				'run_id'       => self::trim_to( (string) $run_id, 64 ),
				'site_id'      => $site_id === null ? null : absint( $site_id ),
				'status'       => 'pending',
				'triggered_by' => $trigger,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function set_running( $id ) {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			array(
				'status'     => 'running',
				'started_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
	}

	public static function set_complete( $id, $platform_used, $offer_count ) {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			array(
				'status'        => 'complete',
				'platform_used' => $platform_used === null ? null : self::trim_to( (string) $platform_used, 32 ),
				'offer_count'   => (int) $offer_count,
				'finished_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
	}

	public static function set_failed( $id, $error_text ) {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			array(
				'status'      => 'failed',
				'error_text'  => (string) $error_text,
				'finished_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
	}

	public static function set_canceled( $id, $reason = '' ) {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			array(
				'status'      => 'canceled',
				'error_text'  => (string) $reason,
				'finished_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) );
	}

	public static function by_run( $run_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %s ORDER BY id ASC", (string) $run_id )
		);
	}

	public static function recent( $limit = 50 ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", (int) $limit )
		);
	}

	private static function trim_to( $val, $max ) {
		return strlen( $val ) > $max ? substr( $val, 0, $max ) : $val;
	}
}
