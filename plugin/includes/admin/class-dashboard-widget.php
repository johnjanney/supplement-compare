<?php
/**
 * WP Dashboard widget — "Supplement Compare — At a Glance".
 *
 * A current-state summary on the native WordPress dashboard:
 *   - Catalog size: live canonical products + merchants (active / total).
 *   - Live offers: the set the public site shows, split in / out of stock.
 *   - Price moves: the ▲ up / ▼ down / unchanged tally, computed over exactly
 *     the same offer universe and time window as the public price-direction
 *     indicator (Supcomp_Price_History_Repo::price_moves_for_offers), so the
 *     "changed" figure equals the count of arrows readers see on the site.
 *
 * Admin-only: registered on wp_dashboard_setup behind a manage_options check,
 * and re-checked in render() as defense in depth. Read-only — no DB writes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Dashboard_Widget {

	const CAPABILITY = 'manage_options';

	public static function register_hooks() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widget' ) );
	}

	public static function register_widget() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'supcomp_at_a_glance',
			__( 'Supplement Compare — At a Glance', 'supplement-compare' ),
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Collect every figure the widget shows in one pass. Mirrors the JSON
	 * exporter's threshold + window derivation so the numbers agree with the
	 * live site.
	 */
	private static function gather() {
		$hide_hours     = (int) get_option( 'supcomp_staleness_hide_hours', 168 );
		$hide_threshold = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $hide_hours ) * HOUR_IN_SECONDS ) );
		$move_window    = (int) get_option( 'supcomp_price_move_window_days', 30 );

		$live      = Supcomp_Offers_Repo::live_for_dashboard( $hide_threshold );
		$offer_ids = array();
		$in_stock  = 0;
		$out_stock = 0;
		$other     = 0;
		foreach ( $live as $row ) {
			$offer_ids[] = (int) $row->id;
			switch ( $row->stock_status ) {
				case 'in_stock':
					$in_stock++;
					break;
				case 'out_of_stock':
					$out_stock++;
					break;
				default:
					$other++;
					break;
			}
		}
		$total_offers = count( $live );

		// Reuse the canonical price-move definition so the tally can never drift
		// from the on-site ▲/▼ indicator. Window of 0 disables it entirely.
		$moves = ( $move_window > 0 )
			? Supcomp_Price_History_Repo::price_moves_for_offers( $offer_ids, $move_window )
			: array();
		$up   = 0;
		$down = 0;
		foreach ( $moves as $mv ) {
			if ( isset( $mv['dir'] ) && 'up' === $mv['dir'] ) {
				$up++;
			} elseif ( isset( $mv['dir'] ) && 'down' === $mv['dir'] ) {
				$down++;
			}
		}
		$changed   = $up + $down;
		$unchanged = max( 0, $total_offers - $changed );

		$merchants = Supcomp_Merchants_Repo::count_by_status();

		return array(
			'canonicals'   => Supcomp_Canonical_Products_Repo::count_active(),
			'merchants'    => $merchants,
			'total_offers' => $total_offers,
			'in_stock'     => $in_stock,
			'out_stock'    => $out_stock,
			'other'        => $other,
			'move_window'  => $move_window,
			'up'           => $up,
			'down'         => $down,
			'changed'      => $changed,
			'unchanged'    => $unchanged,
		);
	}

	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$s = self::gather();
		?>
		<div class="supcomp-glance">
			<h3 style="margin:0 0 .35em;"><?php esc_html_e( 'Catalog', 'supplement-compare' ); ?></h3>
			<ul style="margin:0 0 1em;">
				<li>
					<strong><?php echo esc_html( number_format_i18n( $s['canonicals'] ) ); ?></strong>
					<?php esc_html_e( 'canonical products', 'supplement-compare' ); ?>
				</li>
				<li>
					<?php
					printf(
						/* translators: 1: active merchant count, 2: total merchant count */
						esc_html__( '%1$s active merchants (%2$s total)', 'supplement-compare' ),
						'<strong>' . esc_html( number_format_i18n( $s['merchants']['active'] ) ) . '</strong>',
						esc_html( number_format_i18n( $s['merchants']['total'] ) )
					);
					?>
				</li>
			</ul>

			<h3 style="margin:0 0 .35em;"><?php esc_html_e( 'Live offers', 'supplement-compare' ); ?></h3>
			<ul style="margin:0 0 1em;">
				<li>
					<strong><?php echo esc_html( number_format_i18n( $s['total_offers'] ) ); ?></strong>
					<?php esc_html_e( 'shown on the site', 'supplement-compare' ); ?>
				</li>
				<li>
					<?php
					printf(
						/* translators: 1: in-stock count, 2: out-of-stock count */
						esc_html__( '%1$s in stock · %2$s out of stock', 'supplement-compare' ),
						'<strong>' . esc_html( number_format_i18n( $s['in_stock'] ) ) . '</strong>',
						'<strong>' . esc_html( number_format_i18n( $s['out_stock'] ) ) . '</strong>'
					);
					?>
				</li>
				<?php if ( $s['other'] > 0 ) : ?>
				<li>
					<?php
					printf(
						/* translators: %s: count of offers with a non in/out-of-stock status */
						esc_html__( '%s other (backorder / unavailable / unknown)', 'supplement-compare' ),
						'<strong>' . esc_html( number_format_i18n( $s['other'] ) ) . '</strong>'
					);
					?>
				</li>
				<?php endif; ?>
			</ul>

			<h3 style="margin:0 0 .35em;">
				<?php
				if ( $s['move_window'] > 0 ) {
					printf(
						/* translators: %s: number of days in the price-move window */
						esc_html__( 'Price moves · last %s days', 'supplement-compare' ),
						esc_html( number_format_i18n( $s['move_window'] ) )
					);
				} else {
					esc_html_e( 'Price moves', 'supplement-compare' );
				}
				?>
			</h3>
			<?php if ( $s['move_window'] <= 0 ) : ?>
				<p style="margin:0;">
					<em><?php esc_html_e( 'Price-move tracking is disabled (window set to 0 in Settings).', 'supplement-compare' ); ?></em>
				</p>
			<?php else : ?>
				<ul style="margin:0;">
					<li>
						<strong><?php echo esc_html( number_format_i18n( $s['changed'] ) ); ?></strong>
						<?php esc_html_e( 'offers changed price', 'supplement-compare' ); ?>
					</li>
					<li>
						<span style="color:#b32d2e;">&#9650; <?php echo esc_html( number_format_i18n( $s['up'] ) ); ?> <?php esc_html_e( 'up', 'supplement-compare' ); ?></span>
						&nbsp;&middot;&nbsp;
						<span style="color:#1a7f37;">&#9660; <?php echo esc_html( number_format_i18n( $s['down'] ) ); ?> <?php esc_html_e( 'down', 'supplement-compare' ); ?></span>
						&nbsp;&middot;&nbsp;
						<span>&mdash; <?php echo esc_html( number_format_i18n( $s['unchanged'] ) ); ?> <?php esc_html_e( 'unchanged', 'supplement-compare' ); ?></span>
					</li>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}
}
