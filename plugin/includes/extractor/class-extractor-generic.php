<?php
/**
 * Generic JSON-LD platform handler — port of try_generic in
 * extractor/aggregate_products.py:797-994 + helpers.
 *
 * Unlike Shopify/Woo, this handler doesn't paginate over a structured
 * /products endpoint. Instead:
 *
 *   1. Discover product URLs from the site's XML sitemaps (4 candidate
 *      paths tried in order; sitemap-index entries recursed into).
 *   2. Filter URLs by PRODUCT_PATH_HINTS unless the sitemap path itself
 *      contains "product" (in which case all URLs are trusted).
 *   3. For each product URL, fetch the HTML, extract every
 *      `<script type="application/ld+json">` tag, walk for @type=Product
 *      nodes (including inside @graph), and emit one row per Offer.
 *
 * To fit inside PHP's max_execution_time, the worker calls fetch_chunk()
 * with a slice of N product URLs at a time. The URL list discovered on
 * page 1 is persisted in a transient keyed by attempt id; follow-on
 * pages slice into it via the page cursor.
 *
 * HTML parsing uses DOMDocument + DOMXPath. BeautifulSoup's
 * permissiveness is replaced with libxml_use_internal_errors(true) to
 * suppress malformed-HTML warnings — DOMDocument still parses the
 * happy-path of `<script type="application/ld+json">` tags reliably
 * because they're closed cleanly even on bad pages.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Extractor_Generic {

	const CHUNK_SIZE        = 10;
	const URL_DISCOVERY_CAP = 500;
	const MAX_PAGES         = 50;     // CHUNK_SIZE × MAX_PAGES = 500-URL ceiling.

	const SITEMAP_CANDIDATES = array(
		'/sitemap_products_1.xml',
		'/product-sitemap.xml',
		'/wp-sitemap-posts-product-1.xml',
		'/sitemap.xml',
	);

	const PRODUCT_PATH_HINTS = array(
		'/product', '/products/', '/shop/', '/p/', '/item/', '/dp/',
	);

	const SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

	/**
	 * The generic handler depends on PHP's dom + simplexml extensions
	 * (HTML parsing for JSON-LD; XML parsing for sitemaps). Both are part
	 * of the default PHP installation and present on essentially every
	 * WordPress host, but the check here gives a clean operator-facing
	 * error message if a minimal PHP build lacks them — much better than
	 * a fatal "Class DOMDocument not found".
	 *
	 * @return true|string  true if available, otherwise a human message.
	 */
	public static function dependencies_ok() {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return __( 'PHP "dom" extension is not loaded. The generic JSON-LD handler needs it for HTML parsing. Ask your host to enable php-xml / php-dom.', 'supplement-compare' );
		}
		if ( ! function_exists( 'simplexml_load_string' ) ) {
			return __( 'PHP "simplexml" extension is not loaded. The generic JSON-LD handler needs it for sitemap parsing. Ask your host to enable php-xml.', 'supplement-compare' );
		}
		return true;
	}

	/**
	 * Walk the sitemap candidates and return up to URL_DISCOVERY_CAP
	 * deduplicated product URLs. Empty array if no sitemap responds with
	 * a parseable list. Mirrors discover_product_urls in Python.
	 */
	public static function discover_product_urls( $site ) {
		$seen = array();
		foreach ( self::SITEMAP_CANDIDATES as $path ) {
			$url      = rtrim( $site, '/' ) . $path;
			$response = Supcomp_Extractor_Http::get( $url );
			if ( is_wp_error( $response ) || $response['status'] !== 200 ) {
				continue;
			}
			if ( strpos( $response['body'], '<' ) === false ) {
				continue;
			}
			$trust_all = ( strpos( $path, 'product' ) !== false );
			$urls = self::parse_sitemap( $response['body'], $trust_all );
			foreach ( $urls as $u ) {
				if ( $trust_all || self::matches_product_hint( $u ) ) {
					$seen[ $u ] = true;
					if ( count( $seen ) >= self::URL_DISCOVERY_CAP ) {
						break;
					}
				}
			}
			if ( ! empty( $seen ) ) {
				break;
			}
		}
		return array_slice( array_keys( $seen ), 0, self::URL_DISCOVERY_CAP );
	}

	/**
	 * Recursively parse a sitemap XML body. Sitemap-index entries whose
	 * loc contains "product" or "shop" get fetched recursively with
	 * trust_all=true (their child sitemaps are known-product). Other
	 * sitemap-index entries are skipped.
	 *
	 * @return string[]
	 */
	private static function parse_sitemap( $xml_text, $trust_all = false ) {
		$urls = array();
		libxml_use_internal_errors( true );
		$root = simplexml_load_string( $xml_text );
		libxml_clear_errors();
		if ( $root === false ) {
			return $urls;
		}
		$root->registerXPathNamespace( 'sm', self::SITEMAP_NS );

		// Sitemap-index: recurse into child sitemaps whose loc contains
		// "product" or "shop". Other child sitemaps are ignored.
		$child_sitemaps = $root->xpath( 'sm:sitemap/sm:loc' );
		if ( $child_sitemaps ) {
			foreach ( $child_sitemaps as $loc ) {
				$loc_str = trim( (string) $loc );
				if ( $loc_str !== '' && ( strpos( $loc_str, 'product' ) !== false || strpos( $loc_str, 'shop' ) !== false ) ) {
					$response = Supcomp_Extractor_Http::get( $loc_str );
					if ( ! is_wp_error( $response ) && $response['status'] === 200 ) {
						$urls = array_merge( $urls, self::parse_sitemap( $response['body'], true ) );
					}
				}
			}
		}

		// URL entries.
		$url_locs = $root->xpath( 'sm:url/sm:loc' );
		if ( $url_locs ) {
			foreach ( $url_locs as $loc ) {
				$loc_str = trim( (string) $loc );
				if ( $loc_str !== '' ) {
					$urls[] = $loc_str;
				}
			}
		}

		return $urls;
	}

	/**
	 * Try to identify the store's name from the homepage. Priority:
	 *   og:site_name → JSON-LD Organization/WebSite name → <title>.
	 */
	public static function fetch_store_meta( $site ) {
		$response = Supcomp_Extractor_Http::get( rtrim( $site, '/' ) . '/' );
		if ( is_wp_error( $response ) || $response['status'] !== 200 ) {
			return array( 'store_name' => '', 'currency' => '' );
		}
		$dom = self::load_html_dom( $response['body'] );
		if ( $dom === null ) {
			return array( 'store_name' => '', 'currency' => '' );
		}
		$xpath = new DOMXPath( $dom );

		// og:site_name
		$nodes = $xpath->query( '//meta[@property="og:site_name"]/@content' );
		if ( $nodes && $nodes->length > 0 ) {
			$val = trim( $nodes->item( 0 )->nodeValue );
			if ( $val !== '' ) {
				return array( 'store_name' => $val, 'currency' => '' );
			}
		}

		// JSON-LD Organization/WebSite name
		$scripts = $xpath->query( '//script[@type="application/ld+json"]' );
		if ( $scripts ) {
			foreach ( $scripts as $script ) {
				$data = json_decode( trim( $script->textContent ), true );
				if ( ! is_array( $data ) ) {
					continue;
				}
				$name = self::find_org_name( $data );
				if ( $name !== '' ) {
					return array( 'store_name' => $name, 'currency' => '' );
				}
			}
		}

		// <title>
		$title_nodes = $xpath->query( '//title' );
		if ( $title_nodes && $title_nodes->length > 0 ) {
			$title = trim( $title_nodes->item( 0 )->textContent );
			if ( $title !== '' ) {
				return array( 'store_name' => $title, 'currency' => '' );
			}
		}

		return array( 'store_name' => '', 'currency' => '' );
	}

	/**
	 * Fetch + parse a slice of product URLs. Each yields zero or more
	 * Offer rows (one per JSON-LD Offer/AggregateOffer encountered).
	 *
	 * @param string[] $url_slice
	 * @return array{rows:array, batch_size:int, status:string, http_status:int}
	 */
	public static function fetch_chunk( $site, array $url_slice, $run_id, $exported_at, $store_name ) {
		$rows = array();
		foreach ( $url_slice as $url ) {
			$response = Supcomp_Extractor_Http::get( $url );
			if ( is_wp_error( $response ) || $response['status'] !== 200 ) {
				continue;
			}
			foreach ( self::extract_jsonld_products( $response['body'] ) as $product ) {
				foreach ( self::jsonld_to_offers( $product, $site, $store_name, $url, $run_id, $exported_at ) as $offer ) {
					$rows[] = $offer->to_row_dict();
				}
			}
		}
		return array(
			'rows'        => $rows,
			'batch_size'  => count( $url_slice ),
			'status'      => 'ok',
			'http_status' => 200,
		);
	}

	// ---------- HTML / JSON-LD extraction ----------

	/**
	 * Yield every JSON-LD Product node embedded in the page.
	 *
	 * @return array  Iterator over product dicts.
	 */
	private static function extract_jsonld_products( $html ) {
		$dom = self::load_html_dom( $html );
		if ( $dom === null ) {
			return array();
		}
		$xpath   = new DOMXPath( $dom );
		$scripts = $xpath->query( '//script[@type="application/ld+json"]' );
		$products = array();
		if ( ! $scripts ) {
			return $products;
		}
		foreach ( $scripts as $script ) {
			$data = json_decode( trim( $script->textContent ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			foreach ( self::walk_for_products( $data ) as $product ) {
				$products[] = $product;
			}
		}
		return $products;
	}

	/**
	 * Recursively walk a JSON-LD node looking for @type=Product entries,
	 * including descents into @graph arrays. Mirrors walk_for_products.
	 *
	 * @return array
	 */
	private static function walk_for_products( $node ) {
		$out = array();
		if ( self::is_list( $node ) ) {
			foreach ( $node as $item ) {
				$out = array_merge( $out, self::walk_for_products( $item ) );
			}
			return $out;
		}
		if ( is_array( $node ) ) {
			if ( isset( $node['@type'] ) && self::type_matches( $node['@type'], 'Product' ) ) {
				$out[] = $node;
			}
			if ( isset( $node['@graph'] ) ) {
				$out = array_merge( $out, self::walk_for_products( $node['@graph'] ) );
			}
		}
		return $out;
	}

	private static function find_org_name( $node ) {
		if ( self::is_list( $node ) ) {
			foreach ( $node as $item ) {
				$name = self::find_org_name( $item );
				if ( $name !== '' ) {
					return $name;
				}
			}
			return '';
		}
		if ( is_array( $node ) ) {
			$t = $node['@type'] ?? null;
			if ( $t !== null && ( self::type_matches( $t, 'Organization' ) || self::type_matches( $t, 'WebSite' ) ) ) {
				$n = $node['name'] ?? '';
				if ( $n !== '' && is_string( $n ) ) {
					return trim( $n );
				}
			}
			if ( isset( $node['@graph'] ) ) {
				return self::find_org_name( $node['@graph'] );
			}
		}
		return '';
	}

	// ---------- offer construction ----------

	/**
	 * Convert one JSON-LD Product node into Offer rows. AggregateOffer
	 * gets expanded into its nested offers[]. Mirrors _jsonld_to_offers.
	 *
	 * @return Supcomp_Extractor_Offer[]
	 */
	private static function jsonld_to_offers( $item, $site, $store_name, $url, $run_id, $exported_at ) {
		$name        = isset( $item['name'] ) ? (string) $item['name'] : '';
		$description = self::strip_html( isset( $item['description'] ) ? (string) $item['description'] : '' );
		$brand       = self::stringify( $item['brand'] ?? null );
		$category    = self::stringify( $item['category'] ?? null );
		$sku_top     = isset( $item['sku'] ) ? (string) $item['sku'] : '';
		$gtin        = self::first_nonempty( array(
			$item['gtin13'] ?? null,
			$item['gtin12'] ?? null,
			$item['gtin14'] ?? null,
			$item['gtin8']  ?? null,
			$item['gtin']   ?? null,
		) );

		$offers_data = $item['offers'] ?? null;
		if ( is_array( $offers_data ) ) {
			if ( self::is_list( $offers_data ) ) {
				$offer_list = $offers_data;
			} elseif ( isset( $offers_data['@type'] ) && self::type_matches( $offers_data['@type'], 'AggregateOffer' ) && isset( $offers_data['offers'] ) && self::is_list( $offers_data['offers'] ) ) {
				$offer_list = $offers_data['offers'];
			} else {
				$offer_list = array( $offers_data );
			}
		} else {
			$offer_list = array( array() );
		}

		$out = array();
		foreach ( $offer_list as $o ) {
			if ( ! is_array( $o ) ) {
				continue;
			}
			$price = (string) ( $o['price'] ?? $o['lowPrice'] ?? '' );
			$currency = (string) ( $o['priceCurrency'] ?? '' );
			$avail = strtolower( (string) ( $o['availability'] ?? '' ) );
			if ( strpos( $avail, 'instock' ) !== false ) {
				$stock = 'in_stock';
			} elseif ( strpos( $avail, 'outofstock' ) !== false ) {
				$stock = 'out_of_stock';
			} elseif ( strpos( $avail, 'backorder' ) !== false ) {
				$stock = 'backorder';
			} else {
				$stock = 'unknown';
			}

			$sku = isset( $o['sku'] ) && $o['sku'] !== '' ? (string) $o['sku'] : $sku_top;

			$raw_offer = $o;
			unset( $raw_offer['price'], $raw_offer['priceCurrency'], $raw_offer['availability'], $raw_offer['sku'] );
			$raw = array(
				'jsonld_category' => $category,
				'jsonld_offer'    => $raw_offer,
			);

			$offer = new Supcomp_Extractor_Offer();
			$offer->export_run_id              = $run_id;
			$offer->exported_at                = $exported_at;
			$offer->source                     = 'generic';
			$offer->site                       = $site;
			$offer->store_name                 = $store_name;
			$offer->source_product_id          = $sku !== '' ? $sku : $url;
			$offer->product_title              = $name;
			$offer->brand                      = $brand;
			$offer->product_type               = $category;
			$offer->sku                        = $sku;
			$offer->barcode                    = $gtin !== '' ? (string) $gtin : '';
			$offer->regular_price              = $price;
			$offer->current_price              = $price;
			$offer->on_sale                    = 'false';
			$offer->currency                   = $currency;
			$offer->price_source               = 'jsonld';
			$offer->stock_status               = $stock;
			$offer->source_product_url         = $url;
			$offer->variation_retrieval_status = 'not_applicable';
			$offer->description                = $description;
			$offer->raw_attributes_json        = self::json_compact( $raw );
			$out[] = $offer;
		}

		return $out;
	}

	// ---------- helpers ----------

	private static function load_html_dom( $html ) {
		if ( ! is_string( $html ) || $html === '' ) {
			return null;
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		// Force UTF-8 by prepending a meta charset; DOMDocument's HTML
		// parser otherwise assumes ISO-8859-1 and mangles non-ASCII chars.
		$wrapped = '<?xml encoding="utf-8"?>' . $html;
		$loaded  = $dom->loadHTML( $wrapped, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		return $loaded ? $dom : null;
	}

	/**
	 * JSON-LD @type can be a string or a list of strings. Mirrors
	 * jsonld_type_matches in Python.
	 */
	private static function type_matches( $node_type, $target ) {
		if ( is_string( $node_type ) ) {
			return $node_type === $target;
		}
		if ( is_array( $node_type ) ) {
			foreach ( $node_type as $t ) {
				if ( is_string( $t ) && $t === $target ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * JSON-LD values can be strings, dicts (resolve to name/@id/url), or
	 * lists. Mirrors stringify() in Python.
	 */
	private static function stringify( $value ) {
		if ( $value === null ) {
			return '';
		}
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			if ( self::is_list( $value ) ) {
				$parts = array();
				foreach ( $value as $v ) {
					$s = self::stringify( $v );
					if ( $s !== '' ) {
						$parts[] = $s;
					}
				}
				return implode( ', ', $parts );
			}
			return (string) ( $value['name'] ?? $value['@id'] ?? $value['url'] ?? '' );
		}
		return (string) $value;
	}

	private static function is_list( $value ) {
		if ( ! is_array( $value ) || empty( $value ) ) {
			return is_array( $value ) && empty( $value );
		}
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	private static function matches_product_hint( $url ) {
		foreach ( self::PRODUCT_PATH_HINTS as $hint ) {
			if ( strpos( $url, $hint ) !== false ) {
				return true;
			}
		}
		return false;
	}

	private static function first_nonempty( array $candidates ) {
		foreach ( $candidates as $c ) {
			if ( $c !== null && $c !== '' ) {
				return $c;
			}
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
}
