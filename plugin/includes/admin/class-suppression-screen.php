<?php
/**
 * Suppression List admin screen (v1.23.0).
 *
 * Read + lift only. Entries are created automatically when a *rejected* offer
 * is hard-deleted via Cleanup (Supcomp_Deletion_Service::hard_delete_offer) —
 * there is no manual "block a product" form and no separate pending-queue
 * action, per the operator decision behind this feature.
 *
 * A suppression keeps a product off the site permanently: the importer skips
 * its natural key before it can re-enter the pending queue. "Lift" is the
 * escape hatch — remove the row and the next import re-inserts the product as
 * pending if it's still live on the merchant.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Suppression_Screen {

	const PAGE_SLUG = 'supcomp-suppression';
	const NONCE     = 'supcomp_lift_suppression';
	const PER_PAGE  = 50;

	public static function register_hooks() {
		add_action( 'admin_post_supcomp_lift_suppression', array( __CLASS__, 'handle_lift' ) );
	}

	public static function render() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'supplement-compare' ) );
		}

		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$total  = Supcomp_Suppressions_Repo::count_all( $search );
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$rows   = Supcomp_Suppressions_Repo::paginate( $page, self::PER_PAGE, $search );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Suppression List', 'supplement-compare' ); ?></h1>

			<p class="description" style="max-width:60em">
				<?php esc_html_e( 'Products you rejected and then purged via Cleanup. The extractor and CSV import skip these — they will not return to the Pending Queue even if the merchant still lists them. This is what makes a rejection permanent. To let a product back in, lift its suppression; the next import re-adds it as pending.', 'supplement-compare' ); ?>
			</p>

			<?php self::render_notice(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<p class="search-box">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search title, brand, source id…', 'supplement-compare' ); ?>">
					<?php submit_button( __( 'Search', 'supplement-compare' ), '', '', false ); ?>
					<?php if ( $search !== '' ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Clear', 'supplement-compare' ); ?></a>
					<?php endif; ?>
				</p>
			</form>

			<?php if ( empty( $rows ) ) : ?>
				<?php if ( $search !== '' ) : ?>
					<p><em><?php printf( esc_html__( 'No suppressed products match “%s”.', 'supplement-compare' ), esc_html( $search ) ); ?></em></p>
				<?php else : ?>
					<p><em><?php esc_html_e( 'Nothing suppressed. Entries are added automatically when you delete a rejected offer on the Cleanup screen.', 'supplement-compare' ); ?></em></p>
				<?php endif; ?>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped" style="max-width:80em">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'supplement-compare' ); ?></th>
							<th><?php esc_html_e( 'Merchant', 'supplement-compare' ); ?></th>
							<th><?php esc_html_e( 'Source key', 'supplement-compare' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'supplement-compare' ); ?></th>
							<th><?php esc_html_e( 'Suppressed', 'supplement-compare' ); ?></th>
							<th style="width:9em"><?php esc_html_e( 'Action', 'supplement-compare' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $r ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $r->product_title !== '' ? $r->product_title : __( '(no title captured)', 'supplement-compare' ) ); ?></strong>
									<?php if ( $r->brand !== '' ) : ?>
										<p class="description" style="margin:.2em 0 0"><?php echo esc_html( $r->brand ); ?></p>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $r->merchant_name !== null ? $r->merchant_name : sprintf( '#%d', (int) $r->merchant_id ) ); ?></td>
								<td><code><?php echo esc_html( $r->source_product_id . ( $r->source_variant_id !== '' ? ' / ' . $r->source_variant_id : '' ) ); ?></code></td>
								<td><?php echo esc_html( $r->reason ); ?></td>
								<td><?php echo esc_html( self::pretty_date( $r->created_at ) ); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Lift this suppression? The product can return to the Pending Queue on the next import.', 'supplement-compare' ) ); ?>');">
										<input type="hidden" name="action" value="supcomp_lift_suppression">
										<input type="hidden" name="id" value="<?php echo (int) $r->id; ?>">
										<?php wp_nonce_field( self::NONCE ); ?>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Lift', 'supplement-compare' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php self::render_pagination( $total, $page, $search ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_lift() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'supplement-compare' ) );
		}
		check_admin_referer( self::NONCE );

		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		$ok = $id > 0 && Supcomp_Suppressions_Repo::remove( $id );

		wp_safe_redirect( add_query_arg( 'supcomp_notice', $ok ? 'lifted' : 'lift_error', $base ) );
		exit;
	}

	private static function render_notice() {
		$type = isset( $_GET['supcomp_notice'] ) ? sanitize_key( wp_unslash( $_GET['supcomp_notice'] ) ) : '';
		if ( $type === 'lifted' ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Suppression lifted. The product can return to the Pending Queue on the next import.', 'supplement-compare' )
			);
		} elseif ( $type === 'lift_error' ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html__( 'Could not lift that suppression — it may have already been removed.', 'supplement-compare' )
			);
		}
	}

	private static function render_pagination( $total, $page, $search = '' ) {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages < 2 ) {
			return;
		}
		echo '<p class="tablenav-pages" style="margin-top:1em">';
		for ( $i = 1; $i <= $pages; $i++ ) {
			$args = array( 'page' => self::PAGE_SLUG, 'paged' => $i );
			if ( $search !== '' ) {
				$args['s'] = $search;
			}
			$url = add_query_arg( $args, admin_url( 'admin.php' ) );
			if ( $i === (int) $page ) {
				printf( '<span class="button button-primary" style="margin:0 .2em">%d</span>', $i );
			} else {
				printf( '<a class="button" style="margin:0 .2em" href="%s">%d</a>', esc_url( $url ), $i );
			}
		}
		echo '</p>';
	}

	private static function pretty_date( $mysql_utc ) {
		if ( empty( $mysql_utc ) ) {
			return '—';
		}
		$ts = strtotime( $mysql_utc . ' UTC' );
		if ( $ts === false ) {
			return (string) $mysql_utc;
		}
		return wp_date( 'Y-m-d H:i', $ts );
	}
}
