<?php
/**
 * XML sitemap for canonical-product pages (PROJECTBRIEF.md §8 Phase 10).
 *
 * Served at /supcomp-sitemap.xml. Lists one <url> per canonical_product
 * that meets BOTH thresholds:
 *   - seo_indexable = 1
 *   - active-offer count (within hide-staleness threshold) >= 3
 *
 * Sites with a primary sitemap (yoast / rank-math / wp-sitemap) can reference
 * this one via a <sitemapindex>; we don't generate the index since the
 * operator may want to keep their existing sitemap setup intact.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Sitemap {

	const QUERY_VAR = 'supcomp_sitemap';
	const URL_PATH  = 'supcomp-sitemap.xml';

	public static function register_rewrite_rules() {
		add_rewrite_rule(
			'^' . preg_quote( self::URL_PATH, '/' ) . '$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	public static function add_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function maybe_handle() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$hide_hours     = (int) get_option( 'supcomp_staleness_hide_hours', 168 );
		$hide_threshold = gmdate( 'Y-m-d H:i:s', time() - max( 1, $hide_hours ) * HOUR_IN_SECONDS );

		$entries = self::indexable_entries( $hide_threshold );

		nocache_headers();
		header( 'Content-Type: application/xml; charset=utf-8' );

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $entries as $row ) {
			$loc     = home_url( '/compare/' . rawurlencode( $row->slug ) . '/' );
			$lastmod = '';
			if ( ! empty( $row->updated_at ) ) {
				$ts = strtotime( $row->updated_at . ' UTC' );
				if ( $ts > 0 ) {
					$lastmod = gmdate( 'c', $ts );
				}
			}

			echo "  <url>\n";
			echo '    <loc>' . esc_url( $loc ) . "</loc>\n";
			if ( $lastmod ) {
				echo '    <lastmod>' . esc_html( $lastmod ) . "</lastmod>\n";
			}
			echo "    <changefreq>daily</changefreq>\n";
			echo "  </url>\n";
		}

		echo '</urlset>' . "\n";
		exit;
	}

	private static function indexable_entries( $hide_threshold_mysql ) {
		global $wpdb;
		$cp = $wpdb->prefix . 'supcomp_canonical_products';
		$no = $wpdb->prefix . 'supcomp_normalized_offers';

		// SEO floor is 3 active offers; but never list a page the publish
		// threshold would 404, so require at least max(3, publish threshold).
		$min_count = max( 3, Supcomp_Offers_Repo::min_active_to_publish() );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cp.slug, cp.updated_at, COUNT(o.id) AS active_count
				 FROM {$cp} cp
				 INNER JOIN {$no} o ON o.canonical_product_id = cp.id
				 WHERE cp.seo_indexable = 1
				   AND cp.status <> 'retired'
				   AND o.visibility_status = 'active'
				   AND o.last_synced_at >= %s
				 GROUP BY cp.id, cp.slug, cp.updated_at
				 HAVING active_count >= %d
				 ORDER BY cp.updated_at DESC",
				$hide_threshold_mysql,
				$min_count
			)
		);
	}
}
