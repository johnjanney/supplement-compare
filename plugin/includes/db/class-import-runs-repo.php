<?php
/**
 * Repository for `import_runs` (PROJECTBRIEF.md §3.6).
 *
 * Lifecycle of a run row:
 *
 *   create_run(filename) → status='validating'
 *   (validator runs)
 *   set_status('importing')
 *   (importer processes rows)
 *   update_counts(...) and set_status('complete'|'failed'|'rolled_back')
 *
 * Dry-runs intentionally do not create a row.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Import_Runs_Repo {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'supcomp_import_runs';
	}

	public static function create_run( $filename, $row_count = 0 ) {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'csv_filename' => self::trim_to( (string) $filename, 255 ),
				'row_count'    => (int) $row_count,
				'imported_at'  => current_time( 'mysql', true ),
				'status'       => 'validating',
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function set_export_metadata( $id, $export_run_id, $exported_at ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'export_run_id' => self::trim_to( (string) $export_run_id, 64 ),
				'exported_at'   => self::mysql_datetime( $exported_at ),
			),
			array( 'id' => (int) $id )
		);
	}

	public static function set_status( $id, $status, $error_log = null ) {
		global $wpdb;
		$data = array( 'status' => $status );
		if ( $error_log !== null ) {
			$data['error_log'] = (string) $error_log;
		}
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	public static function update_counts( $id, $inserted, $updated, $stale, $errored ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'rows_inserted'     => (int) $inserted,
				'rows_updated'      => (int) $updated,
				'rows_marked_stale' => (int) $stale,
				'rows_errored'      => (int) $errored,
			),
			array( 'id' => (int) $id )
		);
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) );
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

	/**
	 * Accepts an ISO 8601 string (or anything strtotime can parse) and
	 * returns 'Y-m-d H:i:s' UTC. Returns null on parse failure.
	 */
	private static function mysql_datetime( $value ) {
		if ( $value === null || $value === '' ) {
			return null;
		}
		$ts = strtotime( (string) $value );
		if ( $ts === false ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
