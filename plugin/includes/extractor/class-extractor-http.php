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

	private static $retryable_statuses = array( 408, 429, 500, 502, 503, 504 );

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

		$last_error = null;
		$max        = max( 0, (int) $args['max_retries'] );

		for ( $attempt = 0; $attempt <= $max; $attempt++ ) {
			if ( $attempt > 0 ) {
				$delay = (int) pow( 2, $attempt );
				if ( $last_error instanceof WP_Error === false && isset( $last_error['retry_after'] ) ) {
					$delay = max( $delay, (int) $last_error['retry_after'] );
				}
				$delay = min( $delay, self::RETRY_AFTER_CAP_SECONDS );
				if ( $delay > 0 ) {
					sleep( $delay );
				}
			}

			$response = wp_remote_get(
				$url,
				array(
					'headers'    => $args['headers'],
					'timeout'    => (int) $args['timeout'],
					'user-agent' => (string) $args['user_agent'],
					'redirection'=> 5,
				)
			);

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );

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
