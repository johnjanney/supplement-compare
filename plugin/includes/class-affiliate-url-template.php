<?php
/**
 * Affiliate URL template engine — PROJECTBRIEF.md §5.
 *
 * Templates contain literal text plus zero or more placeholders. The four
 * supported placeholders are:
 *
 *   {product_url}             — the source product URL verbatim
 *   {url_encoded_product_url} — rawurlencode( source product URL )
 *   {path}                    — path portion of the URL, no scheme/host/query
 *   {handle}                  — product slug, supplied via context or guessed
 *                               from the product URL's /products/<handle> segment
 *
 * After substitution, this engine applies one quirk from §5: if the template
 * is "{product_url}?..." and the substituted URL already contained a "?",
 * the appended "?" is flipped to "&" so the result has a single, valid
 * query-string separator.
 *
 * Edge cases deliberately not handled (operator-driven curation will catch):
 *   - URL fragments (#) in the source URL collide with appended query strings.
 *   - Operator using {product_url} inside a query value when they meant
 *     {url_encoded_product_url} (the preview will reveal the broken URL).
 *
 * This class is used both by the admin merchant-edit preview (Phase 3) and
 * by the click-out redirect handler (Phase 7). One source of truth.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Affiliate_URL_Template {

	const KNOWN_VARS = array( 'product_url', 'url_encoded_product_url', 'path', 'handle' );

	/**
	 * Apply a template to a product URL.
	 *
	 * @param string $template
	 * @param string $product_url
	 * @param array  $context     Optional. Recognized keys: 'handle'.
	 * @return string|WP_Error    Generated affiliate URL, or WP_Error.
	 */
	public static function apply( $template, $product_url, $context = array() ) {
		if ( ! is_string( $template ) || trim( $template ) === '' ) {
			return new WP_Error( 'supcomp_empty_template', __( 'Template is empty.', 'supplement-compare' ) );
		}
		if ( ! is_string( $product_url ) || trim( $product_url ) === '' ) {
			return new WP_Error( 'supcomp_empty_url', __( 'Product URL is empty.', 'supplement-compare' ) );
		}

		$parsed = wp_parse_url( $product_url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return new WP_Error( 'supcomp_bad_url', __( 'Product URL is malformed.', 'supplement-compare' ) );
		}

		$path = isset( $parsed['path'] ) ? $parsed['path'] : '';

		$handle = '';
		if ( isset( $context['handle'] ) && $context['handle'] !== '' ) {
			$handle = (string) $context['handle'];
		} else {
			$handle = self::guess_handle_from_path( $path );
		}

		$vars = array(
			'{product_url}'             => $product_url,
			'{url_encoded_product_url}' => rawurlencode( $product_url ),
			'{path}'                    => $path,
			'{handle}'                  => $handle,
		);

		$result = strtr( $template, $vars );
		$result = self::fix_query_separator( $template, $product_url, $result );

		return $result;
	}

	/**
	 * Quick structural check: must be a non-empty string, must begin with an
	 * http(s):// scheme (or with the {product_url} placeholder, which is itself
	 * a validated http(s) URL), and must not contain unknown {placeholder}
	 * tokens. Returns true or WP_Error.
	 */
	public static function validate( $template ) {
		if ( ! is_string( $template ) || trim( $template ) === '' ) {
			return new WP_Error( 'supcomp_empty_template', __( 'Template is required.', 'supplement-compare' ) );
		}
		// Scheme guard: the literal text before the first {placeholder} must
		// start with http:// or https://. A template that *starts* with a
		// placeholder (e.g. "{product_url}?aff=1") is allowed because the
		// substituted URL supplies the scheme. This blocks "javascript:…",
		// "data:…", and protocol-relative "//host" templates at authoring time.
		$literal_prefix = strstr( $template, '{', true );
		if ( false === $literal_prefix ) {
			$literal_prefix = $template;
		}
		if ( '' !== trim( $literal_prefix ) && ! preg_match( '#^\s*https?://#i', $literal_prefix ) ) {
			return new WP_Error( 'supcomp_bad_template_scheme', __( 'Template must begin with http:// or https:// (or with the {product_url} placeholder).', 'supplement-compare' ) );
		}
		preg_match_all( '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $template, $matches );
		$used    = array_unique( $matches[1] );
		$unknown = array_diff( $used, self::KNOWN_VARS );
		if ( ! empty( $unknown ) ) {
			$formatted = array_map(
				static function ( $v ) {
					return '{' . $v . '}';
				},
				array_values( $unknown )
			);
			return new WP_Error(
				'supcomp_unknown_vars',
				sprintf(
					/* translators: %s is a comma-separated list of placeholder names */
					__( 'Unknown template variable(s): %s', 'supplement-compare' ),
					implode( ', ', $formatted )
				)
			);
		}
		return true;
	}

	/**
	 * If the template appends "?..." right after {product_url} and the
	 * substituted product URL already had a query string, flip that "?" to "&".
	 */
	private static function fix_query_separator( $template, $product_url, $result ) {
		if ( strpos( $template, '{product_url}' ) === false ) {
			return $result;
		}
		if ( strpos( $product_url, '?' ) === false ) {
			return $result;
		}
		$pos = strpos( $result, $product_url );
		if ( $pos === false ) {
			return $result;
		}
		$end = $pos + strlen( $product_url );
		if ( isset( $result[ $end ] ) && $result[ $end ] === '?' ) {
			$result = substr( $result, 0, $end ) . '&' . substr( $result, $end + 1 );
		}
		return $result;
	}

	/**
	 * Best-effort handle extraction. Looks for /products/<slug> first
	 * (Shopify / Woo standard), then falls back to the last non-empty path
	 * segment. Returns '' if nothing reasonable is found.
	 */
	private static function guess_handle_from_path( $path ) {
		if ( ! is_string( $path ) || $path === '' ) {
			return '';
		}
		if ( preg_match( '~/products/([^/?#]+)~', $path, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '~/product/([^/?#]+)~', $path, $m ) ) {
			return $m[1];
		}
		$segments = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
		return $segments ? end( $segments ) : '';
	}
}
