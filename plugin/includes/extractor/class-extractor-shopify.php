<?php
/**
 * Shopify platform handler — PHP port of try_shopify in
 * extractor/aggregate_products.py:237-265 + its helpers.
 *
 * Public API is page-oriented so Action Scheduler can chunk: each AS action
 * tick fetches a single /products.json?page=N (up to 250 products) and
 * returns the row_dicts ready to feed into the importer. The worker
 * decides whether to enqueue page N+1 based on whether the response was
 * full (250 products → keep going) or short (<250 → final page).
 *
 * Per-product variant logic mirrors the Python:
 *   - "Default Title" variants collapse to a single row with empty variant_title.
 *   - on_sale = compare_at_price > price.
 *   - stock = in_stock unless available=false (out) or
 *     available=true + inventory_quantity<=0 + policy=continue (backorder).
 *   - currency_minor_unit hardcoded to "2" — Shopify pricing API gives
 *     decimal strings, not minor units.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Extractor_Shopify {

	const PAGE_SIZE = 250;
	const MAX_PAGES = 50;

	/**
	 * Fetch one page of /products.json and convert to row_dicts.
	 *
	 * @param string $site            Site base URL (no trailing slash).
	 * @param int    $page            1-indexed.
	 * @param string $run_id          export_run_id stamped on every row.
	 * @param string $exported_at     ISO timestamp stamped on every row.
	 * @param string $store_name      Optional. Pass empty on first call;
	 *                                  the caller can fetch_store_meta()
	 *                                  once and reuse the value across pages.
	 * @param string $currency        Optional. Same caching idea.
	 * @return array{
	 *     rows: array<int, array>,
	 *     batch_size: int,
	 *     status: string,            // 'ok' | 'empty' | 'not_shopify' | 'http_error'
	 *     http_status: int,
	 * }
	 */
	public static function fetch_page( $site, $page, $run_id, $exported_at, $store_name = '', $currency = '' ) {
		$url      = self::build_url( $site, '/products.json', array( 'limit' => self::PAGE_SIZE, 'page' => (int) $page ) );
		$response = Supcomp_Extractor_Http::get( $url );

		if ( is_wp_error( $response ) ) {
			return array(
				'rows'        => array(),
				'batch_size'  => 0,
				'status'      => 'http_error',
				'http_status' => 0,
			);
		}

		// 404 / 401 / unexpected non-200 — not a Shopify site (or auth-gated).
		if ( $response['status'] !== 200 ) {
			return array(
				'rows'        => array(),
				'batch_size'  => 0,
				'status'      => $response['status'] === 404 ? 'not_shopify' : 'http_error',
				'http_status' => (int) $response['status'],
			);
		}

		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) ) {
			return array(
				'rows'        => array(),
				'batch_size'  => 0,
				'status'      => 'not_shopify',
				'http_status' => 200,
			);
		}

		$products = isset( $data['products'] ) && is_array( $data['products'] ) ? $data['products'] : array();
		if ( empty( $products ) ) {
			return array(
				'rows'        => array(),
				'batch_size'  => 0,
				'status'      => 'empty',
				'http_status' => 200,
			);
		}

		$rows = array();
		foreach ( $products as $product ) {
			foreach ( self::product_to_offers( $product, $site, $store_name, $run_id, $exported_at, $currency ) as $offer ) {
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
	 * Shopify's /products.json doesn't include shop name or currency, but
	 * /meta.json does. Some storefronts return fields at the root; older
	 * ones nest them under {shop: {...}}. Returns (store_name, currency);
	 * each "" if not reachable. Mirrors fetch_shopify_meta() in Python.
	 */
	public static function fetch_store_meta( $site ) {
		$url      = self::build_url( $site, '/meta.json' );
		$response = Supcomp_Extractor_Http::get( $url );
		if ( is_wp_error( $response ) || $response['status'] !== 200 ) {
			return array( 'store_name' => '', 'currency' => '' );
		}
		$meta = json_decode( $response['body'], true );
		if ( ! is_array( $meta ) ) {
			return array( 'store_name' => '', 'currency' => '' );
		}
		$src = ( isset( $meta['shop'] ) && is_array( $meta['shop'] ) ) ? $meta['shop'] : $meta;
		return array(
			'store_name' => isset( $src['name'] ) ? (string) $src['name'] : '',
			'currency'   => isset( $src['currency'] ) ? (string) $src['currency'] : '',
		);
	}

	/**
	 * Convert one product dict into one or more Offer objects (one per variant,
	 * or one product-level row if there are no variants).
	 *
	 * @return Supcomp_Extractor_Offer[]
	 */
	private static function product_to_offers( $product, $site, $store_name, $run_id, $exported_at, $currency ) {
		$variants          = isset( $product['variants'] ) && is_array( $product['variants'] ) ? $product['variants'] : array();
		$handle            = isset( $product['handle'] ) ? (string) $product['handle'] : '';
		$product_title     = isset( $product['title'] ) ? (string) $product['title'] : '';
		$description       = self::strip_html( isset( $product['body_html'] ) ? (string) $product['body_html'] : '' );
		$vendor            = isset( $product['vendor'] ) ? (string) $product['vendor'] : '';
		$product_type      = isset( $product['product_type'] ) ? (string) $product['product_type'] : '';
		$tags              = isset( $product['tags'] ) ? $product['tags'] : array();
		$options           = isset( $product['options'] ) && is_array( $product['options'] ) ? $product['options'] : array();
		$source_created_at = isset( $product['created_at'] ) ? (string) $product['created_at'] : '';
		$source_updated_at = isset( $product['updated_at'] ) ? (string) $product['updated_at'] : '';
		$source_product_url = $handle !== '' ? rtrim( $site, '/' ) . '/products/' . $handle : '';
		$source_product_id  = isset( $product['id'] ) ? (string) $product['id'] : '';

		$raw_base = array(
			'tags'         => is_array( $tags ) ? $tags : array( $tags ),
			'product_type' => $product_type,
			'options'      => $options,
			'vendor'       => $vendor,
		);

		// Product with no variants — synthesize a single product-level row.
		if ( empty( $variants ) ) {
			$offer = new Supcomp_Extractor_Offer();
			$offer->export_run_id              = $run_id;
			$offer->exported_at                = $exported_at;
			$offer->source                     = 'shopify';
			$offer->site                       = $site;
			$offer->store_name                 = $store_name;
			$offer->source_product_id          = $source_product_id;
			$offer->product_title              = $product_title;
			$offer->handle                     = $handle;
			$offer->brand                      = $vendor;
			$offer->product_type               = $product_type;
			$offer->on_sale                    = 'false';
			$offer->currency                   = $currency;
			$offer->currency_minor_unit        = '2';
			$offer->price_source               = 'shopify_variant';
			$offer->stock_status               = 'unknown';
			$offer->source_product_url         = $source_product_url;
			$offer->source_created_at          = $source_created_at;
			$offer->source_updated_at          = $source_updated_at;
			$offer->variation_retrieval_status = 'not_applicable';
			$offer->description                = $description;
			$offer->raw_attributes_json        = self::json_compact( $raw_base );
			return array( $offer );
		}

		$offers = array();
		foreach ( $variants as $variant ) {
			$price      = isset( $variant['price'] ) ? (string) $variant['price'] : '';
			$compare_at = isset( $variant['compare_at_price'] ) ? (string) $variant['compare_at_price'] : '';
			$pricing    = self::pricing( $price, $compare_at );
			$stock      = self::stock_status( $variant );

			$variant_title      = isset( $variant['title'] ) ? (string) $variant['title'] : '';
			$is_default_variant = ( $variant_title === 'Default Title' );
			if ( $is_default_variant ) {
				$variant_title = '';
			}

			$variant_raw = $raw_base;
			$variant_raw['variant_options'] = self::collect_variant_options( $variant );
			$variant_raw['inventory'] = array(
				'policy'    => $variant['inventory_policy']   ?? null,
				'quantity'  => $variant['inventory_quantity'] ?? null,
				'available' => $variant['available']           ?? null,
			);

			$variant_id        = isset( $variant['id'] ) ? (string) $variant['id'] : '';
			$source_variant_url = ( $source_product_url !== '' && $variant_id !== '' && ! $is_default_variant )
				? $source_product_url . '?variant=' . $variant_id
				: '';

			$offer = new Supcomp_Extractor_Offer();
			$offer->export_run_id              = $run_id;
			$offer->exported_at                = $exported_at;
			$offer->source                     = 'shopify';
			$offer->site                       = $site;
			$offer->store_name                 = $store_name;
			$offer->source_product_id          = $source_product_id;
			$offer->source_variant_id          = $variant_id;
			$offer->product_title              = $product_title;
			$offer->variant_title              = $variant_title;
			$offer->handle                     = $handle;
			$offer->brand                      = $vendor;
			$offer->product_type               = $product_type;
			$offer->sku                        = isset( $variant['sku'] ) ? (string) $variant['sku'] : '';
			$offer->barcode                    = isset( $variant['barcode'] ) ? (string) $variant['barcode'] : '';
			$offer->regular_price              = $pricing['regular_price'];
			$offer->sale_price                 = $pricing['sale_price'];
			$offer->current_price              = $price;
			$offer->on_sale                    = $pricing['on_sale'];
			$offer->currency                   = $currency;
			$offer->currency_minor_unit        = '2';
			$offer->price_source               = 'shopify_variant';
			$offer->stock_status               = $stock;
			$offer->purchasable                = self::bool_str( $variant['available'] ?? null );
			$offer->source_product_url         = $source_product_url;
			$offer->source_variant_url         = $source_variant_url;
			$offer->source_created_at          = $source_created_at;
			$offer->source_updated_at          = $source_updated_at;
			$offer->variation_retrieval_status = $is_default_variant ? 'not_applicable' : 'retrieved';
			$offer->description                = $description;
			$offer->raw_attributes_json        = self::json_compact( $variant_raw );
			$offers[]                          = $offer;
		}

		return $offers;
	}

	/**
	 * Mirrors _shopify_pricing — only treats it as on sale if compare_at > price.
	 */
	private static function pricing( $price, $compare_at ) {
		if ( $price === '' ) {
			return array( 'regular_price' => '', 'sale_price' => '', 'on_sale' => 'false' );
		}
		if ( ! is_numeric( $price ) ) {
			return array( 'regular_price' => $price, 'sale_price' => '', 'on_sale' => 'false' );
		}
		$price_f = (float) $price;
		if ( $compare_at !== '' && is_numeric( $compare_at ) ) {
			$compare_f = (float) $compare_at;
			if ( $compare_f > $price_f ) {
				return array( 'regular_price' => $compare_at, 'sale_price' => $price, 'on_sale' => 'true' );
			}
		}
		return array( 'regular_price' => $price, 'sale_price' => '', 'on_sale' => 'false' );
	}

	/**
	 * Mirrors _shopify_stock_status. Backorder is the non-obvious case.
	 */
	private static function stock_status( $variant ) {
		$available = $variant['available'] ?? null;
		if ( $available === true ) {
			$qty    = $variant['inventory_quantity'] ?? null;
			$policy = $variant['inventory_policy']   ?? null;
			if ( is_numeric( $qty ) && (float) $qty <= 0 && $policy === 'continue' ) {
				return 'backorder';
			}
			return 'in_stock';
		}
		if ( $available === false ) {
			return 'out_of_stock';
		}
		return 'unknown';
	}

	private static function collect_variant_options( $variant ) {
		$out = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$key = 'option' . $i;
			if ( isset( $variant[ $key ] ) && $variant[ $key ] !== null && $variant[ $key ] !== '' ) {
				$out[ $key ] = $variant[ $key ];
			}
		}
		return $out;
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
		$stripped = preg_replace( '/<[^>]+>/', ' ', $text );
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
}
