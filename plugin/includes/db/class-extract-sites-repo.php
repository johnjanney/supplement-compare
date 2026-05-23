<?php
/**
 * Repository for `extract_sites` — the configured list of merchant sites
 * the in-plugin extractor will scrape. Adding a row here is the operator's
 * equivalent of putting a URL in the legacy `extractor/sites.txt`.
 *
 * Each row carries last-run telemetry (status, offer_count, error) so the
 * admin screen can render at-a-glance health for the operator without
 * having to join against the runs table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Extract_Sites_Repo {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'supcomp_extract_sites';
	}

	public static function list_all( $only_enabled = false ) {
		global $wpdb;
		$table = self::table();
		$where = $only_enabled ? 'WHERE enabled = 1' : '';
		return $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY label, slug" );
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) );
	}

	public static function get_by_slug( $slug ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", (string) $slug ) );
	}

	/**
	 * Insert a new site. Returns inserted id or 0 on failure (e.g. slug
	 * uniqueness collision).
	 */
	public static function insert( array $data ) {
		global $wpdb;
		$clean = self::sanitize( $data );
		if ( empty( $clean['slug'] ) || empty( $clean['site_url'] ) ) {
			return 0;
		}
		$clean['created_at'] = current_time( 'mysql', true );
		$clean['updated_at'] = $clean['created_at'];
		$ok = $wpdb->insert( self::table(), $clean );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function update( $id, array $data ) {
		global $wpdb;
		$clean = self::sanitize( $data );
		if ( empty( $clean ) ) {
			return false;
		}
		$clean['updated_at'] = current_time( 'mysql', true );
		$result = $wpdb->update( self::table(), $clean, array( 'id' => (int) $id ) );
		return $result !== false;
	}

	public static function delete( $id ) {
		global $wpdb;
		return false !== $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Update the last-run telemetry on a site row. Called by the extractor
	 * after each per-site run finishes. $error may be null on success.
	 */
	public static function record_run_result( $id, $status, $offer_count, $error = null ) {
		global $wpdb;
		$data = array(
			'last_run_at'      => current_time( 'mysql', true ),
			'last_run_status'  => (string) $status,
			'last_offer_count' => (int) $offer_count,
			'last_error'       => $error === null ? null : (string) $error,
			'updated_at'       => current_time( 'mysql', true ),
		);
		return false !== $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	private static function sanitize( array $data ) {
		$clean = array();
		if ( array_key_exists( 'slug', $data ) ) {
			$slug = sanitize_title( (string) $data['slug'] );
			if ( $slug !== '' ) {
				$clean['slug'] = self::trim_to( $slug, 64 );
			}
		}
		if ( array_key_exists( 'label', $data ) ) {
			$clean['label'] = self::trim_to( sanitize_text_field( (string) $data['label'] ), 255 );
		}
		if ( array_key_exists( 'site_url', $data ) ) {
			$clean['site_url'] = self::trim_to( esc_url_raw( (string) $data['site_url'] ), 512 );
		}
		if ( array_key_exists( 'platform_hint', $data ) ) {
			$hint = sanitize_key( (string) $data['platform_hint'] );
			$clean['platform_hint'] = in_array( $hint, Supcomp_Installer::EXTRACT_SITE_PLATFORM_HINTS, true ) ? $hint : 'auto';
		}
		if ( array_key_exists( 'merchant_id', $data ) ) {
			$mid = absint( $data['merchant_id'] );
			$clean['merchant_id'] = $mid > 0 ? $mid : null;
		}
		if ( array_key_exists( 'enabled', $data ) ) {
			$clean['enabled'] = self::truthy( $data['enabled'] ) ? 1 : 0;
		}
		return $clean;
	}

	private static function truthy( $val ) {
		if ( is_bool( $val ) ) {
			return $val;
		}
		$v = strtolower( trim( (string) $val ) );
		return in_array( $v, array( '1', 'true', 'yes', 'y', 'on' ), true );
	}

	private static function trim_to( $val, $max ) {
		return strlen( $val ) > $max ? substr( $val, 0, $max ) : $val;
	}
}
