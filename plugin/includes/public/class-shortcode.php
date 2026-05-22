<?php
/**
 * Public shortcode `[supplement_compare]` (PROJECTBRIEF.md §8 Phase 9).
 *
 * Renders a placeholder div on the page. The frontend.js asset loads the
 * static JSON written by Supcomp_JSON_Exporter, hydrates the placeholder
 * into an in-memory comparison table, and handles list ↔ detail routing
 * via the URL hash.
 *
 * Enqueue is gated on shortcode presence — pages without the shortcode
 * don't ship the JS / CSS.
 *
 * The shortcode supports a few attributes for embedding variations:
 *   [supplement_compare]                       — full app, list view default
 *   [supplement_compare canonical="slug"]      — start on a specific canonical's detail view
 *   [supplement_compare ingredient="slug"]     — list pre-filtered to one ingredient
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Shortcode {

	const SHORTCODE_TAG = 'supplement_compare';
	const SCRIPT_HANDLE = 'supcomp-frontend';
	const STYLE_HANDLE  = 'supcomp-frontend';

	public static function register() {
		add_shortcode( self::SHORTCODE_TAG, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'canonical'  => '',
				'ingredient' => '',
			),
			$atts,
			self::SHORTCODE_TAG
		);

		$initial = array();
		if ( $atts['canonical'] !== '' ) {
			$initial['canonical'] = sanitize_title( $atts['canonical'] );
		}
		if ( $atts['ingredient'] !== '' ) {
			$initial['ingredient'] = sanitize_title( $atts['ingredient'] );
		}

		return sprintf(
			'<div id="supcomp-app" class="supcomp-app" data-initial="%s">' .
				'<noscript><p>%s</p></noscript>' .
				'<p class="supcomp-loading">%s</p>' .
			'</div>',
			esc_attr( wp_json_encode( $initial ) ),
			esc_html__( 'This comparison interface requires JavaScript.', 'supplement-compare' ),
			esc_html__( 'Loading comparison…', 'supplement-compare' )
		);
	}

	/**
	 * Enqueue the JS + CSS only when the current page actually contains the
	 * shortcode. WordPress's wp_enqueue_scripts hook fires before the post
	 * content is processed, so we look at the global $post.
	 */
	public static function maybe_enqueue() {
		if ( is_admin() ) {
			return;
		}
		if ( ! self::current_post_has_shortcode() ) {
			return;
		}

		$json_url   = Supcomp_JSON_Exporter::output_url();
		$last_gen   = Supcomp_JSON_Exporter::last_generated_at();
		$cache_bust = $last_gen ? strtotime( $last_gen . ' UTC' ) : time();

		if ( $json_url !== '' ) {
			$json_url = add_query_arg( 'ver', $cache_bust, $json_url );
		}

		$plugin_url = plugins_url( '', SUPPLEMENT_COMPARE_PLUGIN_FILE );

		wp_enqueue_style(
			self::STYLE_HANDLE,
			$plugin_url . '/assets/public/frontend.css',
			array(),
			SUPPLEMENT_COMPARE_VERSION
		);

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			$plugin_url . '/assets/public/frontend.js',
			array(),
			SUPPLEMENT_COMPARE_VERSION,
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'supcompFrontend',
			array(
				'jsonUrl'             => $json_url,
				'affiliateDisclosure' => (string) get_option( 'supcomp_affiliate_disclosure', '' ),
				'i18n'                => self::i18n_strings(),
			)
		);
	}

	private static function current_post_has_shortcode() {
		global $post;
		if ( ! $post || empty( $post->post_content ) ) {
			return false;
		}
		return has_shortcode( $post->post_content, self::SHORTCODE_TAG );
	}

	private static function i18n_strings() {
		return array(
			'loading'                => __( 'Loading comparison…', 'supplement-compare' ),
			'loadError'              => __( 'Could not load comparison data. Please try again later.', 'supplement-compare' ),
			'emptyData'              => __( 'No comparison data is published yet. Check back soon.', 'supplement-compare' ),
			'noResults'              => __( 'No products match your filters.', 'supplement-compare' ),
			'search'                 => __( 'Search ingredient…', 'supplement-compare' ),
			'allForms'               => __( 'All forms', 'supplement-compare' ),
			'allIngredients'         => __( 'All ingredients', 'supplement-compare' ),
			'allMerchants'           => __( 'All merchants', 'supplement-compare' ),
			'inStockOnly'            => __( 'In stock only', 'supplement-compare' ),
			'thirdPartyOnly'         => __( 'Third-party tested only', 'supplement-compare' ),
			'coaOnly'                => __( 'COA available only', 'supplement-compare' ),
			'sortBy'                 => __( 'Sort by', 'supplement-compare' ),
			'sortCostPerActive'      => __( 'Cost per active unit', 'supplement-compare' ),
			'sortPrice'              => __( 'Price', 'supplement-compare' ),
			'sortBrand'              => __( 'Brand', 'supplement-compare' ),
			'sortMerchant'           => __( 'Merchant', 'supplement-compare' ),
			'sortRecency'            => __( 'Recently updated', 'supplement-compare' ),
			'product'                => __( 'Product', 'supplement-compare' ),
			'lowestCost'             => __( 'Lowest cost / active unit', 'supplement-compare' ),
			'merchants'              => __( 'Merchants', 'supplement-compare' ),
			'compare'                => __( 'Compare', 'supplement-compare' ),
			'backToAll'              => __( '← Back to all products', 'supplement-compare' ),
			'merchantColumn'         => __( 'Merchant', 'supplement-compare' ),
			'priceColumn'            => __( 'Price', 'supplement-compare' ),
			'servingsColumn'         => __( 'Servings', 'supplement-compare' ),
			'totalActiveColumn'      => __( 'Total active', 'supplement-compare' ),
			'servingSizeColumn'      => __( 'Serving size', 'supplement-compare' ),
			'numServingsColumn'      => __( '# Servings', 'supplement-compare' ),
			'costPerServingColumn'   => __( 'Cost / serving', 'supplement-compare' ),
			'costPerActiveColumn'    => __( 'Cost / active unit', 'supplement-compare' ),
			'stockColumn'            => __( 'Stock', 'supplement-compare' ),
			'buyColumn'              => __( 'Buy', 'supplement-compare' ),
			'buyNow'                 => __( 'Buy Now →', 'supplement-compare' ),
			'onSale'                 => __( 'on sale', 'supplement-compare' ),
			'trust3PT'               => __( '3PT', 'supplement-compare' ),
			'trustCOA'               => __( 'COA', 'supplement-compare' ),
			'staleNote'              => __( 'data may be outdated', 'supplement-compare' ),
			'lastUpdated'            => __( 'Data last updated', 'supplement-compare' ),
			'offerCountLabel'        => __( '%d offers', 'supplement-compare' ),
			'inStock'                => __( 'In stock', 'supplement-compare' ),
			'outOfStock'             => __( 'Out of stock', 'supplement-compare' ),
			'backorder'              => __( 'Backorder', 'supplement-compare' ),
			'unknownStock'           => __( 'Stock unknown', 'supplement-compare' ),
		);
	}
}
