<?php
/**
 * Per-canonical product page (PROJECTBRIEF.md §8 Phase 10).
 *
 * Routes /compare/{slug}/ to a virtual page rendered inside the active
 * theme (get_header + get_footer wraps). The page body shows:
 *   - The canonical's display_name as H1
 *   - The operator's seo_content (wp_kses_post'd on output for defense)
 *   - Schema.org Product + AggregateOffer JSON-LD in <head>
 *   - The [supplement_compare canonical="slug"] shortcode embedded so the
 *     Phase 9 JS app drives the actual comparison table
 *
 * Indexability rule (§8 Phase 10):
 *   indexable IFF canonical.seo_indexable=true AND active_offer_count >= 3
 * When not indexable we still render the page (so the operator can link
 * to it internally) but emit <meta name="robots" content="noindex,follow">.
 *
 * The bare /compare/ URL (no slug) is NOT intercepted — operators commonly
 * dedicate a /compare/ WP page to the [supplement_compare] shortcode for
 * the list view. Our rule requires a non-empty slug segment.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Canonical_Page {

	const QUERY_VAR = 'supcomp_canonical';

	public static function register_rewrite_rules() {
		add_rewrite_rule(
			'^compare/([^/]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	public static function add_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Use template_include so we render inside the theme. Hooked early
	 * enough that the theme's header.php / footer.php still run.
	 */
	public static function maybe_render( $template ) {
		$slug = get_query_var( self::QUERY_VAR );
		if ( ! $slug ) {
			return $template;
		}

		$canonical = Supcomp_Canonical_Products_Repo::get_by_slug( (string) $slug );
		if ( ! $canonical || $canonical->status === 'retired' ) {
			status_header( 404 );
			return $template; // Let the theme render its 404 page.
		}

		// Compute indexability + aggregate offer stats once.
		$hide_hours      = (int) get_option( 'supcomp_staleness_hide_hours', 168 );
		$hide_threshold  = gmdate( 'Y-m-d H:i:s', time() - max( 1, $hide_hours ) * HOUR_IN_SECONDS );
		$active_count    = Supcomp_Offers_Repo::count_active_for_canonical( (int) $canonical->id, $hide_threshold );

		// Publish threshold: a canonical below the operator's minimum active-offer
		// count is not yet published — 404 it the same way a retired one is, so it
		// stays out of the public site while it accumulates offers.
		if ( $active_count < Supcomp_Offers_Repo::min_active_to_publish() ) {
			status_header( 404 );
			return $template; // Let the theme render its 404 page.
		}

		$is_indexable    = ( (int) $canonical->seo_indexable === 1 ) && ( $active_count >= 3 );
		$aggregate       = Supcomp_Offers_Repo::aggregate_for_canonical( (int) $canonical->id, $hide_threshold );
		$ingredient      = $canonical->ingredient_id
			? Supcomp_Ingredients_Repo::get( (int) $canonical->ingredient_id )
			: null;

		// Hook wp_head / wp_title for the page chrome.
		add_filter(
			'document_title_parts',
			function ( $parts ) use ( $canonical ) {
				$parts['title'] = $canonical->display_name;
				return $parts;
			},
			11
		);
		add_action(
			'wp_head',
			function () use ( $is_indexable, $canonical, $aggregate, $ingredient ) {
				if ( ! $is_indexable ) {
					echo '<meta name="robots" content="noindex,follow">' . "\n";
				}
				$jsonld = self::schema_jsonld( $canonical, $ingredient, $aggregate );
				if ( $jsonld ) {
					echo '<script type="application/ld+json">' . $jsonld . '</script>' . "\n";
				}
			}
		);

		// Render body inside the theme.
		add_action(
			'loop_start',
			function () {
				// Suppress; we render manually.
			}
		);

		// Provide a body template via the catch-all `the_content` filter
		// isn't reliable here — easier: render fully and exit cleanly.
		get_header();
		echo '<main id="supcomp-canonical" class="supcomp-canonical wrap" style="max-width:960px;margin:1.5em auto;padding:0 1em">';
		self::render_body( $canonical, $ingredient, $active_count, $is_indexable );
		echo '</main>';
		get_footer();
		exit;
	}

	private static function render_body( $canonical, $ingredient, $active_count, $is_indexable ) {
		$show_edit = current_user_can( 'manage_options' );
		?>
		<header class="supcomp-canonical-header">
			<h1><?php echo esc_html( $canonical->display_name ); ?></h1>
			<?php if ( (bool) get_option( 'supcomp_subhead_detail_enabled', true ) ) : ?>
			<p class="supcomp-meta">
				<?php
				$bits = array();
				if ( $ingredient ) {
					$bits[] = esc_html( $ingredient->name );
					if ( $ingredient->category ) {
						$bits[] = esc_html( $ingredient->category );
					}
				}
				if ( $canonical->ingredient_form ) {
					$bits[] = esc_html( $canonical->ingredient_form );
				}
				$strength_unit = $ingredient ? $ingredient->default_unit : '';
				$has_strength  = $canonical->strength_per_serving !== null
					&& $canonical->strength_per_serving !== ''
					&& (float) $canonical->strength_per_serving > 0;
				if ( $has_strength ) {
					$bits[] = esc_html( self::format_decimal( $canonical->strength_per_serving ) . $strength_unit );
				} elseif ( $strength_unit ) {
					// No pinned strength on the canonical — surface the active
					// unit by itself instead of "0mg" from legacy DEFAULT 0 rows.
					$bits[] = esc_html( $strength_unit );
				}
				if ( $canonical->standardization_compound && $canonical->standardization_percentage ) {
					$bits[] = esc_html( self::format_decimal( $canonical->standardization_percentage ) . '% ' . $canonical->standardization_compound );
				}
				echo implode( ' &middot; ', $bits );
				?>
			</p>
			<?php endif; ?>
			<?php if ( $show_edit ) : ?>
				<p class="supcomp-meta">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=supcomp-canonical&action=edit&id=' . (int) $canonical->id ) ); ?>"><?php esc_html_e( 'Edit canonical product', 'supplement-compare' ); ?></a>
					&nbsp;|&nbsp;
					<?php
					if ( $is_indexable ) {
						echo '<span style="color:#155724">' . esc_html__( 'Indexes: yes', 'supplement-compare' ) . '</span>';
					} else {
						echo '<span style="color:#856404">' . esc_html(
							sprintf( __( 'Indexes: no — seo_indexable=%1$s, %2$d active offers', 'supplement-compare' ),
								( $canonical->seo_indexable ? 'on' : 'off' ),
								(int) $active_count
							)
						) . '</span>';
					}
					?>
				</p>
			<?php endif; ?>
		</header>

		<?php if ( ! empty( $canonical->seo_content ) ) : ?>
			<section class="supcomp-canonical-content">
				<?php echo wp_kses_post( $canonical->seo_content ); ?>
			</section>
		<?php endif; ?>

		<section class="supcomp-canonical-comparison">
			<?php
			// Embed the Phase 9 frontend, pointed at this canonical.
			echo do_shortcode( sprintf( '[supplement_compare canonical="%s"]', esc_attr( $canonical->slug ) ) );
			?>
		</section>
		<?php
	}

	/**
	 * Build a schema.org Product + AggregateOffer block. Skipped when there
	 * are zero active offers (nothing to aggregate over).
	 */
	private static function schema_jsonld( $canonical, $ingredient, $aggregate ) {
		if ( empty( $aggregate->cnt ) || (int) $aggregate->cnt === 0 ) {
			return '';
		}

		$payload = array(
			'@context' => 'https://schema.org/',
			'@type'    => 'Product',
			'name'     => (string) $canonical->display_name,
		);
		if ( ! empty( $canonical->seo_content ) ) {
			// Strip HTML for the schema description. Truncate to ~500 chars to
			// keep the JSON-LD payload reasonable.
			$desc = wp_strip_all_tags( (string) $canonical->seo_content );
			if ( strlen( $desc ) > 500 ) {
				$desc = substr( $desc, 0, 497 ) . '…';
			}
			$payload['description'] = $desc;
		}
		if ( $ingredient && $ingredient->category ) {
			$payload['category'] = (string) $ingredient->category;
		}

		$availability = ! empty( $aggregate->any_in_stock )
			? 'https://schema.org/InStock'
			: 'https://schema.org/OutOfStock';

		$payload['offers'] = array(
			'@type'         => 'AggregateOffer',
			'priceCurrency' => $aggregate->currency ? (string) $aggregate->currency : 'USD',
			'lowPrice'      => (float) $aggregate->low,
			'highPrice'     => (float) $aggregate->high,
			'offerCount'    => (int) $aggregate->cnt,
			'availability'  => $availability,
		);

		// JSON_HEX_TAG escapes "<" and ">" as unicode sequences so a literal
		// "</script>" in operator-authored display_name / seo_content cannot
		// close this inline <script> element and break out into HTML. Slashes
		// stay unescaped for readable schema.org URLs; JSON parsers handle both.
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG );
		return $json === false ? '' : $json;
	}

	private static function format_decimal( $val ) {
		if ( $val === null || $val === '' ) {
			return '';
		}
		$s = (string) $val;
		if ( strpos( $s, '.' ) !== false ) {
			$s = rtrim( rtrim( $s, '0' ), '.' );
		}
		return $s;
	}
}
