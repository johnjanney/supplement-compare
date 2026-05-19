<?php
/**
 * Repository for `click_log` (PROJECTBRIEF.md §3.8).
 *
 * Bot-suspect clicks still get inserted — the operator wants to see the
 * volume separately from human clicks. Aggregation methods take an
 * include_bots flag; the admin dashboard defaults to humans-only so the
 * top-offers table reflects real traffic.
 *
 * IP and User-Agent hashes are SHA-256 with a site-stable salt
 * (wp_salt('auth')). Raw IPs are never written.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Clicks_Repo {

	const RAPID_FIRE_WINDOW_SECONDS = 60;
	const RAPID_FIRE_THRESHOLD      = 10;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'supcomp_click_log';
	}

	/**
	 * Record a single click. Caller has already computed hashes / detected
	 * bot status; this method just inserts.
	 *
	 * @param array $args offer_id, canonical_product_id, merchant_id,
	 *                    ip_hash, user_agent_hash, referrer, utm_*,
	 *                    is_bot_suspected
	 * @return int insert_id
	 */
	public static function record_click( $args ) {
		global $wpdb;
		$defaults = array(
			'offer_id'             => null,
			'canonical_product_id' => null,
			'merchant_id'          => null,
			'ip_hash'              => '',
			'user_agent_hash'      => '',
			'referrer'             => null,
			'utm_source'           => null,
			'utm_medium'           => null,
			'utm_campaign'         => null,
			'is_bot_suspected'     => 0,
		);
		$args = wp_parse_args( $args, $defaults );
		$wpdb->insert(
			self::table(),
			array(
				'offer_id'             => $args['offer_id']             !== null ? (int) $args['offer_id']             : null,
				'canonical_product_id' => $args['canonical_product_id'] !== null ? (int) $args['canonical_product_id'] : null,
				'merchant_id'          => $args['merchant_id']          !== null ? (int) $args['merchant_id']          : null,
				'clicked_at'           => current_time( 'mysql', true ),
				'ip_hash'              => self::trim_to( (string) $args['ip_hash'], 64 ),
				'user_agent_hash'      => self::trim_to( (string) $args['user_agent_hash'], 64 ),
				'referrer'             => self::trim_to_nullable( $args['referrer'], 512 ),
				'utm_source'           => self::trim_to_nullable( $args['utm_source'], 128 ),
				'utm_medium'           => self::trim_to_nullable( $args['utm_medium'], 128 ),
				'utm_campaign'         => self::trim_to_nullable( $args['utm_campaign'], 128 ),
				'is_bot_suspected'     => (int) ( $args['is_bot_suspected'] ? 1 : 0 ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Rapid-fire check: more than $threshold clicks from this ip_hash in the
	 * last $window_seconds.
	 */
	public static function is_rapid_fire( $ip_hash, $window_seconds = self::RAPID_FIRE_WINDOW_SECONDS, $threshold = self::RAPID_FIRE_THRESHOLD ) {
		if ( $ip_hash === '' ) {
			return false;
		}
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $window_seconds );
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE ip_hash = %s AND clicked_at >= %s",
				$ip_hash,
				$since
			)
		);
		return $count >= (int) $threshold;
	}

	public static function count_within( $since_mysql, $include_bots = false ) {
		global $wpdb;
		$table = self::table();
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE clicked_at >= %s";
		if ( ! $include_bots ) {
			$sql .= ' AND is_bot_suspected = 0';
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $since_mysql ) );
	}

	public static function count_bots_within( $since_mysql ) {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE clicked_at >= %s AND is_bot_suspected = 1", $since_mysql )
		);
	}

	public static function top_by_offer( $since_mysql, $limit = 10, $include_bots = false ) {
		global $wpdb;
		$cl  = self::table();
		$no  = $wpdb->prefix . 'supcomp_normalized_offers';
		$m   = $wpdb->prefix . 'supcomp_merchants';
		$bot = $include_bots ? '' : 'AND cl.is_bot_suspected = 0';
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cl.offer_id, COUNT(*) AS clicks, o.product_title, o.variant_title, o.brand, m.name AS merchant_name
				 FROM {$cl} cl
				 LEFT JOIN {$no} o ON o.id = cl.offer_id
				 LEFT JOIN {$m} m ON m.id = cl.merchant_id
				 WHERE cl.clicked_at >= %s {$bot}
				 GROUP BY cl.offer_id, o.product_title, o.variant_title, o.brand, m.name
				 ORDER BY clicks DESC
				 LIMIT %d",
				$since_mysql,
				(int) $limit
			)
		);
	}

	public static function top_by_merchant( $since_mysql, $limit = 10, $include_bots = false ) {
		global $wpdb;
		$cl  = self::table();
		$m   = $wpdb->prefix . 'supcomp_merchants';
		$bot = $include_bots ? '' : 'AND cl.is_bot_suspected = 0';
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cl.merchant_id, COUNT(*) AS clicks, m.name AS merchant_name, m.slug AS merchant_slug
				 FROM {$cl} cl
				 LEFT JOIN {$m} m ON m.id = cl.merchant_id
				 WHERE cl.clicked_at >= %s {$bot}
				 GROUP BY cl.merchant_id, m.name, m.slug
				 ORDER BY clicks DESC
				 LIMIT %d",
				$since_mysql,
				(int) $limit
			)
		);
	}

	public static function top_by_canonical( $since_mysql, $limit = 10, $include_bots = false ) {
		global $wpdb;
		$cl  = self::table();
		$cp  = $wpdb->prefix . 'supcomp_canonical_products';
		$bot = $include_bots ? '' : 'AND cl.is_bot_suspected = 0';
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cl.canonical_product_id, COUNT(*) AS clicks, cp.display_name, cp.slug
				 FROM {$cl} cl
				 LEFT JOIN {$cp} cp ON cp.id = cl.canonical_product_id
				 WHERE cl.clicked_at >= %s {$bot} AND cl.canonical_product_id IS NOT NULL
				 GROUP BY cl.canonical_product_id, cp.display_name, cp.slug
				 ORDER BY clicks DESC
				 LIMIT %d",
				$since_mysql,
				(int) $limit
			)
		);
	}

	public static function recent( $limit = 50, $include_bots = true ) {
		global $wpdb;
		$cl  = self::table();
		$no  = $wpdb->prefix . 'supcomp_normalized_offers';
		$m   = $wpdb->prefix . 'supcomp_merchants';
		$bot = $include_bots ? '' : 'WHERE cl.is_bot_suspected = 0';
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cl.*, o.product_title, o.brand, m.name AS merchant_name
				 FROM {$cl} cl
				 LEFT JOIN {$no} o ON o.id = cl.offer_id
				 LEFT JOIN {$m} m ON m.id = cl.merchant_id
				 {$bot}
				 ORDER BY cl.clicked_at DESC
				 LIMIT %d",
				(int) $limit
			)
		);
	}

	private static function trim_to( $val, $max ) {
		$val = (string) $val;
		return strlen( $val ) > $max ? substr( $val, 0, $max ) : $val;
	}

	private static function trim_to_nullable( $val, $max ) {
		if ( $val === null ) {
			return null;
		}
		$val = (string) $val;
		if ( $val === '' ) {
			return null;
		}
		return strlen( $val ) > $max ? substr( $val, 0, $max ) : $val;
	}
}
