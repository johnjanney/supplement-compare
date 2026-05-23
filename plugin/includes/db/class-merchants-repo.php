<?php
/**
 * Repository for `merchants`.
 *
 * Sanitization runs at the repository boundary (slug, platform, status,
 * currency enums validated; text fields run through sanitize_text_field /
 * sanitize_textarea_field). Affiliate URL template is stored as-is — the
 * template engine validates separately.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Merchants_Repo {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'supcomp_merchants';
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
	 * Match CSV `site` column to a merchant row. Returns the first row whose
	 * site_url matches (case-insensitive, ignoring trailing slash). Schema
	 * allows duplicate site_urls; in practice the operator should not create
	 * them, but if they do this picks the oldest.
	 */
	public static function get_by_site_url( $site_url ) {
		global $wpdb;
		$normalized = self::normalize_site_url( $site_url );
		if ( $normalized === '' ) {
			return null;
		}
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE LOWER(REPLACE(site_url, %s, '')) = LOWER(REPLACE(%s, %s, ''))
				 ORDER BY id ASC
				 LIMIT 1",
				'/',
				$normalized,
				'/'
			)
		);
	}

	public static function active_for_select() {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results( "SELECT id, slug, name FROM {$table} WHERE status = 'active' ORDER BY name ASC" );
	}

	/**
	 * @param array $args status, platform, search, orderby, order, limit, offset
	 */
	public static function query( $args = array() ) {
		global $wpdb;
		$defaults = array(
			'status'   => '',
			'platform' => '',
			'search'   => '',
			'orderby'  => 'name',
			'order'    => 'ASC',
			'limit'    => 200,
			'offset'   => 0,
		);
		$args  = wp_parse_args( $args, $defaults );
		$table = self::table();

		$where  = array();
		$params = array();

		if ( $args['status'] !== '' ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( $args['platform'] !== '' ) {
			$where[]  = 'platform = %s';
			$params[] = $args['platform'];
		}
		if ( $args['search'] !== '' ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(name LIKE %s OR slug LIKE %s OR site_url LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$allowed_orderby = array( 'name', 'slug', 'platform', 'status', 'updated_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'name';
		$order           = strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC';

		$where_sql = empty( $where ) ? '1=1' : implode( ' AND ', $where );
		$sql       = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params[]  = (int) $args['limit'];
		$params[]  = (int) $args['offset'];

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	public static function upsert( $data ) {
		global $wpdb;
		$clean = self::sanitize( $data );

		if ( empty( $clean['slug'] ) ) {
			return new WP_Error( 'supcomp_missing_slug', __( 'Slug is required.', 'supplement-compare' ) );
		}
		if ( empty( $clean['name'] ) ) {
			return new WP_Error( 'supcomp_missing_name', __( 'Name is required.', 'supplement-compare' ) );
		}
		if ( empty( $clean['site_url'] ) ) {
			return new WP_Error( 'supcomp_missing_site_url', __( 'Site URL is required.', 'supplement-compare' ) );
		}

		// Validate the affiliate URL template if one was provided.
		if ( ! empty( $clean['affiliate_url_template'] ) ) {
			$valid = Supcomp_Affiliate_URL_Template::validate( $clean['affiliate_url_template'] );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		$now                 = current_time( 'mysql', true );
		$clean['updated_at'] = $now;

		$existing = self::get_by_slug( $clean['slug'] );
		if ( $existing ) {
			$wpdb->update( self::table(), $clean, array( 'id' => (int) $existing->id ) );
			return array(
				'id'      => (int) $existing->id,
				'created' => false,
			);
		}

		$clean['created_at'] = $now;
		$wpdb->insert( self::table(), $clean );
		return array(
			'id'      => (int) $wpdb->insert_id,
			'created' => true,
		);
	}

	public static function set_status( $id, $status ) {
		global $wpdb;
		if ( ! in_array( $status, Supcomp_Installer::MERCHANT_STATUSES, true ) ) {
			return false;
		}
		return false !== $wpdb->update(
			self::table(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $id ) )
		);
	}

	public static function sanitize( $data ) {
		$clean = array();

		if ( isset( $data['slug'] ) ) {
			$clean['slug'] = sanitize_title( $data['slug'] );
		}
		if ( isset( $data['name'] ) ) {
			$clean['name'] = sanitize_text_field( $data['name'] );
		}
		if ( isset( $data['site_url'] ) ) {
			$clean['site_url'] = self::normalize_site_url( $data['site_url'] );
		}
		if ( isset( $data['platform'] ) ) {
			$p                 = sanitize_key( $data['platform'] );
			$clean['platform'] = in_array( $p, Supcomp_Installer::MERCHANT_PLATFORMS, true ) ? $p : 'generic';
		}
		if ( isset( $data['default_currency'] ) ) {
			$cur                       = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $data['default_currency'] ) );
			$cur                       = substr( $cur, 0, 3 );
			$clean['default_currency'] = $cur !== '' ? $cur : 'USD';
		}
		if ( array_key_exists( 'affiliate_url_template', $data ) ) {
			$val                              = trim( (string) $data['affiliate_url_template'] );
			$clean['affiliate_url_template'] = $val;
		}
		if ( array_key_exists( 'coupon_code', $data ) ) {
			$code                  = sanitize_text_field( (string) $data['coupon_code'] );
			$clean['coupon_code'] = substr( trim( $code ), 0, 64 );
		}
		if ( array_key_exists( 'coupon_details', $data ) ) {
			$details                  = sanitize_text_field( (string) $data['coupon_details'] );
			$clean['coupon_details'] = substr( trim( $details ), 0, 255 );
		}
		if ( isset( $data['status'] ) ) {
			$s              = sanitize_key( $data['status'] );
			$clean['status'] = in_array( $s, Supcomp_Installer::MERCHANT_STATUSES, true ) ? $s : 'active';
		}
		if ( isset( $data['notes'] ) ) {
			$clean['notes'] = sanitize_textarea_field( $data['notes'] );
		}

		return $clean;
	}

	/**
	 * Trim, strip whitespace, ensure scheme, strip trailing slash. Returns ''
	 * if the input isn't usable.
	 */
	public static function normalize_site_url( $url ) {
		$url = trim( (string) $url );
		if ( $url === '' ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		return rtrim( $url, '/' );
	}
}
