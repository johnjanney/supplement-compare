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

	/**
	 * Probe-all mode only (crawl_all_sitemap_urls): path substrings that are
	 * almost certainly NOT product pages. This is a fetch-saver, not a
	 * correctness gate — anything that slips through is still self-classified
	 * by fetch_chunk(), which yields zero rows for a page with no Product
	 * JSON-LD. Kept conservative so a real product slug is never excluded.
	 */
	const PROBE_EXCLUDE_SUBSTRINGS = array(
		'/blog', '/learn', '/news', '/article',
		'/about', '/contact', '/faq', '/privacy', '/terms',
		'/refund', '/shipping', '/policy', '/policies',
		'/cart', '/checkout', '/account', '/login', '/register', '/search',
	);

	const SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

	// Max sitemap-index recursion depth. A store's product sitemaps sit one
	// level under the index, so 2 is ample; the cap stops a malicious
	// sitemap-index chain from amplifying into unbounded fetches.
	const SITEMAP_MAX_DEPTH = 2;

	/**
	 * Weak / generic store names returned by various platforms' defaults
	 * (lowercased). When the homepage yields one of these we treat it as
	 * "not really set" and fall back to a product page's seller/brand name.
	 * Wix homepages return "My Site"; a fresh WordPress returns the others.
	 * Mirrors _GENERIC_STORE_NAMES in aggregate_products.py.
	 */
	const GENERIC_STORE_NAMES = array(
		'', 'my site', 'my wordpress site', 'just another wordpress site',
	);

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
	 *
	 * @param string $site      Site base URL.
	 * @param bool   $probe_all When true (the per-site "crawl all sitemap
	 *                          URLs" option), every sitemap URL is treated as
	 *                          a candidate product page rather than filtering
	 *                          by PRODUCT_PATH_HINTS. This is the path for
	 *                          headless / flat-slug storefronts (e.g. a
	 *                          Next.js site whose products live at top-level
	 *                          slugs like /adamax with no /product/ prefix).
	 *                          fetch_chunk() self-classifies — a page with no
	 *                          Product JSON-LD simply yields no rows — so the
	 *                          only cost is extra fetches, spread across the
	 *                          chunked Action Scheduler pages.
	 */
	public static function discover_product_urls( $site, $probe_all = false ) {
		$seen      = array();
		$base_host = (string) wp_parse_url( $site, PHP_URL_HOST );
		$home      = rtrim( $site, '/' );
		foreach ( self::SITEMAP_CANDIDATES as $path ) {
			$url      = rtrim( $site, '/' ) . $path;
			$response = Supcomp_Extractor_Http::get( $url );
			if ( is_wp_error( $response ) || $response['status'] !== 200 ) {
				continue;
			}
			if ( strpos( $response['body'], '<' ) === false ) {
				continue;
			}
			$trust_all = ( $probe_all || strpos( $path, 'product' ) !== false );
			$urls = self::parse_sitemap( $response['body'], $trust_all, $base_host, 0 );
			foreach ( $urls as $u ) {
				$keep = $probe_all
					? ( $u !== $home && $u !== $home . '/' && ! self::is_probe_excluded( $u ) )
					: ( $trust_all || self::matches_product_hint( $u ) );
				if ( $keep ) {
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
		if ( $probe_all && count( $seen ) >= self::URL_DISCOVERY_CAP ) {
			// Not silent: a truncated crawl reads as "covered everything" when
			// it didn't. The operator can split the site or raise the cap.
			error_log( sprintf(
				'[supplement-compare] Generic crawl-all discovery hit the %d-URL cap for %s; later sitemap URLs were not crawled.',
				self::URL_DISCOVERY_CAP,
				$site
			) );
		}
		return array_slice( array_keys( $seen ), 0, self::URL_DISCOVERY_CAP );
	}

	/**
	 * Probe-all helper: true if a URL's path looks like a non-product page
	 * (blog, policy, cart, etc.) per PROBE_EXCLUDE_SUBSTRINGS, or is the bare
	 * homepage. Conservative by design — see PROBE_EXCLUDE_SUBSTRINGS.
	 */
	private static function is_probe_excluded( $url ) {
		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( $path === '' || $path === '/' ) {
			return true;
		}
		foreach ( self::PROBE_EXCLUDE_SUBSTRINGS as $needle ) {
			if ( strpos( $path, $needle ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Recursively parse a sitemap XML body. Sitemap-index entries whose
	 * loc contains "product" or "shop" get fetched recursively with
	 * trust_all=true (their child sitemaps are known-product). Other
	 * sitemap-index entries are skipped.
	 *
	 * @return string[]
	 */
	private static function parse_sitemap( $xml_text, $trust_all = false, $base_host = '', $depth = 0 ) {
		$urls = array();
		libxml_use_internal_errors( true );
		// LIBXML_NONET blocks the parser from fetching external entities/DTDs
		// over the network while reading an untrusted merchant sitemap.
		$root = simplexml_load_string( $xml_text, 'SimpleXMLElement', LIBXML_NONET );
		libxml_clear_errors();
		if ( $root === false ) {
			return $urls;
		}
		$root->registerXPathNamespace( 'sm', self::SITEMAP_NS );

		// Sitemap-index: recurse into child sitemaps whose loc contains
		// "product" or "shop". Other child sitemaps are ignored.
		//
		// SSRF / amplification guard: the <loc> values are merchant-controlled,
		// so (1) cap recursion depth, and (2) only follow a child sitemap on the
		// same host (or a subdomain of it) as the configured site. The internal-
		// network case is already blocked by Supcomp_Extractor_Http::is_safe_url();
		// this additionally stops a sitemap from steering us to an arbitrary
		// third-party host or into an unbounded sitemap-index loop.
		$child_sitemaps = $depth < self::SITEMAP_MAX_DEPTH ? $root->xpath( 'sm:sitemap/sm:loc' ) : array();
		if ( $child_sitemaps ) {
			foreach ( $child_sitemaps as $loc ) {
				$loc_str = trim( (string) $loc );
				if ( $loc_str === '' || ( strpos( $loc_str, 'product' ) === false && strpos( $loc_str, 'shop' ) === false ) ) {
					continue;
				}
				if ( $base_host !== '' && ! self::host_matches( $loc_str, $base_host ) ) {
					continue;
				}
				$response = Supcomp_Extractor_Http::get( $loc_str );
				if ( ! is_wp_error( $response ) && $response['status'] === 200 ) {
					$urls = array_merge( $urls, self::parse_sitemap( $response['body'], true, $base_host, $depth + 1 ) );
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
	 *
	 * When the homepage yields a generic platform default (Wix's "My Site",
	 * a stock WordPress title, etc.) and a $fallback_product_url is given,
	 * additionally probe that product page's JSON-LD for an offer.seller /
	 * brand name. Mirrors fetch_generic_store_name in aggregate_products.py.
	 *
	 * @param string      $site                 Site base URL.
	 * @param string|null $fallback_product_url A product URL to mine for a
	 *                                          seller/brand name when the
	 *                                          homepage name is generic.
	 */
	public static function fetch_store_meta( $site, $fallback_product_url = null ) {
		$name     = '';
		$response = Supcomp_Extractor_Http::get( rtrim( $site, '/' ) . '/' );
		if ( ! is_wp_error( $response ) && $response['status'] === 200 ) {
			$name = self::store_name_from_html( $response['body'] );
		}

		if ( $fallback_product_url && in_array( strtolower( $name ), self::GENERIC_STORE_NAMES, true ) ) {
			$seller = self::store_name_from_product_page( $fallback_product_url );
			if ( $seller !== '' ) {
				$name = $seller;
			}
		}

		return array( 'store_name' => $name, 'currency' => '' );
	}

	/**
	 * Pull a store name from a homepage's HTML:
	 *   og:site_name → JSON-LD Organization/WebSite name → <title>.
	 * Returns '' if none found. Mirrors _store_name_from_html.
	 */
	private static function store_name_from_html( $html ) {
		$dom = self::load_html_dom( $html );
		if ( $dom === null ) {
			return '';
		}
		$xpath = new DOMXPath( $dom );

		// og:site_name
		$nodes = $xpath->query( '//meta[@property="og:site_name"]/@content' );
		if ( $nodes && $nodes->length > 0 ) {
			$val = trim( $nodes->item( 0 )->nodeValue );
			if ( $val !== '' ) {
				return $val;
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
					return $name;
				}
			}
		}

		// <title>
		$title_nodes = $xpath->query( '//title' );
		if ( $title_nodes && $title_nodes->length > 0 ) {
			$title = trim( $title_nodes->item( 0 )->textContent );
			if ( $title !== '' ) {
				return $title;
			}
		}

		return '';
	}

	/**
	 * Probe a single product page's JSON-LD for a seller or brand name.
	 * Used as a fallback when the homepage store name is a generic default.
	 * Mirrors _store_name_from_product_page. Returns '' on any miss.
	 */
	private static function store_name_from_product_page( $url ) {
		$response = Supcomp_Extractor_Http::get( $url );
		if ( is_wp_error( $response ) || $response['status'] !== 200 ) {
			return '';
		}
		foreach ( self::extract_jsonld_products( $response['body'] ) as $item ) {
			// offer.seller.name (case-insensitive: Wix may emit "Offers"/"Seller").
			$offers_data = self::ci_get( $item, array( 'offers' ) );
			$candidates  = array();
			if ( is_array( $offers_data ) && self::is_list( $offers_data ) ) {
				foreach ( $offers_data as $x ) {
					if ( is_array( $x ) ) {
						$candidates[] = $x;
					}
				}
			} elseif ( is_array( $offers_data ) ) {
				$candidates[] = $offers_data;
			}
			foreach ( $candidates as $o ) {
				$seller = self::ci_get( $o, array( 'seller' ) );
				if ( is_array( $seller ) && isset( $seller['name'] ) && $seller['name'] !== '' ) {
					return trim( (string) $seller['name'] );
				}
			}
			// product.brand.name as a secondary signal.
			$brand = $item['brand'] ?? null;
			if ( is_array( $brand ) && isset( $brand['name'] ) && $brand['name'] !== '' ) {
				return trim( (string) $brand['name'] );
			}
		}
		return '';
	}

	/**
	 * Fetch + parse a slice of product URLs. Each yields zero or more
	 * Offer rows (one per JSON-LD Offer/AggregateOffer encountered).
	 *
	 * @param string[] $url_slice
	 * @param string   $source_label Value stamped on each row's `source`
	 *                               column ('generic' by default; 'wix' when
	 *                               the operator pinned the Wix platform).
	 * @return array{rows:array, batch_size:int, status:string, http_status:int}
	 */
	public static function fetch_chunk( $site, array $url_slice, $run_id, $exported_at, $store_name, $source_label = 'generic' ) {
		$rows = array();
		foreach ( $url_slice as $url ) {
			$response = Supcomp_Extractor_Http::get( $url );
			if ( is_wp_error( $response ) || $response['status'] !== 200 ) {
				continue;
			}
			foreach ( self::extract_jsonld_products( $response['body'] ) as $product ) {
				foreach ( self::jsonld_to_offers( $product, $site, $store_name, $url, $run_id, $exported_at, $source_label ) as $offer ) {
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
	private static function jsonld_to_offers( $item, $site, $store_name, $url, $run_id, $exported_at, $source_label = 'generic' ) {
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

		// Wix emits non-standard capitalization ("Offers", "Availability",
		// etc.), so all offer-shaped lookups go through ci_get().
		$offers_data = self::ci_get( $item, array( 'offers' ) );
		if ( is_array( $offers_data ) && ! self::is_list( $offers_data ) ) {
			$inner = self::ci_get( $offers_data, array( 'offers' ) );
			if ( isset( $offers_data['@type'] ) && self::type_matches( $offers_data['@type'], 'AggregateOffer' ) && self::is_list( $inner ) ) {
				$offer_list = $inner;
			} else {
				$offer_list = array( $offers_data );
			}
		} elseif ( is_array( $offers_data ) ) {
			$offer_list = $offers_data;
		} else {
			$offer_list = array( array() );
		}

		$out = array();
		foreach ( $offer_list as $o ) {
			if ( ! is_array( $o ) ) {
				continue;
			}
			$price_val = self::ci_get( $o, array( 'price', 'lowPrice' ) );
			$price     = $price_val === null ? '' : (string) $price_val;
			$currency  = (string) self::ci_get( $o, array( 'priceCurrency' ) );
			$avail     = strtolower( (string) self::ci_get( $o, array( 'availability' ) ) );
			if ( strpos( $avail, 'instock' ) !== false ) {
				$stock = 'in_stock';
			} elseif ( strpos( $avail, 'outofstock' ) !== false ) {
				$stock = 'out_of_stock';
			} elseif ( strpos( $avail, 'backorder' ) !== false ) {
				$stock = 'backorder';
			} else {
				$stock = 'unknown';
			}

			$sku_val = self::ci_get( $o, array( 'sku' ) );
			$sku     = ( $sku_val !== null && $sku_val !== '' ) ? (string) $sku_val : $sku_top;

			// Strip the fields we promoted to columns (case-insensitively, so a
			// Wix "Availability" key doesn't survive into the raw blob twice).
			$skip      = array( 'price', 'pricecurrency', 'availability', 'sku' );
			$raw_offer = array();
			foreach ( $o as $k => $v ) {
				if ( is_string( $k ) && in_array( strtolower( $k ), $skip, true ) ) {
					continue;
				}
				$raw_offer[ $k ] = $v;
			}
			$raw = array(
				'jsonld_category' => $category,
				'jsonld_offer'    => $raw_offer,
			);

			$offer = new Supcomp_Extractor_Offer();
			$offer->export_run_id              = $run_id;
			$offer->exported_at                = $exported_at;
			$offer->source                     = $source_label;
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

	/**
	 * Case-insensitive lookup over a JSON-LD node. Returns the first value
	 * whose key matches any of $keys (compared lowercased), else $default.
	 * JSON-LD is case-sensitive per spec, but some generators — notably Wix —
	 * emit "Offers"/"Availability"/etc. Mirrors _ci_get in
	 * aggregate_products.py.
	 *
	 * @param mixed    $arr     Expected to be an associative array; anything
	 *                          else returns $default.
	 * @param string[] $keys    Candidate keys, in priority order.
	 * @param mixed    $default Returned when nothing matches.
	 * @return mixed
	 */
	private static function ci_get( $arr, array $keys, $default = null ) {
		if ( ! is_array( $arr ) ) {
			return $default;
		}
		$lower_map = array();
		foreach ( $arr as $k => $v ) {
			if ( is_string( $k ) ) {
				$lower_map[ strtolower( $k ) ] = $k;
			}
		}
		foreach ( $keys as $key ) {
			$lk = strtolower( $key );
			if ( array_key_exists( $lk, $lower_map ) ) {
				return $arr[ $lower_map[ $lk ] ];
			}
		}
		return $default;
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

	/**
	 * True when $url's host is the configured $base_host or a subdomain of it.
	 * Used to keep recursive sitemap fetches on the merchant's own domain
	 * (their product sitemaps always are) rather than following a <loc> to an
	 * arbitrary third-party host.
	 */
	private static function host_matches( $url, $base_host ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$base = strtolower( (string) $base_host );
		if ( $host === '' || $base === '' ) {
			return false;
		}
		return $host === $base || substr( $host, -strlen( '.' . $base ) ) === '.' . $base;
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
