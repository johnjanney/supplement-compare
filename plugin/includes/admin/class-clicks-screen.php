<?php
/**
 * Click analytics admin screen. Replaces the Phase 1 placeholder.
 *
 * Time-window filter (today / 7d / 30d / all). Summary tiles for total /
 * human / bot. Three top-N tables (by offer, by merchant, by canonical
 * product). Recent-clicks audit at the bottom for spot-checking.
 *
 * All aggregations exclude bot-suspected clicks by default. Tick "Include
 * bot-suspected" to see the full numbers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Clicks_Screen {

	const PAGE_SLUG = 'supcomp-clicks';

	public static function render() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'supplement-compare' ) );
		}

		$window       = isset( $_GET['window'] ) ? sanitize_key( wp_unslash( $_GET['window'] ) ) : '7d';
		$include_bots = ! empty( $_GET['include_bots'] );

		list( $label, $since_mysql ) = self::window_to_since( $window );

		$total = Supcomp_Clicks_Repo::count_within( $since_mysql, true );
		$human = Supcomp_Clicks_Repo::count_within( $since_mysql, false );
		$bot   = Supcomp_Clicks_Repo::count_bots_within( $since_mysql );

		$by_offer     = Supcomp_Clicks_Repo::top_by_offer( $since_mysql, 10, $include_bots );
		$by_merchant  = Supcomp_Clicks_Repo::top_by_merchant( $since_mysql, 10, $include_bots );
		$by_canonical = Supcomp_Clicks_Repo::top_by_canonical( $since_mysql, 10, $include_bots );

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Click Analytics', 'supplement-compare' ); ?></h1>
			<hr class="wp-header-end">

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<p>
					<select name="window">
						<option value="today" <?php selected( $window, 'today' ); ?>><?php esc_html_e( 'Today', 'supplement-compare' ); ?></option>
						<option value="7d" <?php selected( $window, '7d' ); ?>><?php esc_html_e( 'Last 7 days', 'supplement-compare' ); ?></option>
						<option value="30d" <?php selected( $window, '30d' ); ?>><?php esc_html_e( 'Last 30 days', 'supplement-compare' ); ?></option>
						<option value="all" <?php selected( $window, 'all' ); ?>><?php esc_html_e( 'All time', 'supplement-compare' ); ?></option>
					</select>
					<label style="margin-left:1em">
						<input type="checkbox" name="include_bots" value="1" <?php checked( $include_bots ); ?>>
						<?php esc_html_e( 'Include bot-suspected in top-N tables', 'supplement-compare' ); ?>
					</label>
					<?php submit_button( __( 'Apply', 'supplement-compare' ), '', '', false ); ?>
				</p>
			</form>

			<div style="display:flex;gap:1em;margin:1.5em 0">
				<?php self::render_tile( __( 'Total clicks', 'supplement-compare' ), $total, $label ); ?>
				<?php self::render_tile( __( 'Human clicks', 'supplement-compare' ), $human, $label, '#155724', '#d4edda' ); ?>
				<?php self::render_tile( __( 'Bot-suspected', 'supplement-compare' ), $bot, $label, '#856404', '#fff3cd' ); ?>
			</div>

			<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5em">
				<div>
					<h2><?php esc_html_e( 'Top offers', 'supplement-compare' ); ?></h2>
					<?php self::render_offer_table( $by_offer ); ?>
				</div>
				<div>
					<h2><?php esc_html_e( 'Top merchants', 'supplement-compare' ); ?></h2>
					<?php self::render_merchant_table( $by_merchant ); ?>
				</div>
			</div>

			<h2 style="margin-top:1.5em"><?php esc_html_e( 'Top canonical products', 'supplement-compare' ); ?></h2>
			<?php self::render_canonical_table( $by_canonical ); ?>

			<h2 style="margin-top:1.5em"><?php esc_html_e( 'Recent clicks', 'supplement-compare' ); ?></h2>
			<?php self::render_recent_table( Supcomp_Clicks_Repo::recent( 50, $include_bots ) ); ?>
		</div>
		<?php
	}

	// ---------- tiles ----------

	private static function render_tile( $label, $count, $window_label, $fg = '#23282d', $bg = '#f6f7f7' ) {
		?>
		<div style="background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $fg ); ?>;border:1px solid #ddd;padding:1em;flex:1">
			<div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;opacity:0.7"><?php echo esc_html( $label ); ?></div>
			<div style="font-size:32px;font-weight:600;margin-top:0.25em"><?php echo esc_html( number_format( (int) $count ) ); ?></div>
			<div style="font-size:11px;margin-top:0.25em;opacity:0.7"><?php echo esc_html( $window_label ); ?></div>
		</div>
		<?php
	}

	// ---------- tables ----------

	private static function render_offer_table( $rows ) {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No clicks in this window.', 'supplement-compare' ) . '</p>';
			return;
		}
		?>
		<table class="wp-list-table widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Offer', 'supplement-compare' ); ?></th>
				<th><?php esc_html_e( 'Merchant', 'supplement-compare' ); ?></th>
				<th style="width:5em;text-align:right"><?php esc_html_e( 'Clicks', 'supplement-compare' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td>
							<?php if ( $r->offer_id ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=supcomp-active&action=edit&id=' . (int) $r->offer_id ) ); ?>"><?php echo esc_html( $r->product_title ? $r->product_title : '(offer #' . (int) $r->offer_id . ')' ); ?></a>
								<?php if ( $r->variant_title ) : ?>
									<br><span class="description"><?php echo esc_html( $r->variant_title ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<span style="color:#888"><?php esc_html_e( '(deleted offer)', 'supplement-compare' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $r->merchant_name ? $r->merchant_name : '—' ); ?></td>
						<td style="text-align:right"><strong><?php echo esc_html( (int) $r->clicks ); ?></strong></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_merchant_table( $rows ) {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No clicks in this window.', 'supplement-compare' ) . '</p>';
			return;
		}
		?>
		<table class="wp-list-table widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Merchant', 'supplement-compare' ); ?></th>
				<th style="width:5em;text-align:right"><?php esc_html_e( 'Clicks', 'supplement-compare' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r->merchant_name ? $r->merchant_name : '—' ); ?></td>
						<td style="text-align:right"><strong><?php echo esc_html( (int) $r->clicks ); ?></strong></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_canonical_table( $rows ) {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No clicks against canonical-matched offers in this window.', 'supplement-compare' ) . '</p>';
			return;
		}
		?>
		<table class="wp-list-table widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Canonical product', 'supplement-compare' ); ?></th>
				<th><?php esc_html_e( 'Slug', 'supplement-compare' ); ?></th>
				<th style="width:5em;text-align:right"><?php esc_html_e( 'Clicks', 'supplement-compare' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td>
							<?php if ( $r->canonical_product_id ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=supcomp-canonical&action=edit&id=' . (int) $r->canonical_product_id ) ); ?>"><?php echo esc_html( $r->display_name ); ?></a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $r->slug ); ?></code></td>
						<td style="text-align:right"><strong><?php echo esc_html( (int) $r->clicks ); ?></strong></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_recent_table( $rows ) {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No clicks recorded.', 'supplement-compare' ) . '</p>';
			return;
		}
		?>
		<table class="wp-list-table widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'When', 'supplement-compare' ); ?></th>
				<th><?php esc_html_e( 'Offer', 'supplement-compare' ); ?></th>
				<th><?php esc_html_e( 'Merchant', 'supplement-compare' ); ?></th>
				<th><?php esc_html_e( 'Referrer', 'supplement-compare' ); ?></th>
				<th><?php esc_html_e( 'UTM', 'supplement-compare' ); ?></th>
				<th><?php esc_html_e( 'Bot?', 'supplement-compare' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $r->clicked_at, 'Y-m-d H:i' ) ); ?></td>
						<td>
							<?php if ( $r->offer_id ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=supcomp-active&action=edit&id=' . (int) $r->offer_id ) ); ?>"><?php echo esc_html( $r->product_title ? $r->product_title : '(offer #' . (int) $r->offer_id . ')' ); ?></a>
							<?php else : ?>
								<span style="color:#888">—</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $r->merchant_name ? $r->merchant_name : '—' ); ?></td>
						<td><?php echo $r->referrer ? '<code style="font-size:11px">' . esc_html( self::short( $r->referrer, 60 ) ) . '</code>' : '—'; ?></td>
						<td><?php
							$parts = array_filter( array(
								$r->utm_source ? 's=' . $r->utm_source : '',
								$r->utm_medium ? 'm=' . $r->utm_medium : '',
								$r->utm_campaign ? 'c=' . $r->utm_campaign : '',
							) );
							echo $parts ? esc_html( implode( ' ', $parts ) ) : '—';
						?></td>
						<td><?php echo (int) $r->is_bot_suspected ? '<span style="color:#856404">bot</span>' : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// ---------- helpers ----------

	private static function window_to_since( $window ) {
		$now = time();
		switch ( $window ) {
			case 'today':
				return array( __( 'today', 'supplement-compare' ), gmdate( 'Y-m-d 00:00:00' ) );
			case '30d':
				return array( __( 'last 30 days', 'supplement-compare' ), gmdate( 'Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS ) );
			case 'all':
				return array( __( 'all time', 'supplement-compare' ), '1970-01-01 00:00:00' );
			case '7d':
			default:
				return array( __( 'last 7 days', 'supplement-compare' ), gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS ) );
		}
	}

	private static function short( $val, $max ) {
		$val = (string) $val;
		if ( strlen( $val ) <= $max ) {
			return $val;
		}
		return substr( $val, 0, $max - 1 ) . '…';
	}
}
