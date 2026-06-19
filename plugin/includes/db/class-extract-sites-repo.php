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
		// Compose the per-site settings bag (schema 14). The legacy columns are
		// dual-written by sanitize() above; the bag mirrors them and additionally
		// carries handler-specific settings (json_handler) that have no column.
		$clean['settings_json'] = self::compose_settings_json( array(), $clean, $data );
		$clean['created_at'] = current_time( 'mysql', true );
		$clean['updated_at'] = $clean['created_at'];
		$ok = $wpdb->insert( self::table(), $clean );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function update( $id, array $data ) {
		global $wpdb;
		$clean = self::sanitize( $data );

		// Merge incoming settings over the existing bag so a partial update
		// (e.g. a legacy-column change) can't wipe json_handler, and vice versa.
		$existing = self::get( $id );
		if ( self::touches_settings( $data ) ) {
			$clean['settings_json'] = self::compose_settings_json(
				$existing ? self::settings( $existing ) : array(),
				$clean,
				$data
			);
		}

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
		if ( array_key_exists( 'request_cookies', $data ) ) {
			// Stored verbatim as a Cookie header value (e.g. an age-gate bypass
			// cookie). Collapse CR/LF to spaces as a header-injection guard,
			// strip tags, trim, and cap length.
			$raw = preg_replace( '/[\r\n]+/', ' ', (string) $data['request_cookies'] );
			$clean['request_cookies'] = self::trim_to( trim( wp_strip_all_tags( $raw ) ), 2048 );
		}
		if ( array_key_exists( 'crawl_all_sitemap_urls', $data ) ) {
			$clean['crawl_all_sitemap_urls'] = self::truthy( $data['crawl_all_sitemap_urls'] ) ? 1 : 0;
		}
		if ( array_key_exists( 'enabled', $data ) ) {
			$clean['enabled'] = self::truthy( $data['enabled'] ) ? 1 : 0;
		}
		return $clean;
	}

	// === Per-site settings bag (schema 14) ===
	//
	// `settings_json` is the home for per-site handler exceptions. The three
	// legacy columns (platform_hint, request_cookies, crawl_all_sitemap_urls)
	// are dual-written for one release as a back-compat mirror / rollback path
	// and are read here with the bag taking precedence. New settings — starting
	// with `json_handler` — live only in the bag. A future release drops the
	// legacy columns and the dual-write once every reader goes through this
	// accessor.

	/**
	 * Normalized read of a site row's settings. Single source of truth for
	 * consumers (the worker reads through this). Bag value wins; falls back to
	 * the legacy column when the bag lacks a key (rows not yet backfilled).
	 *
	 * @param object $row An extract_sites row.
	 * @return array{platform_hint:string,request_cookies:string,crawl_all_sitemap_urls:bool,json_handler:array}
	 */
	public static function settings( $row ) {
		$bag = self::decode_settings( $row );

		$col_hint   = isset( $row->platform_hint ) ? (string) $row->platform_hint : 'auto';
		$col_cookie = isset( $row->request_cookies ) ? (string) $row->request_cookies : '';
		$col_crawl  = isset( $row->crawl_all_sitemap_urls ) ? (int) $row->crawl_all_sitemap_urls : 0;

		return array(
			'platform_hint'          => array_key_exists( 'platform_hint', $bag ) ? (string) $bag['platform_hint'] : $col_hint,
			'request_cookies'        => array_key_exists( 'request_cookies', $bag ) ? (string) $bag['request_cookies'] : $col_cookie,
			'crawl_all_sitemap_urls' => array_key_exists( 'crawl_all_sitemap_urls', $bag ) ? (bool) $bag['crawl_all_sitemap_urls'] : (bool) $col_crawl,
			'json_handler'           => ( isset( $bag['json_handler'] ) && is_array( $bag['json_handler'] ) ) ? $bag['json_handler'] : array(),
			'url_rewrite'            => ( isset( $bag['url_rewrite'] ) && is_array( $bag['url_rewrite'] ) ) ? $bag['url_rewrite'] : array(),
		);
	}

	/**
	 * Decode the raw settings_json blob to an array, or [] if absent/invalid.
	 */
	public static function decode_settings( $row ) {
		if ( ! is_object( $row ) || empty( $row->settings_json ) ) {
			return array();
		}
		$decoded = json_decode( (string) $row->settings_json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Whether an incoming $data array references any settings-bag field, so
	 * update() knows to recompose the blob even when no legacy column changed
	 * (e.g. a json_config-only edit).
	 */
	private static function touches_settings( array $data ) {
		foreach ( array( 'platform_hint', 'request_cookies', 'crawl_all_sitemap_urls', 'json_config', 'url_rewrite_config' ) as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build the settings_json string by merging incoming values over the
	 * existing (normalized) bag. Legacy fields are taken from the already
	 * sanitized $clean so the bag and the columns stay in lockstep; the raw
	 * json_config string is validated here.
	 */
	private static function compose_settings_json( array $existing, array $clean, array $data ) {
		$bag = array(
			'platform_hint'          => isset( $existing['platform_hint'] ) ? (string) $existing['platform_hint'] : 'auto',
			'request_cookies'        => isset( $existing['request_cookies'] ) ? (string) $existing['request_cookies'] : '',
			'crawl_all_sitemap_urls' => (bool) ( $existing['crawl_all_sitemap_urls'] ?? false ),
			'json_handler'           => ( isset( $existing['json_handler'] ) && is_array( $existing['json_handler'] ) ) ? $existing['json_handler'] : array(),
			'url_rewrite'            => ( isset( $existing['url_rewrite'] ) && is_array( $existing['url_rewrite'] ) ) ? $existing['url_rewrite'] : array(),
		);

		if ( array_key_exists( 'platform_hint', $clean ) ) {
			$bag['platform_hint'] = (string) $clean['platform_hint'];
		}
		if ( array_key_exists( 'request_cookies', $clean ) ) {
			$bag['request_cookies'] = (string) $clean['request_cookies'];
		}
		if ( array_key_exists( 'crawl_all_sitemap_urls', $clean ) ) {
			$bag['crawl_all_sitemap_urls'] = (bool) $clean['crawl_all_sitemap_urls'];
		}
		if ( array_key_exists( 'json_config', $data ) ) {
			$bag['json_handler'] = self::sanitize_json_handler( (string) $data['json_config'] );
		}
		if ( array_key_exists( 'url_rewrite_config', $data ) ) {
			$bag['url_rewrite'] = self::sanitize_url_rewrite( (string) $data['url_rewrite_config'] );
		}

		$json = wp_json_encode( $bag, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * Validate & normalize an operator-supplied JSON-handler config string into
	 * a structurally safe array. Returns [] for empty/malformed input (the
	 * handler then fails the run with a clear "not configured" message, and the
	 * admin "Test mapping" button surfaces the problem before that). Unknown
	 * keys are dropped; only whitelisted shapes survive.
	 */
	public static function sanitize_json_handler( $raw ) {
		$raw = trim( (string) $raw );
		if ( $raw === '' ) {
			return array();
		}
		$cfg = json_decode( $raw, true );
		if ( ! is_array( $cfg ) ) {
			return array();
		}

		$out = array();

		// list_url — must be a safe http(s) URL.
		if ( ! empty( $cfg['list_url'] ) ) {
			$url = esc_url_raw( (string) $cfg['list_url'] );
			if ( $url !== '' && ( ! class_exists( 'Supcomp_Extractor_Http' ) || Supcomp_Extractor_Http::is_safe_url( $url ) ) ) {
				$out['list_url'] = $url;
			}
		}

		// pagination — whitelist of modes the handler implements.
		$mode = isset( $cfg['pagination']['mode'] ) ? sanitize_key( (string) $cfg['pagination']['mode'] ) : 'none';
		if ( ! in_array( $mode, array( 'none', 'page' ), true ) ) {
			$mode = 'none';
		}
		$pagination = array( 'mode' => $mode );
		if ( $mode === 'page' ) {
			$pagination['param'] = isset( $cfg['pagination']['param'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $cfg['pagination']['param'] ) : 'page';
			if ( $pagination['param'] === '' ) {
				$pagination['param'] = 'page';
			}
			$size = isset( $cfg['pagination']['size'] ) ? (int) $cfg['pagination']['size'] : 100;
			$pagination['size']  = max( 1, min( 1000, $size ) );
			$start = isset( $cfg['pagination']['start'] ) ? (int) $cfg['pagination']['start'] : 1;
			$pagination['start'] = ( $start === 0 ) ? 0 : 1;
		}
		$out['pagination'] = $pagination;

		// path selectors.
		$out['products_path'] = isset( $cfg['products_path'] ) ? self::clean_path( $cfg['products_path'] ) : '';
		if ( isset( $cfg['variants_path'] ) ) {
			$out['variants_path'] = self::clean_path( $cfg['variants_path'] );
		}
		if ( ! empty( $cfg['store_name'] ) ) {
			$out['store_name'] = self::trim_to( sanitize_text_field( (string) $cfg['store_name'] ), 255 );
		}
		if ( ! empty( $cfg['currency_default'] ) ) {
			$cur = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $cfg['currency_default'] ) );
			if ( strlen( $cur ) === 3 ) {
				$out['currency_default'] = $cur;
			}
		}

		// field map: Offer field => string path | { from, transform }.
		$allowed_fields = ( class_exists( 'Supcomp_Extractor_Offer' ) )
			? Supcomp_Extractor_Offer::fieldnames()
			: array();
		$transforms = ( class_exists( 'Supcomp_Extractor_Json' ) )
			? Supcomp_Extractor_Json::transforms()
			: array( 'as_string', 'strip_html', 'bool_to_status', 'gt_zero_to_status', 'truthy_to_status', 'woo_stock_to_status' );

		$out['fields'] = array();
		if ( isset( $cfg['fields'] ) && is_array( $cfg['fields'] ) ) {
			foreach ( $cfg['fields'] as $field => $spec ) {
				$field = (string) $field;
				if ( ! empty( $allowed_fields ) && ! in_array( $field, $allowed_fields, true ) ) {
					continue; // ignore unknown Offer fields
				}
				if ( is_string( $spec ) ) {
					$out['fields'][ $field ] = self::clean_path( $spec );
				} elseif ( is_array( $spec ) && isset( $spec['template'] ) ) {
					$tpl = self::clean_url_template( $spec['template'] );
					if ( $tpl !== '' ) {
						$out['fields'][ $field ] = array( 'template' => $tpl );
					}
				} elseif ( is_array( $spec ) && isset( $spec['from'] ) ) {
					$entry = array( 'from' => self::clean_path_list( $spec['from'] ) );
					if ( isset( $spec['transform'] ) && in_array( (string) $spec['transform'], $transforms, true ) ) {
						$entry['transform'] = (string) $spec['transform'];
					}
					$out['fields'][ $field ] = $entry;
				} elseif ( is_array( $spec ) ) {
					// Bare fallback list, e.g. ["sku", "slug"] — first non-empty wins.
					$list = self::clean_path_list( $spec );
					if ( ! empty( $list ) ) {
						$out['fields'][ $field ] = $list;
					}
				}
			}
		}

		// raw_attributes: list of paths copied into raw_attributes_json.
		if ( isset( $cfg['raw_attributes'] ) && is_array( $cfg['raw_attributes'] ) ) {
			$out['raw_attributes'] = array();
			foreach ( $cfg['raw_attributes'] as $p ) {
				$p = self::clean_path( $p );
				if ( $p !== '' ) {
					$out['raw_attributes'][] = $p;
				}
			}
		}

		return $out;
	}

	/**
	 * Validate & normalize a per-site URL-rewrite rule. Handler-agnostic: the
	 * worker applies it to source_product_url / source_variant_url on every row
	 * after the handler runs. Built for headless storefronts whose API/feed
	 * returns backend/staging product URLs (e.g. a Next.js frontend over a Woo
	 * backend that leaks `wp.example.com` or a `*.wpcomstaging.com` host).
	 *
	 * Shape: { "from_host": "...", "to_host": "...",
	 *          "from_path_prefix": "/product/", "to_path_prefix": "/products/",
	 *          "strip_trailing_slash": true }
	 *
	 * `from_host` is the trigger — a URL is only rewritten when its host matches,
	 * so correct URLs are never touched. Returns [] (disabled) without it.
	 */
	public static function sanitize_url_rewrite( $raw ) {
		$raw = trim( (string) $raw );
		if ( $raw === '' ) {
			return array();
		}
		$cfg = json_decode( $raw, true );
		if ( ! is_array( $cfg ) ) {
			return array();
		}

		$from_host = isset( $cfg['from_host'] ) ? self::clean_host( $cfg['from_host'] ) : '';
		if ( $from_host === '' ) {
			return array();
		}
		$out = array( 'from_host' => $from_host );

		$to_host = isset( $cfg['to_host'] ) ? self::clean_host( $cfg['to_host'] ) : '';
		if ( $to_host !== '' ) {
			$out['to_host'] = $to_host;
		}
		if ( isset( $cfg['from_path_prefix'] ) ) {
			$out['from_path_prefix'] = self::clean_url_path( $cfg['from_path_prefix'] );
		}
		if ( isset( $cfg['to_path_prefix'] ) ) {
			$out['to_path_prefix'] = self::clean_url_path( $cfg['to_path_prefix'] );
		}
		if ( ! empty( $cfg['strip_trailing_slash'] ) ) {
			$out['strip_trailing_slash'] = true;
		}
		return $out;
	}

	/** Strip a value down to a bare hostname (no scheme, path, or port). */
	private static function clean_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		// Tolerate a pasted full URL by extracting the host component.
		if ( strpos( $host, '//' ) !== false ) {
			$parsed = wp_parse_url( $host, PHP_URL_HOST );
			if ( $parsed ) {
				$host = (string) $parsed;
			}
		}
		$host = preg_replace( '#[/:].*$#', '', $host );
		return (string) preg_replace( '/[^a-z0-9.\-]/', '', (string) $host );
	}

	/** Sanitize a URL path prefix like `/product/`. */
	private static function clean_url_path( $path ) {
		$path = (string) preg_replace( '/[^A-Za-z0-9\/_\-]/', '', (string) $path );
		if ( $path !== '' && $path[0] !== '/' ) {
			$path = '/' . $path;
		}
		return $path;
	}

	/**
	 * Sanitize a URL template like "https://store.com/catalog/{sku}". Must be an
	 * http(s) URL and free of whitespace/quote/angle chars; `{placeholder}`
	 * braces are preserved. Returns '' if it doesn't qualify.
	 */
	private static function clean_url_template( $tpl ) {
		$tpl = trim( wp_strip_all_tags( (string) $tpl ) );
		if ( ! preg_match( '#^https?://#i', $tpl ) ) {
			return '';
		}
		if ( preg_match( '/[\s<>"\']/', $tpl ) ) {
			return '';
		}
		return self::trim_to( $tpl, 512 );
	}

	/**
	 * Sanitize a path or a list of fallback paths. Returns a cleaned string for
	 * a scalar input, or a list of cleaned non-empty paths for an array input.
	 */
	private static function clean_path_list( $paths ) {
		if ( is_array( $paths ) ) {
			$out = array();
			foreach ( $paths as $p ) {
				$cp = self::clean_path( $p );
				if ( $cp !== '' ) {
					$out[] = $cp;
				}
			}
			return $out;
		}
		return self::clean_path( $paths );
	}

	/**
	 * Sanitize a dot-path selector. Allows word chars, dots, the `@variant.`
	 * scope prefix, and brackets — enough for nested-object/array access while
	 * rejecting anything weird.
	 */
	private static function clean_path( $path ) {
		$path = (string) $path;
		return (string) preg_replace( '/[^A-Za-z0-9_.@\[\]\-]/', '', $path );
	}

	/**
	 * One-time seed of settings_json for rows created before schema 14. Called
	 * from the installer after dbDelta. Idempotent — skips rows that already
	 * have a bag.
	 */
	public static function backfill_settings_json() {
		global $wpdb;
		$table = self::table();
		// The column may not exist yet on the very first call within the same
		// request that adds it; guard so a missing column can't fatal.
		$has_col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'settings_json' ) );
		if ( ! $has_col ) {
			return 0;
		}
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE settings_json IS NULL OR settings_json = ''" );
		if ( empty( $rows ) ) {
			return 0;
		}
		$n = 0;
		foreach ( $rows as $row ) {
			$bag = array(
				'platform_hint'          => isset( $row->platform_hint ) ? (string) $row->platform_hint : 'auto',
				'request_cookies'        => isset( $row->request_cookies ) ? (string) $row->request_cookies : '',
				'crawl_all_sitemap_urls' => isset( $row->crawl_all_sitemap_urls ) ? (bool) (int) $row->crawl_all_sitemap_urls : false,
				'json_handler'           => array(),
				'url_rewrite'            => array(),
			);
			$json = wp_json_encode( $bag, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$wpdb->update( $table, array( 'settings_json' => $json ), array( 'id' => (int) $row->id ) );
			$n++;
		}
		return $n;
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
