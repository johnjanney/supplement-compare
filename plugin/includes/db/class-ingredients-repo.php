<?php
/**
 * Repository for `canonical_ingredients`.
 *
 * Sanitization runs at the repository boundary — callers pass raw form values
 * keyed by column name and the repo validates and casts. Returns plain stdClass
 * rows from $wpdb->get_row / get_results, or WP_Error on validation failure.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Ingredients_Repo {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'supcomp_canonical_ingredients';
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
	 * Lightweight cache for the matcher. Called once per import row, hits
	 * the DB once per request. Returns [id, name, aliases[]] arrays.
	 */
	public static function all_for_matching() {
		static $cache = null;
		if ( $cache !== null ) {
			return $cache;
		}
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			"SELECT id, name, aliases_json FROM {$table} WHERE status <> 'retired'"
		);
		$cache = array();
		foreach ( (array) $rows as $r ) {
			$cache[] = array(
				'id'      => (int) $r->id,
				'name'    => (string) $r->name,
				'aliases' => self::decode_aliases( $r->aliases_json ),
			);
		}
		return $cache;
	}

	/**
	 * Active and draft ingredients, sorted for use in a <select> dropdown.
	 * Retired ingredients are omitted.
	 */
	public static function active_for_select() {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results( "SELECT id, slug, name FROM {$table} WHERE status <> 'retired' ORDER BY name ASC" );
	}

	/**
	 * List ingredients with optional category/status/search filters.
	 *
	 * @param array $args category, status, search, orderby, order, limit, offset
	 */
	public static function query( $args = array() ) {
		global $wpdb;
		$defaults = array(
			'category' => '',
			'status'   => '',
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

		if ( $args['category'] !== '' ) {
			$where[]  = 'category = %s';
			$params[] = $args['category'];
		}
		if ( $args['status'] !== '' ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( $args['search'] !== '' ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(name LIKE %s OR slug LIKE %s OR aliases_json LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$allowed_orderby = array( 'name', 'slug', 'category', 'status', 'updated_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'name';
		$order           = strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC';

		$where_sql = empty( $where ) ? '1=1' : implode( ' AND ', $where );
		$sql       = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params[]  = (int) $args['limit'];
		$params[]  = (int) $args['offset'];

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Insert or update by slug. Returns array{id:int, created:bool} or WP_Error.
	 * Pass raw form values keyed by column name; sanitize() runs here.
	 */
	public static function upsert( $data ) {
		global $wpdb;
		$clean = self::sanitize( $data );

		if ( empty( $clean['slug'] ) ) {
			return new WP_Error( 'supcomp_missing_slug', __( 'Slug is required.', 'supplement-compare' ) );
		}
		if ( empty( $clean['name'] ) ) {
			return new WP_Error( 'supcomp_missing_name', __( 'Name is required.', 'supplement-compare' ) );
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
		if ( ! in_array( $status, Supcomp_Installer::INGREDIENT_STATUSES, true ) ) {
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

	/**
	 * Sanitize raw form/CSV data into a row ready for $wpdb->insert/update.
	 * Keys not present in $data are not present in the returned array, so
	 * partial updates leave un-touched columns alone.
	 */
	public static function sanitize( $data ) {
		$clean = array();

		if ( isset( $data['slug'] ) ) {
			$clean['slug'] = sanitize_title( $data['slug'] );
		}
		if ( isset( $data['name'] ) ) {
			$clean['name'] = sanitize_text_field( $data['name'] );
		}
		if ( isset( $data['aliases'] ) || isset( $data['aliases_json'] ) ) {
			$raw = isset( $data['aliases'] ) ? $data['aliases'] : $data['aliases_json'];
			if ( is_string( $raw ) && $raw !== '' ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$aliases = $decoded;
				} else {
					$aliases = preg_split( '/\s*[|;,]\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY );
				}
			} elseif ( is_array( $raw ) ) {
				$aliases = $raw;
			} else {
				$aliases = array();
			}
			$aliases               = array_map( 'sanitize_text_field', (array) $aliases );
			$aliases               = array_values( array_filter( $aliases, 'strlen' ) );
			$clean['aliases_json'] = wp_json_encode( $aliases );
		}
		if ( isset( $data['category'] ) ) {
			$cat               = sanitize_key( $data['category'] );
			$clean['category'] = in_array( $cat, Supcomp_Installer::INGREDIENT_CATEGORIES, true ) ? $cat : 'other';
		}
		if ( isset( $data['default_unit'] ) ) {
			$unit                  = trim( (string) $data['default_unit'] );
			$clean['default_unit'] = in_array( $unit, Supcomp_Installer::INGREDIENT_UNITS, true ) ? $unit : 'mg';
		}
		if ( array_key_exists( 'elemental_percentage', $data ) ) {
			$val                          = trim( (string) $data['elemental_percentage'] );
			$clean['elemental_percentage'] = $val === '' ? null : (float) $val;
		}
		if ( array_key_exists( 'standardization_compound', $data ) ) {
			$val                              = trim( (string) $data['standardization_compound'] );
			$clean['standardization_compound'] = $val === '' ? null : sanitize_text_field( $val );
		}
		if ( array_key_exists( 'standardization_default_pct', $data ) ) {
			$val                                  = trim( (string) $data['standardization_default_pct'] );
			$clean['standardization_default_pct'] = $val === '' ? null : (float) $val;
		}
		if ( isset( $data['status'] ) ) {
			$s               = sanitize_key( $data['status'] );
			$clean['status'] = in_array( $s, Supcomp_Installer::INGREDIENT_STATUSES, true ) ? $s : 'draft';
		}
		if ( isset( $data['notes'] ) ) {
			$clean['notes'] = sanitize_textarea_field( $data['notes'] );
		}

		return $clean;
	}

	public static function decode_aliases( $json ) {
		if ( empty( $json ) ) {
			return array();
		}
		$decoded = json_decode( (string) $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
