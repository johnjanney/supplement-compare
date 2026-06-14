<?php
/**
 * Shared HTTP client for the in-plugin extractor. Mirrors the semantics of
 * the Python `get()` helper at extractor/aggregate_products.py:123-164:
 *
 *   - Retries on network errors and statuses {408, 429, 500, 502, 503, 504}.
 *   - Exponential backoff: 2 ^ attempt seconds before each retry.
 *   - Honors Retry-After on 429, capped at 30s so a hostile server cannot
 *     stall a chunk past the PHP execution-time budget.
 *   - 0.5s politeness delay after every successful (2xx) response.
 *
 * Non-retryable 4xx responses (other than 408 and 429) come back to the
 * caller as-is so per-platform handlers can branch on them (e.g. a 404 on
 * /products.json signals "this isn't a Shopify site, try the next handler").
 *
 * Uses wp_remote_get / wp_remote_request directly — no connection pooling,
 * because pooling is not available without bringing in Guzzle/cURL multi
 * handles, and the 0.5s politeness floor dominates the wall-clock anyway.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Extractor_Http {

	const MAX_RETRIES              = 2;
	const RETRY_AFTER_CAP_SECONDS  = 30;
	const POLITENESS_DELAY_SECONDS = 0.5;
	const DEFAULT_TIMEOUT          = 20;

	/**
	 * Fallback User-Agent used for a one-shot retry when a request is refused
	 * with 403 — typically a Cloudflare/WAF rule that blocks our honest
	 * crawler UA but lets a normal browser through (e.g. example-chems.is). We
	 * try the honest UA first and only fall back on an outright block.
	 */
	const BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

	/**
	 * Hard cap on the response body we will buffer into memory before the
	 * transport aborts the transfer. Bounds a memory-exhaustion DoS from a
	 * hostile merchant endpoint (a paginated products.json page or a single
	 * sitemap is well under this; a 50k-URL sitemap is only a few MB).
	 * Filterable so an operator with an unusually large feed can raise it.
	 */
	const MAX_RESPONSE_BYTES = 33554432; // 32 * 1024 * 1024

	private static $retryable_statuses = array( 408, 429, 500, 502, 503, 504 );

	/**
	 * Cookie header value sent with every request for the current attempt
	 * (e.g. an age-gate bypass cookie configured on the site). Set per-attempt
	 * by the worker and cleared when the attempt ends, so it never leaks across
	 * sites sharing a queue-runner process.
	 */
	private static $request_cookies = '';

	/**
	 * Set (or clear, with '') the Cookie header for subsequent get() calls.
	 */
	public static function set_request_cookies( $cookies ) {
		self::$request_cookies = trim( (string) $cookies );
	}

	/**
	 * SSRF guard. Returns true when the URL is safe to fetch server-side, or a
	 * WP_Error describing why not. Two checks:
	 *
	 *   1. Scheme must be http or https (no file://, ftp://, gopher://, etc.).
	 *   2. The host must not resolve to a private, loopback, reserved, or
	 *      link-local address — this is what blocks the cloud metadata endpoint
	 *      (169.254.169.254) and internal services. We resolve the host
	 *      ourselves because wp_http_validate_url() only rejects literal
	 *      private IPs, not hostnames that *resolve* to one.
	 *
	 * Called at the single fetch chokepoint in get(), so every outbound
	 * request — initial sitemap, recursive sitemap <loc>, product pages — is
	 * covered in one place.
	 *
	 * Residual gap: a host could pass this check and then DNS-rebind to a
	 * private IP for the actual connection (or on a redirect hop). Closing that
	 * fully requires pinning the resolved IP into the request (cURL
	 * CURLOPT_RESOLVE); wp_safe_remote_get() + this pre-check is the
	 * proportionate mitigation for a self-hosted WordPress.
	 *
	 * @param string $url
	 * @return true|WP_Error
	 */
	public static function is_safe_url( $url ) {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'supcomp_bad_scheme', __( 'Only http and https URLs may be fetched.', 'supplement-compare' ) );
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return new WP_Error( 'supcomp_bad_host', __( 'URL has no host.', 'supplement-compare' ) );
		}
		// A bare IP host is validated directly; a name is resolved first.
		$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : gethostbynamel( $host );
		if ( empty( $ips ) ) {
			return new WP_Error( 'supcomp_unresolvable', __( 'Host could not be resolved.', 'supplement-compare' ) );
		}
		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return new WP_Error( 'supcomp_private_host', __( 'Host resolves to a non-public (private/reserved) address.', 'supplement-compare' ) );
			}
		}
		return true;
	}

	/**
	 * GET a URL with retry + politeness semantics.
	 *
	 * @param string $url
	 * @param array  $args  Optional. Supported keys:
	 *                      - headers (array)
	 *                      - timeout (int seconds, default 20)
	 *                      - user_agent (string, default "Supcomp-Extractor/...")
	 *                      - max_retries (int, default 2)
	 * @return array{status:int,headers:array,body:string}|WP_Error
	 *         WP_Error on persistent network failure after retries exhausted.
	 *         Non-retryable HTTP errors are returned as an array with the
	 *         status code in place, not WP_Error.
	 */
	public static function get( $url, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'headers'     => array(),
				'timeout'     => self::DEFAULT_TIMEOUT,
				'user_agent'  => self::default_user_agent(),
				'max_retries' => self::MAX_RETRIES,
			)
		);

		// Attach the per-attempt cookie (age-gate bypass etc.) at this single
		// chokepoint so every request — sitemap, products.json, product pages —
		// carries it. An explicit Cookie header passed in $args always wins.
		if ( self::$request_cookies !== '' && ! self::has_header( $args['headers'], 'cookie' ) ) {
			$args['headers']['Cookie'] = self::$request_cookies;
		}

		// SSRF guard: reject non-http(s) and hosts that resolve to a
		// private/reserved address before we make any request.
		$safe = self::is_safe_url( $url );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		$last_error       = null;
		$max              = max( 0, (int) $args['max_retries'] );
		$ua               = (string) $args['user_agent'];
		$browser_ua_tried = false;
		$skip_backoff     = false;

		for ( $attempt = 0; $attempt <= $max; $attempt++ ) {
			if ( $attempt > 0 && ! $skip_backoff ) {
				$delay = (int) pow( 2, $attempt );
				if ( $last_error instanceof WP_Error === false && isset( $last_error['retry_after'] ) ) {
					$delay = max( $delay, (int) $last_error['retry_after'] );
				}
				$delay = min( $delay, self::RETRY_AFTER_CAP_SECONDS );
				if ( $delay > 0 ) {
					sleep( $delay );
				}
			}
			$skip_backoff = false;

			// wp_safe_remote_get() sets reject_unsafe_urls => true, which runs
			// WP's own wp_http_validate_url() on the request URL and redirect
			// targets. We keep redirection => 5 so merchants that legitimately
			// redirect (http→https, CDN, locale) still resolve; the is_safe_url()
			// pre-check above plus reject_unsafe_urls cover the SSRF surface.
			// limit_response_size aborts an oversized body before it exhausts
			// memory.
			$response = wp_safe_remote_get(
				$url,
				array(
					'headers'             => $args['headers'],
					'timeout'             => (int) $args['timeout'],
					'user-agent'          => $ua,
					'redirection'         => 5,
					'limit_response_size' => (int) apply_filters( 'supcomp_extractor_max_response_bytes', self::MAX_RESPONSE_BYTES ),
				)
			);

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );

			// One-shot User-Agent upgrade on a bot-block 403: many WAFs refuse
			// our honest crawler UA but allow a browser. Retry immediately with
			// a browser UA (no backoff, doesn't consume a retry-budget slot).
			if ( $status === 403 && ! $browser_ua_tried && $ua !== self::BROWSER_USER_AGENT ) {
				$browser_ua_tried = true;
				$ua               = self::BROWSER_USER_AGENT;
				$skip_backoff     = true;
				--$attempt;
				continue;
			}

			if ( in_array( $status, self::$retryable_statuses, true ) ) {
				$retry_after_header = wp_remote_retrieve_header( $response, 'retry-after' );
				$retry_after        = self::parse_retry_after( $retry_after_header );
				$last_error         = array(
					'status'      => $status,
					'retry_after' => $retry_after,
				);
				continue;
			}

			// Success or non-retryable error — return the response as-is.
			if ( $status >= 200 && $status < 300 ) {
				self::politeness_sleep();
			}

			return array(
				'status'  => $status,
				'headers' => self::headers_to_array( wp_remote_retrieve_headers( $response ) ),
				'body'    => wp_remote_retrieve_body( $response ),
			);
		}

		if ( $last_error instanceof WP_Error ) {
			return $last_error;
		}
		if ( is_array( $last_error ) && isset( $last_error['status'] ) ) {
			return new WP_Error(
				'supcomp_http_retries_exhausted',
				sprintf( 'Retries exhausted; last status %d', $last_error['status'] ),
				$last_error
			);
		}
		return new WP_Error( 'supcomp_http_failed', 'Request failed with no further detail.' );
	}

	/**
	 * Convenience: GET a URL expecting JSON. Returns the decoded payload on
	 * 2xx with a JSON body, or null on any other outcome (so handlers can
	 * branch cleanly on "didn't get JSON back").
	 */
	public static function get_json( $url, array $args = array() ) {
		$response = self::get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			return null;
		}
		$decoded = json_decode( $response['body'], true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private static function politeness_sleep() {
		$us = (int) round( self::POLITENESS_DELAY_SECONDS * 1000000 );
		if ( $us > 0 ) {
			usleep( $us );
		}
	}

	/**
	 * Retry-After can be either a delta-seconds integer or an HTTP-date.
	 * We support delta-seconds — HTTP-date is rare in practice and adds
	 * timezone fragility we don't need.
	 */
	private static function parse_retry_after( $value ) {
		if ( $value === null || $value === '' ) {
			return 0;
		}
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		if ( ctype_digit( (string) $value ) ) {
			return min( (int) $value, self::RETRY_AFTER_CAP_SECONDS );
		}
		$ts = strtotime( (string) $value );
		if ( $ts === false ) {
			return 0;
		}
		$delta = $ts - time();
		return max( 0, min( $delta, self::RETRY_AFTER_CAP_SECONDS ) );
	}

	private static function has_header( array $headers, $name ) {
		foreach ( array_keys( $headers ) as $key ) {
			if ( strcasecmp( (string) $key, (string) $name ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	private static function headers_to_array( $headers ) {
		if ( is_array( $headers ) ) {
			return $headers;
		}
		// WP's Requests_Utility_CaseInsensitiveDictionary is iterable.
		$out = array();
		foreach ( $headers as $name => $value ) {
			$out[ strtolower( (string) $name ) ] = $value;
		}
		return $out;
	}

	private static function default_user_agent() {
		$version = defined( 'SUPPLEMENT_COMPARE_VERSION' ) ? SUPPLEMENT_COMPARE_VERSION : 'dev';
		return 'Supcomp-Extractor/' . $version . ' (+WordPress)';
	}
}
