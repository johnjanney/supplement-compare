<?php
/**
 * Click-out redirect (PROJECTBRIEF.md §8 Phase 7).
 *
 * Registers a rewrite rule for /out/{offer_id} and a query var. On
 * template_redirect, if the query var is set:
 *
 *   1. Look up the offer (joined with merchant + canonical_product).
 *   2. Bot detection: UA pattern check OR rapid-fire from same ip_hash.
 *   3. Hash IP and User-Agent with a site-stable salt; raw values are
 *      never stored.
 *   4. Capture utm_source / utm_medium / utm_campaign and the Referer.
 *   5. Insert a click_log row (bot rows are stored too; aggregations
 *      filter them out by default).
 *   6. Resolve the affiliate URL via Supcomp_Affiliate_URL_Template;
 *      fall back to the source_product_url when the merchant has no
 *      template (or template is invalid).
 *   7. wp_safe_redirect 302; exit.
 *
 * Visibility status of the offer is intentionally NOT a click-time gate.
 * If a site visitor has an old URL pointing to a paused or stale offer,
 * we still honor the click — the merchant gets the visit, the operator
 * sees it logged. Only "rejected" and missing offers return 404.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Redirect {

	const QUERY_VAR = 'supcomp_out';

	const BOT_UA_PATTERN = '~(?:bot|crawler|spider|scraper|wget|curl|libwww|python-?(?:requests|urllib)|node-fetch|axios|java/|go-http-client|headless|phantom|puppeteer|playwright|googlebot|bingbot|slurp|duckduckbot|baiduspider|yandex|facebookexternalhit|twitterbot|linkedinbot|whatsapp|telegrambot|preview)~i';

	public static function register_rewrite_rules() {
		add_rewrite_rule(
			'^out/([0-9]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	public static function add_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function maybe_handle() {
		$offer_id = (int) get_query_var( self::QUERY_VAR );
		if ( $offer_id <= 0 ) {
			return;
		}

		$offer = Supcomp_Offers_Repo::get_with_joins( $offer_id );
		if ( ! $offer ) {
			status_header( 404 );
			nocache_headers();
			wp_die( esc_html__( 'Offer not found.', 'supplement-compare' ), '', array( 'response' => 404 ) );
		}

		if ( $offer->visibility_status === 'rejected' ) {
			status_header( 410 );
			nocache_headers();
			wp_die( esc_html__( 'This offer is no longer available.', 'supplement-compare' ), '', array( 'response' => 410 ) );
		}

		// Hash IP and UA before bot detection so rapid-fire lookup uses the
		// same key as the eventual insert.
		$ip      = self::client_ip();
		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		$ip_hash = self::hash( $ip );
		$ua_hash = self::hash( $ua );

		$bot_suspected = self::is_bot_ua( $ua ) || Supcomp_Clicks_Repo::is_rapid_fire( $ip_hash );

		// Capture context.
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
		$utm = array(
			'source'   => isset( $_GET['utm_source'] )   ? sanitize_text_field( wp_unslash( $_GET['utm_source'] ) )   : '',
			'medium'   => isset( $_GET['utm_medium'] )   ? sanitize_text_field( wp_unslash( $_GET['utm_medium'] ) )   : '',
			'campaign' => isset( $_GET['utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_campaign'] ) ) : '',
		);

		Supcomp_Clicks_Repo::record_click(
			array(
				'offer_id'             => (int) $offer->id,
				'canonical_product_id' => $offer->canonical_product_id ? (int) $offer->canonical_product_id : null,
				'merchant_id'          => $offer->merchant_id ? (int) $offer->merchant_id : null,
				'ip_hash'              => $ip_hash,
				'user_agent_hash'      => $ua_hash,
				'referrer'             => $referrer !== '' ? esc_url_raw( $referrer ) : null,
				'utm_source'           => $utm['source']   !== '' ? $utm['source']   : null,
				'utm_medium'           => $utm['medium']   !== '' ? $utm['medium']   : null,
				'utm_campaign'         => $utm['campaign'] !== '' ? $utm['campaign'] : null,
				'is_bot_suspected'     => $bot_suspected ? 1 : 0,
			)
		);

		$destination = self::resolve_affiliate_url( $offer );
		// Only ever redirect to an http(s) URL. esc_url_raw with an explicit
		// protocol allowlist drops anything else (javascript:, data:, a
		// malformed host) to '' so we 410 rather than emit a hostile Location.
		$destination = $destination ? esc_url_raw( $destination, array( 'http', 'https' ) ) : '';
		if ( ! $destination ) {
			status_header( 410 );
			nocache_headers();
			wp_die( esc_html__( 'No destination URL is available for this offer.', 'supplement-compare' ), '', array( 'response' => 410 ) );
		}

		// wp_safe_redirect restricts host allowlists by default. The affiliate
		// URL points off-site, so we use wp_redirect with status 302.
		nocache_headers();
		wp_redirect( $destination, 302, 'Supplement-Compare' );
		exit;
	}

	/**
	 * Apply the merchant's affiliate URL template to the offer's source URL.
	 * Falls back to the bare source URL when no template is configured or
	 * the template is invalid — better to lose tracking than to break the
	 * click.
	 */
	private static function resolve_affiliate_url( $offer ) {
		$source = (string) $offer->source_product_url;
		if ( $source === '' ) {
			return '';
		}

		$template = isset( $offer->merchant_affiliate_url_template ) ? (string) $offer->merchant_affiliate_url_template : '';
		if ( $template === '' ) {
			return $source;
		}

		$generated = Supcomp_Affiliate_URL_Template::apply(
			$template,
			$source,
			array( 'handle' => self::handle_from_source( $offer ) )
		);
		if ( is_wp_error( $generated ) || ! is_string( $generated ) || $generated === '' ) {
			return $source;
		}
		return $generated;
	}

	/**
	 * Best-effort handle: use the source_variant_url's last /products/<slug>
	 * segment if the source has one. The engine has its own fallback so
	 * returning '' here is fine.
	 */
	private static function handle_from_source( $offer ) {
		$url = $offer->source_variant_url ? (string) $offer->source_variant_url : (string) $offer->source_product_url;
		if ( $url === '' ) {
			return '';
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return '';
		}
		if ( preg_match( '~/products?/([^/?#]+)~', $path, $m ) ) {
			return $m[1];
		}
		return '';
	}

	public static function is_bot_ua( $ua ) {
		if ( ! is_string( $ua ) || $ua === '' ) {
			return true; // no UA at all is suspicious
		}
		return (bool) preg_match( self::BOT_UA_PATTERN, $ua );
	}

	private static function client_ip() {
		// Strip proxy chain. The leftmost X-Forwarded-For is the client. If a
		// reverse proxy is misconfigured this can be spoofed — operator's
		// server should set REMOTE_ADDR correctly.
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$xff   = (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] );
			$parts = array_map( 'trim', explode( ',', $xff ) );
			if ( ! empty( $parts[0] ) ) {
				return $parts[0];
			}
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
	}

	/**
	 * Salted SHA-256. Stable for the life of the site (wp_salt rotates only
	 * on explicit operator action) so rapid-fire detection works across
	 * requests.
	 */
	private static function hash( $value ) {
		$salt = wp_salt( 'auth' );
		return hash( 'sha256', $salt . '|' . (string) $value );
	}
}
