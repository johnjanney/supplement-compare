<?php
/**
 * Generic JSON-API platform handler.
 *
 * For storefronts that render client-side (an empty HTML shell + a JavaScript
 * SPA) and serve their catalogue from a plain JSON API. Neither the Shopify,
 * Woo, nor generic JSON-LD handlers can see anything on these sites — there is
 * no server-rendered product markup to parse — but the underlying API is
 * usually a clean, unauthenticated JSON feed.
 *
 * Because every site's JSON shape differs and the endpoint can't be sniffed
 * (it lives inside the JS bundle), this handler is NOT auto-detected: the
 * operator pins platform_hint = 'json' and supplies a declarative mapping
 * (stored in extract_sites.settings_json -> json_handler) that says where the
 * product array is and which source fields populate which Offer columns.
 *
 * Config shape (validated in Supcomp_Extract_Sites_Repo::sanitize_json_handler):
 *   {
 *     "list_url":      "https://api.example.com/v1/products",
 *     "pagination":    { "mode": "none" }
 *                      | { "mode": "page", "param": "page", "size": 100, "start": 1 },
 *     "products_path": "products",            // dot-path to the array in the response
 *     "variants_path": "variants",            // dot-path within a product; omit -> product-level row
 *     "store_name":    "Example",             // optional literal
 *     "fields": {                             // Offer field <- source spec
 *        "product_title":     "name",
 *        "current_price":     "@variant.price",   // "@variant." resolves in variant scope
 *        "stock_status":      { "from": "in_stock", "transform": "bool_to_status" }
 *     },
 *     "raw_attributes": ["form", "@variant.dosage", "@variant.tier_prices"]
 *   }
 *
 * Page-oriented like the other handlers so Action Scheduler can chunk: each
 * tick fetches one page and returns row_dicts ready for the importer.
 *
 * Load-bearing guardrail (PROJECTBRIEF §"no exact stock"): stock_status is
 * coerced to the STOCK_STATUSES allow-list, so a raw inventory COUNT can never
 * land in that column — it must be derived to a status via bool_to_status /
 * gt_zero_to_status, or it falls through to 'unknown'.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Extractor_Json {

	// Mode 'page' cap — the worker also stops when a short page comes back.
	const MAX_PAGES = 50;

	/**
	 * Transforms the field map may invoke. Kept here as the single source of
	 * truth; the repo validator references this list.
	 *
	 * @return string[]
	 */
	public static function transforms() {
		return array( 'as_string', 'strip_html', 'bool_to_status', 'gt_zero_to_status', 'truthy_to_status', 'woo_stock_to_status' );
	}

	/**
	 * Fetch one page of the configured JSON list endpoint and convert to
	 * row_dicts.
	 *
	 * @param string $site        Site base URL (telemetry / fallback only).
	 * @param int    $page        1-indexed.
	 * @param string $run_id      export_run_id stamped on every row.
	 * @param string $exported_at ISO timestamp stamped on every row.
	 * @param string $store_name  Resolved once on page 1, reused across pages.
	 * @param string $currency    Fallback currency when a row doesn't map one.
	 * @param array  $config       The validated json_handler config.
	 * @return array{rows:array,batch_size:int,status:string,http_status:int}
	 */
	public static function fetch_page( $site, $page, $run_id, $exported_at, $store_name, $currency, array $config ) {
		$list_url = isset( $config['list_url'] ) ? (string) $config['list_url'] : '';
		if ( $list_url === '' ) {
			return array( 'rows' => array(), 'batch_size' => 0, 'status' => 'not_configured', 'http_status' => 0 );
		}

		$url      = self::page_url( $list_url, $config, (int) $page );
		$response = Supcomp_Extractor_Http::get( $url );

		if ( is_wp_error( $response ) ) {
			return array( 'rows' => array(), 'batch_size' => 0, 'status' => 'http_error', 'http_status' => 0 );
		}
		if ( (int) $response['status'] !== 200 ) {
			return array(
				'rows'        => array(),
				'batch_size'  => 0,
				'status'      => 'http_error',
				'http_status' => (int) $response['status'],
			);
		}

		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) ) {
			return array( 'rows' => array(), 'batch_size' => 0, 'status' => 'not_json', 'http_status' => 200 );
		}

		$products_path = isset( $config['products_path'] ) ? (string) $config['products_path'] : '';
		$products      = $products_path === '' ? $data : self::resolve_path( $data, $products_path );
		if ( ! is_array( $products ) ) {
			$products = array();
		}
		// A bare object (single product) — wrap so the loop below works.
		if ( ! empty( $products ) && self::is_assoc( $products ) ) {
			$products = array( $products );
		}

		if ( empty( $products ) ) {
			return array( 'rows' => array(), 'batch_size' => 0, 'status' => 'empty', 'http_status' => 200 );
		}

		$rows = array();
		foreach ( $products as $product ) {
			if ( ! is_array( $product ) ) {
				continue;
			}
			foreach ( self::product_to_offers( $product, $config, $site, $store_name, $run_id, $exported_at, $currency ) as $offer ) {
				$rows[] = $offer->to_row_dict();
			}
		}

		return array(
			'rows'        => $rows,
			'batch_size'  => count( $products ),
			'status'      => 'ok',
			'http_status' => 200,
		);
	}

	/**
	 * Best-effort store name for telemetry: the config literal, else the API
	 * host. Currency is left to the per-row field map (many feeds carry it per
	 * product), so this only returns a name.
	 */
	public static function store_name_for( $site, array $config ) {
		if ( ! empty( $config['store_name'] ) ) {
			return (string) $config['store_name'];
		}
		$host = wp_parse_url( ! empty( $config['list_url'] ) ? (string) $config['list_url'] : (string) $site, PHP_URL_HOST );
		return $host ? (string) $host : '';
	}

	/**
	 * Page size / max pages the worker uses to decide whether to paginate. In
	 * 'none' mode there is a single page, so page_size is effectively infinite
	 * and max_pages is 1 — any batch is "short" and the run finalizes.
	 *
	 * @return array{0:int,1:int} [page_size, max_pages]
	 */
	public static function pagination( array $config ) {
		$mode = isset( $config['pagination']['mode'] ) ? (string) $config['pagination']['mode'] : 'none';
		if ( $mode === 'page' ) {
			$size = isset( $config['pagination']['size'] ) ? (int) $config['pagination']['size'] : 100;
			return array( max( 1, $size ), self::MAX_PAGES );
		}
		return array( PHP_INT_MAX, 1 );
	}

	// === internals ===

	private static function page_url( $list_url, array $config, $page ) {
		$mode = isset( $config['pagination']['mode'] ) ? (string) $config['pagination']['mode'] : 'none';
		if ( $mode !== 'page' ) {
			return $list_url;
		}
		$param = isset( $config['pagination']['param'] ) ? (string) $config['pagination']['param'] : 'page';
		$start = isset( $config['pagination']['start'] ) ? (int) $config['pagination']['start'] : 1;
		// page is 1-indexed internally; offset by `start` so a 0-indexed API
		// gets ?page=0 on the first tick.
		$value = $start + ( (int) $page - 1 );
		return add_query_arg( array( $param => $value ), $list_url );
	}

	/**
	 * @return Supcomp_Extractor_Offer[]
	 */
	private static function product_to_offers( array $product, array $config, $site, $store_name, $run_id, $exported_at, $currency ) {
		$fields   = isset( $config['fields'] ) && is_array( $config['fields'] ) ? $config['fields'] : array();
		$raw_keys = isset( $config['raw_attributes'] ) && is_array( $config['raw_attributes'] ) ? $config['raw_attributes'] : array();

		$variants = array( null ); // default: one product-level row
		if ( ! empty( $config['variants_path'] ) ) {
			$resolved = self::resolve_path( $product, (string) $config['variants_path'] );
			if ( is_array( $resolved ) && ! empty( $resolved ) ) {
				$variants = array_values( $resolved );
			}
		}

		$valid_fields  = Supcomp_Extractor_Offer::fieldnames();
		$stock_allowed = class_exists( 'Supcomp_Installer' ) ? Supcomp_Installer::STOCK_STATUSES : array( 'in_stock', 'out_of_stock', 'backorder', 'unavailable', 'unknown' );

		$offers = array();
		foreach ( $variants as $variant ) {
			$variant = is_array( $variant ) ? $variant : null;

			$offer                = new Supcomp_Extractor_Offer();
			$offer->export_run_id = (string) $run_id;
			$offer->exported_at   = (string) $exported_at;
			$offer->source        = 'json';
			$offer->site          = (string) $site;
			$offer->store_name    = (string) $store_name;
			$offer->currency      = (string) $currency;
			$offer->currency_minor_unit        = '2';
			$offer->on_sale                     = 'false';
			$offer->price_source                = 'json_field_map';
			$offer->variation_retrieval_status  = ( $variant !== null ) ? 'retrieved' : 'not_applicable';

			foreach ( $fields as $field => $spec ) {
				if ( ! in_array( (string) $field, $valid_fields, true ) ) {
					continue;
				}
				$value = self::resolve_spec( $spec, $product, $variant );
				if ( $value === null ) {
					continue;
				}
				if ( $field === 'stock_status' ) {
					$value = in_array( (string) $value, $stock_allowed, true ) ? (string) $value : 'unknown';
				}
				$offer->{$field} = is_scalar( $value ) ? (string) $value : self::json_compact( $value );
			}

			// regular_price defaults to current_price when only one is mapped,
			// so cost-per-active-unit has a number to work with.
			if ( $offer->regular_price === '' && $offer->current_price !== '' ) {
				$offer->regular_price = $offer->current_price;
			}
			if ( $offer->current_price === '' && $offer->regular_price !== '' ) {
				$offer->current_price = $offer->regular_price;
			}
			// Derive on_sale from a mapped sale_price below the regular price
			// (mirrors the Shopify handler). Only when the map didn't set it.
			if ( $offer->on_sale === 'false' && $offer->sale_price !== '' && is_numeric( $offer->sale_price )
				&& $offer->regular_price !== '' && is_numeric( $offer->regular_price )
				&& (float) $offer->sale_price < (float) $offer->regular_price ) {
				$offer->on_sale = 'true';
			}
			if ( $offer->stock_status === '' ) {
				$offer->stock_status = 'unknown';
			}
			// Feeds that omit a per-row currency (e.g. WooCommerce product
			// objects) can supply a fallback literal via the config.
			if ( $offer->currency === '' && ! empty( $config['currency_default'] ) ) {
				$offer->currency = (string) $config['currency_default'];
			}

			// Preserve site-specific extras (form, dosage, tier prices, …) as an
			// audit trail without surfacing them as normalized columns — same
			// role raw_attributes_json plays for the Shopify handler.
			$raw = array();
			foreach ( $raw_keys as $key ) {
				$val = self::resolve_path_scoped( (string) $key, $product, $variant );
				if ( $val !== null ) {
					$raw[ self::raw_label( (string) $key ) ] = $val;
				}
			}
			if ( ! empty( $raw ) ) {
				$offer->raw_attributes_json = self::json_compact( $raw );
			}

			$offers[] = $offer;
		}

		return $offers;
	}

	/**
	 * Resolve a field spec — either a string dot-path or { from, transform }.
	 */
	private static function resolve_spec( $spec, array $product, $variant ) {
		if ( is_string( $spec ) ) {
			return self::resolve_path_scoped( $spec, $product, $variant );
		}
		if ( is_array( $spec ) && isset( $spec['from'] ) ) {
			// `from` may be a single path or a fallback list.
			$value = self::resolve_first( $spec['from'], $product, $variant );
			if ( isset( $spec['transform'] ) ) {
				return self::apply_transform( $value, (string) $spec['transform'] );
			}
			return $value;
		}
		if ( is_array( $spec ) ) {
			// A bare list of paths — first non-empty wins. Lets a feed with a
			// sparse field fall back to a reliable one, e.g. ["sku", "slug"] so
			// products with a blank SKU still get a unique, stable key.
			return self::resolve_first( $spec, $product, $variant );
		}
		return null;
	}

	/**
	 * Resolve the first path that yields a non-empty value. Accepts a single
	 * path string or a list of them.
	 */
	private static function resolve_first( $paths, array $product, $variant ) {
		foreach ( (array) $paths as $path ) {
			$value = self::resolve_path_scoped( (string) $path, $product, $variant );
			if ( $value !== null && $value !== '' ) {
				return $value;
			}
		}
		return null;
	}

	/**
	 * Resolve a path, honoring the `@variant.` scope prefix. Without the prefix
	 * the path resolves against the product.
	 */
	private static function resolve_path_scoped( $path, array $product, $variant ) {
		$path = (string) $path;
		if ( strpos( $path, '@variant.' ) === 0 ) {
			if ( ! is_array( $variant ) ) {
				return null;
			}
			return self::resolve_path( $variant, substr( $path, strlen( '@variant.' ) ) );
		}
		if ( $path === '@variant' ) {
			return is_array( $variant ) ? $variant : null;
		}
		return self::resolve_path( $product, $path );
	}

	/**
	 * Walk a dot/bracket path into a decoded JSON structure. `a.b[0].c` and
	 * `a.b.0.c` are equivalent. Returns null on any miss.
	 */
	private static function resolve_path( $obj, $path ) {
		$path = (string) $path;
		if ( $path === '' ) {
			return $obj;
		}
		$normalized = str_replace( array( '[', ']' ), array( '.', '' ), $path );
		$tokens     = array_filter( explode( '.', $normalized ), static function ( $t ) {
			return $t !== '';
		} );
		$cursor = $obj;
		foreach ( $tokens as $token ) {
			if ( is_array( $cursor ) && array_key_exists( $token, $cursor ) ) {
				$cursor = $cursor[ $token ];
				continue;
			}
			return null;
		}
		return $cursor;
	}

	private static function apply_transform( $value, $name ) {
		switch ( $name ) {
			case 'as_string':
				return is_scalar( $value ) ? (string) $value : null;
			case 'strip_html':
				return self::strip_html( is_scalar( $value ) ? (string) $value : '' );
			case 'bool_to_status':
			case 'truthy_to_status':
				if ( $value === null ) {
					return 'unknown';
				}
				return self::truthy( $value ) ? 'in_stock' : 'out_of_stock';
			case 'gt_zero_to_status':
				if ( ! is_numeric( $value ) ) {
					return 'unknown';
				}
				return ( (float) $value > 0 ) ? 'in_stock' : 'out_of_stock';
			case 'woo_stock_to_status':
				// Normalize WooCommerce's stock vocabulary to our enum.
				switch ( strtolower( trim( (string) $value ) ) ) {
					case 'instock':
						return 'in_stock';
					case 'outofstock':
						return 'out_of_stock';
					case 'onbackorder':
						return 'backorder';
				}
				return 'unknown';
		}
		return $value;
	}

	private static function truthy( $v ) {
		if ( is_bool( $v ) ) {
			return $v;
		}
		if ( is_numeric( $v ) ) {
			return (float) $v != 0.0;
		}
		$s = strtolower( trim( (string) $v ) );
		return in_array( $s, array( '1', 'true', 'yes', 'y', 'in_stock', 'instock', 'available' ), true );
	}

	private static function is_assoc( array $arr ) {
		if ( $arr === array() ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	private static function raw_label( $path ) {
		$label = str_replace( '@variant.', '', $path );
		return $label === '' ? $path : $label;
	}

	private static function strip_html( $text ) {
		if ( $text === '' ) {
			return '';
		}
		$stripped  = preg_replace( '/<[^>]+>/', ' ', $text );
		$collapsed = preg_replace( '/\s+/', ' ', (string) $stripped );
		return trim( (string) $collapsed );
	}

	private static function json_compact( $value ) {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}
}
