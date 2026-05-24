<?php
/**
 * WooCommerce platform handler — PHP port of try_woocommerce in
 * extractor/aggregate_products.py:418-794 + helpers.
 *
 * Public API is page-oriented (same shape as Supcomp_Extractor_Shopify):
 * each AS action tick fetches one page of /wp-json/wc/store/v1/products
 * (up to 100 products) and inline-fetches variations for variable
 * products. The worker chains follow-on pages when a batch comes back
 * full.
 *
 * Quirks worth knowing about:
 *
 *   - Woo Store API prices are minor-unit integers (e.g. "1234" with
 *     currency_minor_unit=2 → 12.34). Some plugin-customized endpoints
 *     return already-decimal strings ("12.34") instead — we disambiguate
 *     by checking for a "." in the raw string.
 *
 *   - on_sale flag gates `sale_price`: some stores leave a stale
 *     sale_price value on the row even when the merchant has ended the
 *     promotion, so we only populate it when on_sale=true AND the value
 *     differs from regular_price. Matches Python suppression at line 501.
 *
 *   - Variable products require a second HTTP call per parent to fetch
 *     their /variations endpoint. If that fails, we fall back to a
 *     single parent row with variation_retrieval_status=fallback_parent_only
 *     and a price-range payload in raw_attributes_json so the operator
 *     can see what they're missing.
 *
 *   - Variation URL synthesis is non-obvious: variations are selected on
 *     the public site via `?attribute_<taxonomy>=<value>` query params.
 *     We match variation attributes back to parent attribute taxonomies
 *     by id first, then by lowercased name. Slugify name when no
 *     taxonomy exists (product-local attribute).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Extractor_Woo {

	const PAGE_SIZE = 100;
	const MAX_PAGES = 50;

	/**
	 * Fetch one page of /wp-json/wc/store/v1/products and convert to
	 * row_dicts. Inline-fetches variations for variable products.
	 *
	 * @return array{
	 *     rows: array,
	 *     batch_size: int,
	 *     status: string,         // 'ok' | 'empty' | 'not_woo' | 'http_error'
	 *     http_status: int,
	 * }
	 */
	public static function fetch_page( $site, $page, $run_id, $exported_at, $store_name = '' ) {
		$url = self::build_url(
			$site,
			'/wp-json/wc/store/v1/products',
			array( 'per_page' => self::PAGE_SIZE, 'page' => (int) $page )
		);
		$response = Supcomp_Extractor_Http::get( $url );

		if ( is_wp_error( $response ) ) {
			return array( 'rows' => array(), 'batch_size' => 0, 'status' => 'http_error', 'http_status' => 0 );
		}

		if ( $response['status'] !== 200 ) {
			return array(
				'rows'        => array(),
				'batch_size'  => 0,
				'status'      => $response['status'] === 404 ? 'not_woo' : 'http_error',
				'http_status' => (int) $response['status'],
			);
		}

		$batch = json_decode( $response['body'], true );
		if ( ! is_array( $batch ) ) {
			return array( 'rows' => array(), 'batch_size' => 0, 'status' => 'not_woo', 'http_status' => 200 );
		}
		// The Store API returns a JSON list at the top level; if the body is
		// an associative array (typical Woo plugin error envelope), treat as not-woo.
		if ( self::is_assoc( $batch ) ) {
			return array( 'rows' => array(), 'batch_size' => 0, 'status' => 'not_woo', 'http_status' => 200 );
		}
		if ( empty( $batch ) ) {
			return array( 'rows' => array(), 'batch_size' => 0, 'status' => 'empty', 'http_status' => 200 );
		}

		$rows = array();
		foreach ( $batch as $product ) {
			foreach ( self::product_to_offers( $product, $site, $store_name, $run_id, $exported_at ) as $offer ) {
				$rows[] = $offer->to_row_dict();
			}
		}

		return array(
			'rows'        => $rows,
			'batch_size'  => count( $batch ),
			'status'      => 'ok',
			'http_status' => 200,
		);
	}

	/**
	 * WordPress exposes site name at the REST root: GET /wp-json/ → {name, ...}.
	 * Currency is per-product on the Store API (not on the REST root) so we
	 * leave that to fetch_page().
	 */
	public static function fetch_store_meta( $site ) {
		$url      = self::build_url( $site, '/wp-json/' );
		$response = Supcomp_Extractor_Http::get( $url );
		if ( is_wp_error( $response ) || $response['status'] !== 200 ) {
			return array( 'store_name' => '', 'currency' => '' );
		}
		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) ) {
			return array( 'store_name' => '', 'currency' => '' );
		}
		return array(
			'store_name' => isset( $data['name'] ) ? (string) $data['name'] : '',
			'currency'   => '',
		);
	}

	/**
	 * One product → 1+ Offer objects.
	 *
	 * @return Supcomp_Extractor_Offer[]
	 */
	private static function product_to_offers( $product, $site, $store_name, $run_id, $exported_at ) {
		$has_options    = ! empty( $product['has_options'] );
		$product_id     = isset( $product['id'] ) ? (string) $product['id'] : '';
		$product_title  = isset( $product['name'] ) ? (string) $product['name'] : '';
		$handle         = isset( $product['slug'] ) ? (string) $product['slug'] : '';
		$permalink      = isset( $product['permalink'] ) ? (string) $product['permalink'] : '';
		$description    = self::strip_html(
			isset( $product['description'] ) && $product['description'] !== ''
				? (string) $product['description']
				: ( isset( $product['short_description'] ) ? (string) $product['short_description'] : '' )
		);
		$categories     = isset( $product['categories'] ) && is_array( $product['categories'] ) ? $product['categories'] : array();
		$tags           = isset( $product['tags'] ) && is_array( $product['tags'] ) ? $product['tags'] : array();
		$attributes     = isset( $product['attributes'] ) && is_array( $product['attributes'] ) ? $product['attributes'] : array();
		$prices         = isset( $product['prices'] ) && is_array( $product['prices'] ) ? $product['prices'] : array();
		$minor_unit     = $prices['currency_minor_unit'] ?? null;
		$currency_code  = isset( $prices['currency_code'] ) ? (string) $prices['currency_code'] : '';

		$brand        = self::extract_brand( $product, $attributes );
		$product_type = implode(
			', ',
			array_values( array_filter( array_map(
				static function ( $c ) {
					return is_array( $c ) && isset( $c['name'] ) ? (string) $c['name'] : '';
				},
				$categories
			) ) )
		);

		$raw_base = array(
			'categories'  => array_values( array_filter( array_map(
				static function ( $c ) {
					return is_array( $c ) && isset( $c['name'] ) ? (string) $c['name'] : '';
				},
				$categories
			) ) ),
			'tags'        => array_values( array_filter( array_map(
				static function ( $t ) {
					return is_array( $t ) && isset( $t['name'] ) ? (string) $t['name'] : '';
				},
				$tags
			) ) ),
			'attributes'  => $attributes,
			'has_options' => $has_options,
		);

		if ( ! $has_options ) {
			$on_sale_bool = ! empty( $product['on_sale'] );
			$regular_raw  = isset( $prices['regular_price'] ) ? (string) $prices['regular_price'] : '';
			$sale_raw     = isset( $prices['sale_price'] )    ? (string) $prices['sale_price']    : '';
			$current_raw  = isset( $prices['price'] )         ? (string) $prices['price']         : '';

			$offer = new Supcomp_Extractor_Offer();
			$offer->export_run_id              = $run_id;
			$offer->exported_at                = $exported_at;
			$offer->source                     = 'woocommerce';
			$offer->site                       = $site;
			$offer->store_name                 = $store_name;
			$offer->source_product_id          = $product_id;
			$offer->product_title              = $product_title;
			$offer->handle                     = $handle;
			$offer->brand                      = $brand;
			$offer->product_type               = $product_type;
			$offer->sku                        = isset( $product['sku'] ) ? (string) $product['sku'] : '';
			$offer->barcode                    = self::extract_barcode( $product );
			$offer->regular_price              = self::woo_decimal( $regular_raw, $minor_unit );
			$offer->sale_price                 = ( $on_sale_bool && $sale_raw !== '' && $sale_raw !== $regular_raw )
				? self::woo_decimal( $sale_raw, $minor_unit )
				: '';
			$offer->current_price              = self::woo_decimal( $current_raw, $minor_unit );
			$offer->on_sale                    = $on_sale_bool ? 'true' : 'false';
			$offer->currency                   = $currency_code;
			$offer->currency_minor_unit        = ( $minor_unit === null ) ? '' : (string) $minor_unit;
			$offer->price_source               = 'woo_store_api';
			$offer->stock_status               = self::stock_status( $product );
			$offer->purchasable                = self::bool_str( $product['is_purchasable'] ?? null );
			$offer->source_product_url         = $permalink;
			$offer->variation_retrieval_status = 'not_applicable';
			$offer->description                = $description;
			$offer->raw_attributes_json        = self::json_compact( $raw_base );
			return array( $offer );
		}

		// Variable product: fetch its variations endpoint.
		$variation_offers = self::fetch_variations(
			$site,
			$store_name,
			$product_id,
			$product,
			$raw_base,
			$brand,
			$product_type,
			$currency_code,
			$minor_unit,
			$run_id,
			$exported_at
		);

		if ( ! empty( $variation_offers ) ) {
			return $variation_offers;
		}

		// Variations fetch failed or returned empty — fall back to a single
		// parent row with the price range so the operator can investigate.
		$current_raw       = isset( $prices['price'] ) ? (string) $prices['price'] : '';
		$raw_with_range    = $raw_base;
		$raw_with_range['variable_product_fallback'] = true;
		$price_range_raw   = isset( $prices['price_range'] ) && is_array( $prices['price_range'] ) ? $prices['price_range'] : array();
		$raw_with_range['price_range'] = array(
			'min' => $price_range_raw['min_amount'] ?? null,
			'max' => $price_range_raw['max_amount'] ?? null,
		);

		$offer = new Supcomp_Extractor_Offer();
		$offer->export_run_id              = $run_id;
		$offer->exported_at                = $exported_at;
		$offer->source                     = 'woocommerce';
		$offer->site                       = $site;
		$offer->store_name                 = $store_name;
		$offer->source_product_id          = $product_id;
		$offer->product_title              = $product_title;
		$offer->variant_title              = '(variations not retrieved)';
		$offer->handle                     = $handle;
		$offer->brand                      = $brand;
		$offer->product_type               = $product_type;
		$offer->sku                        = isset( $product['sku'] ) ? (string) $product['sku'] : '';
		$offer->barcode                    = self::extract_barcode( $product );
		$offer->current_price              = self::woo_decimal( $current_raw, $minor_unit );
		$offer->on_sale                    = self::bool_str( $product['on_sale'] ?? false );
		$offer->currency                   = $currency_code;
		$offer->currency_minor_unit        = ( $minor_unit === null ) ? '' : (string) $minor_unit;
		$offer->price_source               = 'woo_store_api';
		$offer->stock_status               = self::stock_status( $product );
		$offer->purchasable                = self::bool_str( $product['is_purchasable'] ?? null );
		$offer->source_product_url         = $permalink;
		$offer->is_variable_parent         = 'true';
		$offer->variation_retrieval_status = 'fallback_parent_only';
		$offer->description                = $description;
		$offer->raw_attributes_json        = self::json_compact( $raw_with_range );
		return array( $offer );
	}

	/**
	 * Fetch variation objects for a variable parent and convert to Offer
	 * objects.
	 *
	 * Modern WooCommerce (Store API in Woo 8.x+) removed the per-product
	 * /products/{id}/variations endpoint, which now returns 404. The
	 * supported path is a filter on the products list:
	 * /products?type=variation&parent={id}. We try the modern path first
	 * and fall back to the legacy path for older stores.
	 *
	 * @return Supcomp_Extractor_Offer[]  Empty array on any failure.
	 */
	private static function fetch_variations(
		$site, $store_name, $product_id, $parent, $raw_base, $brand, $product_type,
		$currency_code, $minor_unit, $run_id, $exported_at
	) {
		// Modern endpoint (Woo 8.x+).
		$variations = self::request_variations(
			self::build_url(
				$site,
				'/wp-json/wc/store/v1/products',
				array(
					'type'     => 'variation',
					'parent'   => $product_id,
					'per_page' => 100,
				)
			),
			true
		);
		// Legacy endpoint (older Woo). The modern endpoint returns null on
		// hard failure (non-200, malformed body, or a payload that doesn't
		// look like variations — older Woo can silently ignore unknown
		// query params and return the products list instead).
		if ( $variations === null ) {
			$variations = self::request_variations(
				self::build_url(
					$site,
					'/wp-json/wc/store/v1/products/' . rawurlencode( $product_id ) . '/variations',
					array( 'per_page' => 100 )
				),
				false
			);
		}
		if ( ! is_array( $variations ) || empty( $variations ) ) {
			return array();
		}

		$parent_title = isset( $parent['name'] ) ? (string) $parent['name'] : '';
		$permalink    = isset( $parent['permalink'] ) ? (string) $parent['permalink'] : '';
		$handle       = isset( $parent['slug'] ) ? (string) $parent['slug'] : '';
		$description  = self::strip_html(
			isset( $parent['description'] ) && $parent['description'] !== ''
				? (string) $parent['description']
				: ( isset( $parent['short_description'] ) ? (string) $parent['short_description'] : '' )
		);

		$offers = array();
		foreach ( $variations as $v ) {
			$v_prices     = ( isset( $v['prices'] ) && is_array( $v['prices'] ) ) ? $v['prices'] : array();
			$v_minor      = $v_prices['currency_minor_unit'] ?? $minor_unit;
			$on_sale_bool = ! empty( $v['on_sale'] );
			$regular_raw  = isset( $v_prices['regular_price'] ) ? (string) $v_prices['regular_price'] : '';
			$sale_raw     = isset( $v_prices['sale_price'] )    ? (string) $v_prices['sale_price']    : '';
			$current_raw  = isset( $v_prices['price'] )         ? (string) $v_prices['price']         : '';

			$v_attrs        = isset( $v['attributes'] ) && is_array( $v['attributes'] ) ? $v['attributes'] : array();
			$variant_title  = self::variant_title_from_attrs( $v_attrs );

			$variant_raw                          = $raw_base;
			$variant_raw['variation_attributes']  = $v_attrs;

			$variant_url = self::variation_url( $permalink, $parent, $v );

			$offer = new Supcomp_Extractor_Offer();
			$offer->export_run_id              = $run_id;
			$offer->exported_at                = $exported_at;
			$offer->source                     = 'woocommerce';
			$offer->site                       = $site;
			$offer->store_name                 = $store_name;
			$offer->source_product_id          = $product_id;
			$offer->source_variant_id          = isset( $v['id'] ) ? (string) $v['id'] : '';
			$offer->product_title              = $parent_title;
			$offer->variant_title              = $variant_title;
			$offer->handle                     = $handle;
			$offer->brand                      = $brand;
			$offer->product_type               = $product_type;
			$offer->sku                        = isset( $v['sku'] ) ? (string) $v['sku'] : '';
			$offer->regular_price              = self::woo_decimal( $regular_raw, $v_minor );
			$offer->sale_price                 = ( $on_sale_bool && $sale_raw !== '' && $sale_raw !== $regular_raw )
				? self::woo_decimal( $sale_raw, $v_minor )
				: '';
			$offer->current_price              = self::woo_decimal( $current_raw, $v_minor );
			$offer->on_sale                    = $on_sale_bool ? 'true' : 'false';
			$offer->currency                   = isset( $v_prices['currency_code'] ) && $v_prices['currency_code'] !== ''
				? (string) $v_prices['currency_code']
				: $currency_code;
			$offer->currency_minor_unit        = ( $v_minor === null ) ? '' : (string) $v_minor;
			$offer->price_source               = 'woo_variation_api';
			$offer->stock_status               = self::stock_status( $v );
			$offer->purchasable                = self::bool_str( $v['is_purchasable'] ?? null );
			$offer->source_product_url         = $permalink;
			$offer->source_variant_url         = ( $variant_url !== $permalink ) ? $variant_url : '';
			$offer->variation_retrieval_status = 'retrieved';
			$offer->description                = $description;
			$offer->raw_attributes_json        = self::json_compact( $variant_raw );
			$offers[] = $offer;
		}

		return $offers;
	}

	/**
	 * GET a variation-list endpoint and return the parsed array on success,
	 * or null on hard failure (non-200, malformed JSON, error envelope, or
	 * — when $strict is true — a payload whose items don't look like
	 * variation-typed products).
	 *
	 * $strict guards the modern endpoint against older Woo versions that
	 * silently ignore unknown query params and would return the regular
	 * products list instead of variations. The legacy endpoint doesn't
	 * need that guard because it can only return variations or 404.
	 */
	private static function request_variations( $url, $strict ) {
		$response = Supcomp_Extractor_Http::get( $url );
		if ( is_wp_error( $response ) || $response['status'] !== 200 ) {
			return null;
		}
		$body = json_decode( $response['body'], true );
		if ( ! is_array( $body ) || self::is_assoc( $body ) ) {
			return null;
		}
		if ( empty( $body ) ) {
			return array();
		}
		if ( $strict ) {
			$first = $body[0];
			if ( ! is_array( $first ) || ( isset( $first['type'] ) && $first['type'] !== 'variation' ) ) {
				return null;
			}
		}
		return $body;
	}

	private static function variant_title_from_attrs( array $v_attrs ) {
		$parts = array();
		foreach ( $v_attrs as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$val = isset( $a['value'] ) && $a['value'] !== '' ? $a['value'] : ( $a['name'] ?? '' );
			if ( $val !== '' && $val !== null ) {
				$parts[] = (string) $val;
			}
		}
		return implode( ' / ', $parts );
	}

	private static function extract_brand( $product, $attributes ) {
		$brands = $product['brands'] ?? null;
		if ( is_array( $brands ) && ! empty( $brands ) ) {
			$first = $brands[0];
			if ( is_array( $first ) && isset( $first['name'] ) ) {
				return (string) $first['name'];
			}
		}
		foreach ( $attributes as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$name = strtolower( trim( (string) ( $a['name'] ?? '' ) ) );
			if ( $name !== 'brand' ) {
				continue;
			}
			$terms = $a['terms'] ?? null;
			if ( is_array( $terms ) && ! empty( $terms ) && is_array( $terms[0] ) && isset( $terms[0]['name'] ) ) {
				return (string) $terms[0]['name'];
			}
			$opts = $a['options'] ?? null;
			if ( is_array( $opts ) && ! empty( $opts ) ) {
				return (string) $opts[0];
			}
		}
		return '';
	}

	private static function extract_barcode( $product ) {
		$gtin = $product['global_unique_id'] ?? null;
		return $gtin ? (string) $gtin : '';
	}

	private static function stock_status( $node ) {
		$is_in_stock = $node['is_in_stock'] ?? null;
		if ( $is_in_stock === true ) {
			if ( ( $node['is_on_backorder'] ?? null ) === true ) {
				return 'backorder';
			}
			return 'in_stock';
		}
		if ( $is_in_stock === false ) {
			return 'out_of_stock';
		}
		return 'unknown';
	}

	/**
	 * Build the variation-selecting URL by appending ?attribute_<key>=<value>
	 * params to the parent permalink. Port of _woo_variation_url at
	 * aggregate_products.py:713-769.
	 *
	 * Does NOT use http_build_query — that function rewrites duplicate keys
	 * to `key[0]=...&key[1]=...`, which WooCommerce's permalink resolver
	 * would not honor. We build the query string manually so the canonical
	 * `?attribute_pa_size=large&attribute_pa_color=red` shape is preserved.
	 */
	private static function variation_url( $permalink, $parent, $variation ) {
		if ( ! $permalink ) {
			return $permalink;
		}
		$v_attrs = isset( $variation['attributes'] ) && is_array( $variation['attributes'] ) ? $variation['attributes'] : array();
		if ( empty( $v_attrs ) ) {
			return $permalink;
		}

		$parent_attrs = isset( $parent['attributes'] ) && is_array( $parent['attributes'] ) ? $parent['attributes'] : array();
		$by_id   = array();
		$by_name = array();
		foreach ( $parent_attrs as $pa ) {
			if ( ! is_array( $pa ) ) {
				continue;
			}
			if ( isset( $pa['id'] ) ) {
				$by_id[ (string) $pa['id'] ] = $pa;
			}
			$name = strtolower( (string) ( $pa['name'] ?? '' ) );
			if ( $name !== '' ) {
				$by_name[ $name ] = $pa;
			}
		}

		$extra_pairs = array();
		foreach ( $v_attrs as $va ) {
			if ( ! is_array( $va ) ) {
				continue;
			}
			$value = $va['value'] ?? null;
			if ( $value === null || $value === '' ) {
				continue;
			}
			$parent_attr = null;
			if ( isset( $va['id'] ) ) {
				$parent_attr = $by_id[ (string) $va['id'] ] ?? null;
			}
			if ( $parent_attr === null ) {
				$parent_attr = $by_name[ strtolower( (string) ( $va['name'] ?? '' ) ) ] ?? null;
			}
			$taxonomy = is_array( $parent_attr ) ? ( $parent_attr['taxonomy'] ?? null ) : null;
			$key_part = $taxonomy ? (string) $taxonomy : self::slugify( (string) ( $va['name'] ?? '' ) );
			if ( $key_part === '' ) {
				continue;
			}
			$extra_pairs[] = array( 'attribute_' . $key_part, (string) $value );
		}

		if ( empty( $extra_pairs ) ) {
			return $permalink;
		}

		$parsed = parse_url( $permalink );
		if ( $parsed === false ) {
			return $permalink;
		}

		// Manually parse the existing query string preserving blank values,
		// duplicate keys, and key order — to match parse_qsl(keep_blank_values=True).
		$existing_pairs = array();
		if ( ! empty( $parsed['query'] ) ) {
			foreach ( explode( '&', $parsed['query'] ) as $segment ) {
				if ( $segment === '' ) {
					continue;
				}
				if ( strpos( $segment, '=' ) === false ) {
					$existing_pairs[] = array( urldecode( $segment ), '' );
				} else {
					list( $k, $v ) = explode( '=', $segment, 2 );
					$existing_pairs[] = array( urldecode( $k ), urldecode( $v ) );
				}
			}
		}

		$merged = array_merge( $existing_pairs, $extra_pairs );
		$query_parts = array();
		foreach ( $merged as $pair ) {
			$query_parts[] = rawurlencode( $pair[0] ) . '=' . rawurlencode( $pair[1] );
		}
		$new_query = implode( '&', $query_parts );

		// Rebuild the URL.
		$scheme   = isset( $parsed['scheme'] ) ? $parsed['scheme'] . '://' : '';
		$host     = $parsed['host'] ?? '';
		$port     = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';
		$user     = isset( $parsed['user'] ) ? $parsed['user'] : '';
		$pass     = isset( $parsed['pass'] ) ? ':' . $parsed['pass'] : '';
		$userpass = $user !== '' ? $user . $pass . '@' : '';
		$path     = $parsed['path'] ?? '';
		$fragment = isset( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '';
		return $scheme . $userpass . $host . $port . $path . '?' . $new_query . $fragment;
	}

	/**
	 * Convert a Woo Store API price (raw) to a decimal string. Mirrors
	 * _woo_decimal at aggregate_products.py:772-794.
	 *   - Already-decimal "12.34" → pass through, quantized to $n digits.
	 *   - Minor-unit integer "1234" + minor_unit=2 → "12.34".
	 *   - Non-numeric junk → returned as-is.
	 */
	private static function woo_decimal( $raw, $minor_unit ) {
		if ( $raw === '' || $raw === null ) {
			return '';
		}
		$n = is_numeric( $minor_unit ) ? (int) $minor_unit : 2;
		if ( $n < 0 ) {
			$n = 0;
		}
		$raw_str = (string) $raw;
		if ( ! is_numeric( $raw_str ) ) {
			return $raw_str;
		}
		if ( strpos( $raw_str, '.' ) !== false ) {
			// Already a decimal — quantize to $n fractional digits.
			return number_format( (float) $raw_str, $n, '.', '' );
		}
		// Minor-unit integer — divide by 10^n. Use bcdiv for precision when
		// available; fall back to float math otherwise (price magnitudes are
		// small enough that doubles don't lose precision in practice).
		if ( function_exists( 'bcdiv' ) ) {
			$divisor = str_pad( '1', $n + 1, '0', STR_PAD_RIGHT );
			return bcdiv( $raw_str, $divisor, $n );
		}
		return number_format( ( (float) $raw_str ) / pow( 10, $n ), $n, '.', '' );
	}

	private static function slugify( $text ) {
		$text = strtolower( trim( (string) $text ) );
		$slug = preg_replace( '/[^a-z0-9]+/', '-', $text );
		return trim( (string) $slug, '-' );
	}

	private static function bool_str( $v ) {
		if ( $v === true ) {
			return 'true';
		}
		if ( $v === false ) {
			return 'false';
		}
		return '';
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

	private static function build_url( $site, $path, $query = array() ) {
		$url = rtrim( $site, '/' ) . $path;
		if ( ! empty( $query ) ) {
			$url .= '?' . http_build_query( $query );
		}
		return $url;
	}

	/**
	 * PHP doesn't distinguish "list" arrays from "associative" — but the
	 * Woo Store API returns lists at the top level for /products and
	 * /variations, and assoc arrays for error envelopes. This lets us
	 * branch defensively.
	 */
	private static function is_assoc( array $arr ) {
		if ( empty( $arr ) ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}
}
