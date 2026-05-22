<?php
/**
 * Shared confirmation + POST handler for hard-deleting entities.
 *
 * Per-entity admin screens only need to link to:
 *   admin.php?page=supcomp-delete&type=<type>&id=<id>
 *
 * …which lands on the confirmation screen here. The screen renders the
 * cascade preview from Supcomp_Deletion_Service and a Confirm button that
 * POSTs back to admin-post.php with a nonce.
 *
 * Centralizing this avoids re-implementing delete flows on four different
 * entity screens. The state gate (must be in soft-trash state) is enforced
 * by the service; the admin layer just renders the preview and the
 * Cancel/Confirm UI.
 *
 * Supported types: 'offer', 'merchant', 'ingredient', 'canonical_product'.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Deletion_Admin {

	const PAGE_SLUG = 'supcomp-delete';
	const NONCE     = 'supcomp_delete_entity';

	public static function register_hooks() {
		add_action( 'admin_post_supcomp_delete_entity', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_notice' ) );
	}

	public static function maybe_render_notice() {
		$type = isset( $_GET['supcomp_notice'] ) ? sanitize_key( wp_unslash( $_GET['supcomp_notice'] ) ) : '';
		if ( $type === 'deleted_hard' ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Permanently deleted. Cascade rules ran: per-offer audit rows removed, click_log preserved with the FK set to NULL.', 'supplement-compare' )
			);
			return;
		}
		if ( $type === 'delete_refused' ) {
			$err = isset( $_GET['supcomp_delete_err'] ) ? wp_unslash( $_GET['supcomp_delete_err'] ) : '';
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $err !== '' ? $err : __( 'Delete refused.', 'supplement-compare' ) )
			);
		}
	}

	/**
	 * Render the confirmation screen. Routed from class-admin.php as a
	 * hidden submenu (no menu entry, but the slug is registered so WP
	 * recognizes the page).
	 */
	public static function render() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'supplement-compare' ) );
		}

		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$id   = isset( $_GET['id'] )   ? absint( wp_unslash( $_GET['id'] ) ) : 0;

		if ( ! in_array( $type, array( 'offer', 'merchant', 'ingredient', 'canonical_product' ), true ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Unknown entity type', 'supplement-compare' ) . '</h1></div>';
			return;
		}
		if ( $id <= 0 ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Missing id', 'supplement-compare' ) . '</h1></div>';
			return;
		}

		$info = self::load_entity( $type, $id );
		if ( $info['entity'] === null ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Row not found', 'supplement-compare' ) . '</h1></div>';
			return;
		}
		if ( ! $info['deletable'] ) {
			echo '<div class="wrap">';
			echo '<h1>' . esc_html__( 'Cannot delete this row', 'supplement-compare' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html( $info['gate_reason'] ) . '</p></div>';
			echo '<p><a class="button" href="' . esc_url( $info['return_url'] ) . '">' . esc_html__( '« Back', 'supplement-compare' ) . '</a></p>';
			echo '</div>';
			return;
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html( sprintf( __( 'Delete %s permanently?', 'supplement-compare' ), $info['type_label'] ) ); ?></h1>

			<p>
				<?php echo esc_html__( 'You are about to permanently delete:', 'supplement-compare' ); ?>
				<strong><?php echo esc_html( $info['display_name'] ); ?></strong>
			</p>

			<h2><?php esc_html_e( 'Impact', 'supplement-compare' ); ?></h2>
			<table class="widefat striped" style="max-width:46em">
				<tbody>
					<?php foreach ( $info['preview_rows'] as $label => $value ) : ?>
						<tr>
							<th style="width:55%"><?php echo esc_html( $label ); ?></th>
							<td><?php echo esc_html( $value ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="description" style="margin-top:1em">
				<?php esc_html_e( 'Cascade rules:', 'supplement-compare' ); ?>
			</p>
			<ul style="margin-left:1.5em">
				<li><?php esc_html_e( 'price_history and raw_source_offers rows are deleted alongside the entity (per-offer audit trail).', 'supplement-compare' ); ?></li>
				<li><?php esc_html_e( 'click_log rows are preserved; the FK is set to NULL so historical click totals still show in the dashboard.', 'supplement-compare' ); ?></li>
				<li><?php esc_html_e( 'This action cannot be undone — no soft-trash backup.', 'supplement-compare' ); ?></li>
			</ul>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1.5em">
				<input type="hidden" name="action" value="supcomp_delete_entity">
				<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>">
				<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
				<input type="hidden" name="return" value="<?php echo esc_attr( $info['return_url'] ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<p>
					<button type="submit" class="button button-primary" style="background:#a00;border-color:#900" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete? This cannot be undone.', 'supplement-compare' ) ); ?>');">
						<?php esc_html_e( 'Delete permanently', 'supplement-compare' ); ?>
					</button>
					<a class="button" href="<?php echo esc_url( $info['return_url'] ); ?>"><?php esc_html_e( 'Cancel', 'supplement-compare' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	public static function handle_delete() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'supplement-compare' ) );
		}
		check_admin_referer( self::NONCE );

		$type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$id     = isset( $_POST['id'] )   ? absint( wp_unslash( $_POST['id'] ) )       : 0;
		$return = isset( $_POST['return'] ) ? esc_url_raw( wp_unslash( $_POST['return'] ) ) : admin_url( 'admin.php?page=supcomp-pending' );

		switch ( $type ) {
			case 'offer':
				$result = Supcomp_Deletion_Service::hard_delete_offer( $id );
				break;
			case 'merchant':
				$result = Supcomp_Deletion_Service::hard_delete_merchant( $id );
				break;
			case 'ingredient':
				$result = Supcomp_Deletion_Service::hard_delete_ingredient( $id );
				break;
			case 'canonical_product':
				$result = Supcomp_Deletion_Service::hard_delete_canonical_product( $id );
				break;
			default:
				wp_safe_redirect( add_query_arg( 'supcomp_notice', 'error', $return ) );
				exit;
		}

		if ( empty( $result['ok'] ) ) {
			$err = isset( $result['error'] ) ? $result['error'] : 'unknown';
			wp_safe_redirect( add_query_arg(
				array( 'supcomp_notice' => 'delete_refused', 'supcomp_delete_err' => rawurlencode( $err ) ),
				$return
			) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'supcomp_notice', 'deleted_hard', $return ) );
		exit;
	}

	/**
	 * Build the per-type confirmation payload: entity row, deletable flag,
	 * display name, preview rows, return URL.
	 */
	private static function load_entity( $type, $id ) {
		$info = array(
			'entity'       => null,
			'deletable'    => false,
			'gate_reason'  => '',
			'type_label'   => '',
			'display_name' => '',
			'preview_rows' => array(),
			'return_url'   => admin_url( 'admin.php?page=supcomp-pending' ),
		);

		switch ( $type ) {
			case 'offer':
				$offer = Supcomp_Offers_Repo::get( $id );
				$info['entity']     = $offer;
				$info['type_label'] = __( 'offer', 'supplement-compare' );
				$info['return_url'] = admin_url( 'admin.php?page=supcomp-active' );
				if ( $offer ) {
					$info['display_name'] = $offer->product_title . ( $offer->variant_title ? ' — ' . $offer->variant_title : '' );
					$info['deletable']    = Supcomp_Deletion_Service::offer_is_deletable( $offer );
					if ( ! $info['deletable'] ) {
						$info['gate_reason'] = sprintf(
							__( 'Offer must be in visibility status "rejected" or "dead" before it can be hard-deleted. Current state: %s.', 'supplement-compare' ),
							$offer->visibility_status
						);
					} else {
						$p = Supcomp_Deletion_Service::preview_offer_deletion( $offer );
						$info['preview_rows'] = array(
							__( 'Price-history rows to delete',  'supplement-compare' ) => (string) $p['price_history_rows'],
							__( 'Raw CSV-row snapshots to delete', 'supplement-compare' ) => (string) $p['raw_csv_rows'],
							__( 'Click-log rows to preserve (offer_id will be nulled)', 'supplement-compare' ) => (string) $p['click_log_rows'],
						);
					}
				}
				break;

			case 'merchant':
				$merchant = Supcomp_Merchants_Repo::get( $id );
				$info['entity']     = $merchant;
				$info['type_label'] = __( 'merchant', 'supplement-compare' );
				$info['return_url'] = admin_url( 'admin.php?page=supcomp-merchants' );
				if ( $merchant ) {
					$info['display_name'] = $merchant->name . ' (' . $merchant->slug . ')';
					$info['deletable']    = Supcomp_Deletion_Service::merchant_is_deletable( $merchant );
					if ( ! $info['deletable'] ) {
						$info['gate_reason'] = sprintf(
							__( 'Merchant must be in status "dead" before it can be hard-deleted. Current state: %s.', 'supplement-compare' ),
							$merchant->status
						);
					} else {
						$p = Supcomp_Deletion_Service::preview_merchant_deletion( $merchant );
						$info['preview_rows'] = array(
							__( 'Offers to cascade-delete', 'supplement-compare' )       => (string) $p['offer_count'],
							__( 'Price-history rows to delete', 'supplement-compare' )  => (string) $p['price_history_rows'],
							__( 'Raw CSV-row snapshots to delete', 'supplement-compare' ) => (string) $p['raw_csv_rows'],
							__( 'Click-log rows to preserve (merchant_id will be nulled)', 'supplement-compare' ) => (string) $p['click_log_rows'],
						);
					}
				}
				break;

			case 'ingredient':
				$ingredient = Supcomp_Ingredients_Repo::get( $id );
				$info['entity']     = $ingredient;
				$info['type_label'] = __( 'ingredient', 'supplement-compare' );
				$info['return_url'] = admin_url( 'admin.php?page=supcomp-ingredients' );
				if ( $ingredient ) {
					$info['display_name'] = $ingredient->name . ' (' . $ingredient->slug . ')';
					$preview              = Supcomp_Deletion_Service::preview_ingredient_deletion( $ingredient );
					$can                  = (int) $preview['canonical_count'];
					if ( $ingredient->status !== 'retired' ) {
						$info['gate_reason'] = sprintf(
							__( 'Ingredient must be in status "retired" before it can be hard-deleted. Current state: %s.', 'supplement-compare' ),
							$ingredient->status
						);
					} elseif ( $can > 0 ) {
						$info['gate_reason'] = sprintf(
							__( 'Cannot delete this ingredient — %d canonical product(s) still reference it. Retire and delete those first.', 'supplement-compare' ),
							$can
						);
					} else {
						$info['deletable']    = true;
						$info['preview_rows'] = array(
							__( 'Canonical products referencing this ingredient (blocker if > 0)', 'supplement-compare' ) => (string) $preview['canonical_count'],
							__( 'Offers to orphan (ingredient_id will be nulled)', 'supplement-compare' )                => (string) $preview['offer_count'],
						);
					}
				}
				break;

			case 'canonical_product':
				$canonical = Supcomp_Canonical_Products_Repo::get( $id );
				$info['entity']     = $canonical;
				$info['type_label'] = __( 'canonical product', 'supplement-compare' );
				$info['return_url'] = admin_url( 'admin.php?page=supcomp-canonical' );
				if ( $canonical ) {
					$info['display_name'] = $canonical->display_name ? $canonical->display_name : $canonical->slug;
					$info['deletable']    = Supcomp_Deletion_Service::canonical_is_deletable( $canonical );
					if ( ! $info['deletable'] ) {
						$info['gate_reason'] = sprintf(
							__( 'Canonical product must be in status "retired" before it can be hard-deleted. Current state: %s.', 'supplement-compare' ),
							$canonical->status
						);
					} else {
						$p = Supcomp_Deletion_Service::preview_canonical_deletion( $canonical );
						$info['preview_rows'] = array(
							__( 'Offers to orphan (canonical_product_id will be nulled)', 'supplement-compare' ) => (string) $p['offer_count'],
							__( 'Click-log rows to preserve (canonical_product_id will be nulled)', 'supplement-compare' ) => (string) $p['click_log_rows'],
						);
					}
				}
				break;
		}

		return $info;
	}

	/**
	 * Build a delete-confirmation URL for use in admin screens' actions
	 * columns. Returns empty string if the row isn't in soft-trash state
	 * (so the caller can omit the link entirely).
	 */
	public static function confirm_url( $type, $id, $return = '' ) {
		$args = array(
			'page' => self::PAGE_SLUG,
			'type' => $type,
			'id'   => (int) $id,
		);
		if ( $return ) {
			$args['return'] = $return;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
