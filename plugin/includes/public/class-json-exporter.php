<?php
/**
 * Static JSON exporter (PROJECTBRIEF.md §8 Phase 8, payload shape §9).
 *
 * Generates a single public.json file under wp-content/uploads/supplement-compare/.
 * The Phase 9 frontend loads it into an in-memory comparison table. The
 * payload contains:
 *
 *   - canonical_products[]: one entry per canonical that has at least one
 *     active offer. lowest_cost_per_active_unit + offer_count are rolled up
 *     across that canonical's offers.
 *   - offers[]: one entry per active, canonical-matched offer within the
 *     hide-staleness threshold. Offers older than the warn threshold but
 *     within hide get is_stale=true.
 *
 * Deliberately omitted from the payload:
 *   - source_product_url, source_variant_url — buy_url points to /out/{id}
 *   - affiliate_url, affiliate_url_template — never exposed publicly
 *   - description, raw_attributes_json, operator_notes — internal only
 *   - any merchant.affiliate_url_template or notes
 *
 * Cache invalidation: mark_dirty() registers a shutdown hook that runs
 * generate() once per request after any state-changing operation, so
 * multiple writes within one request coalesce. An hourly cron is the
 * backup. Both paths call generate(); mark_dirty() is just a debouncer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_JSON_Exporter {

	const SCHEMA_VERSION    = '1.0';
	const FILENAME          = 'public.json';
	const SUBDIR            = 'supplement-compare';
	const CRON_HOOK         = 'supcomp_export_cron';
	const LAST_GENERATED_AT = 'supcomp_export_last_generated_at';

	public static function register_hooks() {
		add_action( 'supcomp_data_changed', array( __CLASS__, 'mark_dirty' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'generate' ) );
	}

	/**
	 * Mark the public JSON as needing regeneration before the request ends.
	 * Multiple calls within the same request coalesce — only one regeneration
	 * happens on shutdown.
	 */
	public static function mark_dirty() {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;
		add_action( 'shutdown', array( __CLASS__, 'generate' ), 99 );
	}

	/**
	 * Build the payload and write the file atomically. Returns the count of
	 * canonical products and offers written, or a WP_Error.
	 */
	public static function generate() {
		$hide_hours = (int) get_option( 'supcomp_staleness_hide_hours', 168 );
		$warn_hours = (int) get_option( 'supcomp_staleness_warn_hours', 48 );

		$hide_threshold = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $hide_hours ) * HOUR_IN_SECONDS ) );
		$warn_threshold = time() - ( max( 1, $warn_hours ) * HOUR_IN_SECONDS );

		$rows = Supcomp_Offers_Repo::for_export( $hide_threshold );

		$canonicals_by_id = array();
		$offers_payload   = array();

		foreach ( $rows as $row ) {
			$cp_id = (int) $row->canonical_product_id;
			if ( ! $cp_id ) {
				continue;
			}

			$offer_entry      = self::offer_entry( $row, $warn_threshold );
			$offers_payload[] = $offer_entry;

			if ( ! isset( $canonicals_by_id[ $cp_id ] ) ) {
				$canonicals_by_id[ $cp_id ] = self::canonical_entry( $row );
			}
			$cp_entry = &$canonicals_by_id[ $cp_id ];

			++$cp_entry['offer_count'];
			if ( $offer_entry['cost_per_active_unit'] !== null ) {
				if ( $cp_entry['lowest_cost_per_active_unit'] === null
					|| $offer_entry['cost_per_active_unit'] < $cp_entry['lowest_cost_per_active_unit'] ) {
					$cp_entry['lowest_cost_per_active_unit'] = $offer_entry['cost_per_active_unit'];
				}
			}
			unset( $cp_entry );
		}

		$payload = array(
			'schema_version'     => self::SCHEMA_VERSION,
			'generated_at'       => gmdate( 'c' ),
			'canonical_products' => array_values( $canonicals_by_id ),
			'offers'             => $offers_payload,
		);

		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( $json === false ) {
			return new WP_Error( 'supcomp_export_encode', __( 'Failed to encode payload as JSON.', 'supplement-compare' ) );
		}

		$path = self::output_path();
		if ( $path === '' ) {
			return new WP_Error( 'supcomp_export_path', __( 'Could not resolve uploads path.', 'supplement-compare' ) );
		}

		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Atomic write: .tmp then rename, so the public file is never
		// truncated mid-fetch.
		$tmp = $path . '.tmp';
		if ( file_put_contents( $tmp, $json ) === false ) {
			return new WP_Error( 'supcomp_export_write', __( 'Failed to write temp JSON file.', 'supplement-compare' ) );
		}
		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return new WP_Error( 'supcomp_export_rename', __( 'Failed to atomically replace JSON file.', 'supplement-compare' ) );
		}

		update_option( self::LAST_GENERATED_AT, current_time( 'mysql', true ) );

		return array(
			'canonical_products' => count( $canonicals_by_id ),
			'offers'             => count( $offers_payload ),
			'bytes'              => strlen( $json ),
		);
	}

	/**
	 * Per-canonical entry. Some fields are filled in on first sight; rollups
	 * (offer_count, lowest_cost_per_active_unit) get accumulated by the
	 * caller.
	 */
	private static function canonical_entry( $row ) {
		return array(
			'id'                          => (int) $row->canonical_product_id,
			'slug'                        => (string) $row->canonical_slug,
			'display_name'                => (string) $row->canonical_display_name,
			'ingredient'                  => array(
				'id'       => (int) $row->ingredient_id_join,
				'name'     => (string) $row->ingredient_name,
				'category' => (string) $row->ingredient_category,
			),
			'form'                        => (string) $row->canonical_form,
			'strength_per_serving'        => self::cast_number( $row->canonical_strength ),
			'strength_unit'               => (string) $row->ingredient_unit,
			'standardization_compound'    => self::nullable_str( $row->canonical_std_compound ),
			'standardization_percentage'  => self::cast_number( $row->canonical_std_pct ),
			'active_unit_label'           => (string) $row->ingredient_unit,
			'lowest_cost_per_active_unit' => null,
			'offer_count'                 => 0,
		);
	}

	private static function offer_entry( $row, $warn_threshold ) {
		$last_synced_ts = $row->last_synced_at ? strtotime( (string) $row->last_synced_at . ' UTC' ) : 0;
		$is_stale       = $last_synced_ts > 0 && $last_synced_ts < $warn_threshold;

		return array(
			'id'                          => (int) $row->id,
			'canonical_product_id'        => (int) $row->canonical_product_id,
			'merchant'                    => array(
				'id'   => (int) $row->merchant_id,
				'slug' => (string) $row->merchant_slug,
				'name' => (string) $row->merchant_name,
			),
			'brand'                       => (string) $row->brand,
			'product_title'               => (string) $row->product_title,
			'variant_title'               => $row->variant_title !== '' ? (string) $row->variant_title : null,
			'current_price'               => self::cast_number( $row->current_price ),
			'regular_price'               => self::cast_number( $row->regular_price ),
			'sale_price'                  => self::cast_number( $row->sale_price ),
			'on_sale'                     => (bool) (int) $row->on_sale,
			'currency'                    => (string) $row->currency,
			'servings_per_container'      => $row->servings_per_container !== null ? (int) $row->servings_per_container : null,
			'strength_per_serving'        => self::cast_number( $row->strength_per_serving ),
			'active_compound_per_serving' => self::cast_number( $row->active_compound_per_serving ),
			'active_compound_total'       => self::cast_number( $row->active_compound_total ),
			'cost_per_serving'            => self::cast_number( $row->cost_per_serving ),
			'cost_per_active_unit'        => self::cast_number( $row->cost_per_active_unit ),
			'stock_status'                => (string) $row->stock_status,
			'third_party_tested'          => (bool) (int) $row->third_party_tested,
			'coa_available'               => (bool) (int) $row->coa_available,
			'coa_url'                     => self::nullable_str( $row->coa_url ),
			'certifications'              => Supcomp_Offers_Repo::decode_certifications( $row->certifications_json ),
			'buy_url'                     => self::buy_url( (int) $row->id ),
			'last_synced_at'              => $row->last_synced_at ? gmdate( 'c', $last_synced_ts ) : null,
			'is_stale'                    => $is_stale,
		);
	}

	/**
	 * Casts a DECIMAL string (or null/empty) to a float for JSON. Returns
	 * null when the input is null or empty so the frontend doesn't see "0"
	 * for missing data.
	 */
	private static function cast_number( $value ) {
		if ( $value === null || $value === '' ) {
			return null;
		}
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$f = (float) $value;
		// JSON can't carry NaN/Infinity; convert to null.
		if ( ! is_finite( $f ) ) {
			return null;
		}
		return $f;
	}

	private static function nullable_str( $value ) {
		return ( $value === null || $value === '' ) ? null : (string) $value;
	}

	public static function output_path() {
		$upload = wp_upload_dir();
		if ( empty( $upload['basedir'] ) ) {
			return '';
		}
		return rtrim( (string) $upload['basedir'], DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . self::SUBDIR . DIRECTORY_SEPARATOR . self::FILENAME;
	}

	public static function output_url() {
		$upload = wp_upload_dir();
		if ( empty( $upload['baseurl'] ) ) {
			return '';
		}
		return rtrim( (string) $upload['baseurl'], '/' ) . '/' . self::SUBDIR . '/' . self::FILENAME;
	}

	public static function buy_url( $offer_id ) {
		return '/out/' . (int) $offer_id;
	}

	public static function last_generated_at() {
		return (string) get_option( self::LAST_GENERATED_AT, '' );
	}

	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public static function unschedule_cron() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
