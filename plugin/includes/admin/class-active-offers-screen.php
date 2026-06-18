<?php
/**
 * Active offers admin screen. Mirror of the pending queue, filtered to
 * visibility=active. Same edit form (delegated to Supcomp_Offer_Form).
 *
 * Per-row actions are scoped to what makes sense for an already-published
 * offer: Edit, Pause (operator wants to hide it without rejecting), Defer
 * (operator wants to re-review). Bulk actions: Pause, Defer.
 *
 * The Pending Queue screen owns the admin_post handlers for save / row /
 * bulk — both screens use the same handler names, so we delegate by
 * directing forms there. (We could split, but having one handler per
 * action keeps the routing simple.)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Active_Offers_Screen {

	const PAGE_SLUG  = 'supcomp-active';
	const NONCE_ROW  = 'supcomp_offer_row_action';
	const NONCE_BULK = 'supcomp_offer_bulk_action';
	const PER_PAGE   = 20;

	public static function render() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'supplement-compare' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		if ( $action === 'edit' ) {
			$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
			Supcomp_Offer_Form::render( $id, self::url() );
			return;
		}
		self::render_list();
	}

	private static function render_list() {
		$args               = self::query_args_from_request();
		$args['visibility'] = array( 'active' );

		$rows  = Supcomp_Offers_Repo::query_for_admin( $args );
		$total = Supcomp_Offers_Repo::count_for_admin( $args );

		$merchants   = Supcomp_Merchants_Repo::active_for_select();
		$ingredients = Supcomp_Ingredients_Repo::active_for_select();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Active Offers', 'supplement-compare' ); ?></h1>
			<span class="title-count">&nbsp;<?php echo esc_html( sprintf( __( '%d live', 'supplement-compare' ), (int) $total ) ); ?></span>
			<hr class="wp-header-end">

			<?php self::render_notice(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<p class="search-box">
					<input type="search" name="s" value="<?php echo esc_attr( $args['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search title, brand, SKU…', 'supplement-compare' ); ?>">

					<select name="merchant_id">
						<option value="0"><?php esc_html_e( 'All merchants', 'supplement-compare' ); ?></option>
						<?php foreach ( $merchants as $m ) : ?>
							<option value="<?php echo (int) $m->id; ?>" <?php selected( (int) $args['merchant_id'], (int) $m->id ); ?>><?php echo esc_html( $m->name ); ?></option>
						<?php endforeach; ?>
					</select>

					<select name="ingredient_id">
						<option value="0"><?php esc_html_e( 'All ingredients', 'supplement-compare' ); ?></option>
						<?php foreach ( $ingredients as $i ) : ?>
							<option value="<?php echo (int) $i->id; ?>" <?php selected( (int) $args['ingredient_id'], (int) $i->id ); ?>><?php echo esc_html( $i->name ); ?></option>
						<?php endforeach; ?>
					</select>

					<select name="has_canonical">
						<option value=""><?php esc_html_e( 'Any canonical state', 'supplement-compare' ); ?></option>
						<option value="no" <?php selected( $args['has_canonical'], 'no' ); ?>><?php esc_html_e( 'No canonical assigned', 'supplement-compare' ); ?></option>
						<option value="yes" <?php selected( $args['has_canonical'], 'yes' ); ?>><?php esc_html_e( 'Has canonical', 'supplement-compare' ); ?></option>
					</select>

					<?php submit_button( __( 'Filter', 'supplement-compare' ), '', '', false ); ?>
				</p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="supcomp_offer_bulk_action">
				<input type="hidden" name="return" value="<?php echo esc_attr( self::current_url() ); ?>">
				<?php wp_nonce_field( self::NONCE_BULK ); ?>

				<div class="tablenav top">
					<div class="alignleft actions bulkactions">
						<select name="bulk_action">
							<option value=""><?php esc_html_e( 'Bulk actions', 'supplement-compare' ); ?></option>
							<option value="pause"><?php esc_html_e( 'Pause', 'supplement-compare' ); ?></option>
							<option value="defer"><?php esc_html_e( 'Defer (re-review)', 'supplement-compare' ); ?></option>
							<option value="reject"><?php esc_html_e( 'Reject', 'supplement-compare' ); ?></option>
						</select>
						<?php submit_button( __( 'Apply', 'supplement-compare' ), '', '', false ); ?>
					</div>
				</div>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<td class="manage-column column-cb check-column"><input type="checkbox"></td>
							<th><?php esc_html_e( 'Offer', 'supplement-compare' ); ?></th>
							<th><?php esc_html_e( 'Merchant', 'supplement-compare' ); ?></th>
							<th><?php esc_html_e( 'Canonical', 'supplement-compare' ); ?></th>
							<th><?php esc_html_e( 'Total active / container', 'supplement-compare' ); ?></th>
							<th><?php esc_html_e( 'Price', 'supplement-compare' ); ?></th>
							<th><?php esc_html_e( 'Cost / active unit', 'supplement-compare' ); ?></th>
							<th><?php esc_html_e( 'Stock', 'supplement-compare' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'supplement-compare' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr><td colspan="9"><?php esc_html_e( 'No active offers. Approve some pending offers to populate the public site.', 'supplement-compare' ); ?></td></tr>
						<?php endif; ?>

						<?php foreach ( $rows as $r ) : ?>
							<tr>
								<th class="check-column"><input type="checkbox" name="ids[]" value="<?php echo (int) $r->id; ?>"></th>
								<td>
									<strong><a href="<?php echo esc_url( self::url( array( 'action' => 'edit', 'id' => $r->id ) ) ); ?>"><?php echo esc_html( $r->product_title ); ?></a></strong>
									<?php if ( $r->variant_title ) : ?>
										<br><span class="description"><?php echo esc_html( $r->variant_title ); ?></span>
									<?php endif; ?>
									<?php if ( $r->brand ) : ?>
										<br><span class="description"><?php echo esc_html( $r->brand ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $r->merchant_name ? $r->merchant_name : '(missing)' ); ?></td>
								<td>
									<?php if ( $r->canonical_product_id ) : ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=supcomp-canonical&action=edit&id=' . (int) $r->canonical_product_id ) ); ?>"><?php echo esc_html( $r->canonical_display_name ); ?></a>
									<?php else : ?>
										<span style="color:#888">—</span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									// Total active per container = total_strength (derived, PROJECTBRIEF.md §6).
									$unit  = $r->strength_unit ? $r->strength_unit : ( $r->ingredient_unit ? $r->ingredient_unit : '' );
									$total = self::trim_decimal( $r->total_strength );
									if ( $total === '' && $r->strength_per_serving !== null && $r->servings_per_container !== null ) {
										$total = self::trim_decimal( (float) $r->strength_per_serving * (int) $r->servings_per_container );
									}
									echo esc_html( $total !== '' ? trim( $total . ' ' . $unit ) : '—' );
									?>
								</td>
								<td>
									<?php echo $r->current_price !== null ? esc_html( ( $r->currency ? $r->currency . ' ' : '' ) . number_format( (float) $r->current_price, 2 ) ) : '—'; ?>
								</td>
								<td>
									<?php echo $r->cost_per_active_unit !== null ? esc_html( ( $r->currency ? $r->currency . ' ' : '' ) . number_format( (float) $r->cost_per_active_unit, 6 ) ) : '<span style="color:#888">—</span>'; ?>
								</td>
								<td><?php echo esc_html( $r->stock_status ); ?></td>
								<td>
									<a href="<?php echo esc_url( self::url( array( 'action' => 'edit', 'id' => $r->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'supplement-compare' ); ?></a>
									<?php self::render_row_action( $r->id, 'pause', __( 'Pause', 'supplement-compare' ) ); ?>
									<?php self::render_row_action( $r->id, 'defer', __( 'Re-review', 'supplement-compare' ) ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php self::render_pagination( $total, $args ); ?>
			</form>
		</div>
		<?php
	}

	private static function render_row_action( $offer_id, $action, $label ) {
		// Row actions are nonced links, NOT nested forms — see the matching
		// note in Supcomp_Pending_Queue_Screen::render_row_action(). Nesting a
		// <form> inside the bulk-action form breaks both bulk selection and
		// row-action routing. Handler is the shared admin_post_supcomp_offer_row_action.
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'supcomp_offer_row_action',
					'id'         => (int) $offer_id,
					'row_action' => $action,
					'return'     => self::current_url(),
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_ROW . '_' . $offer_id
		);
		printf(
			' <a href="%s" class="button button-small button-link-delete">%s</a>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	private static function render_pagination( $total, $args ) {
		$per   = (int) $args['limit'];
		$pages = max( 1, (int) ceil( $total / $per ) );
		$cur   = (int) floor( $args['offset'] / $per ) + 1;
		if ( $pages <= 1 ) {
			return;
		}
		?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num"><?php echo esc_html( sprintf( _n( '%d offer', '%d offers', $total, 'supplement-compare' ), $total ) ); ?></span>
				<?php
				$prev_off = max( 0, ( $cur - 2 ) * $per );
				$next_off = $cur * $per;
				if ( $cur > 1 ) {
					echo '<a class="prev-page button" href="' . esc_url( add_query_arg( 'offset', $prev_off ) ) . '">‹</a> ';
				}
				echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'supplement-compare' ), $cur, $pages ) );
				if ( $cur < $pages ) {
					echo ' <a class="next-page button" href="' . esc_url( add_query_arg( 'offset', $next_off ) ) . '">›</a>';
				}
				?>
			</div>
		</div>
		<?php
	}

	public static function url( $args = array() ) {
		$args = array_merge( array( 'page' => self::PAGE_SLUG ), $args );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private static function current_url() {
		// Preserve only the known filter/sort/pagination params rather than
		// reflecting every $_GET key (keys are attacker-pollutable; the result
		// is echoed back into the page).
		$allowed = array( 's', 'merchant_id', 'ingredient_id', 'has_canonical', 'orderby', 'order', 'offset', 'paged' );
		$args    = array( 'page' => self::PAGE_SLUG );
		foreach ( $allowed as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private static function query_args_from_request() {
		return array(
			'merchant_id'    => isset( $_GET['merchant_id'] ) ? absint( wp_unslash( $_GET['merchant_id'] ) ) : 0,
			'ingredient_id'  => isset( $_GET['ingredient_id'] ) ? absint( wp_unslash( $_GET['ingredient_id'] ) ) : 0,
			'has_canonical'  => isset( $_GET['has_canonical'] ) && in_array( $_GET['has_canonical'], array( 'yes', 'no' ), true ) ? sanitize_key( wp_unslash( $_GET['has_canonical'] ) ) : '',
			'search'         => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'orderby'        => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'cost_per_active_unit',
			'order'          => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'ASC',
			'limit'          => self::PER_PAGE,
			'offset'         => isset( $_GET['offset'] ) ? max( 0, (int) wp_unslash( $_GET['offset'] ) ) : 0,
		);
	}

	private static function trim_decimal( $val ) {
		if ( $val === null || $val === '' ) {
			return '';
		}
		$s = (string) $val;
		if ( strpos( $s, '.' ) !== false ) {
			$s = rtrim( rtrim( $s, '0' ), '.' );
		}
		return $s;
	}

	private static function render_notice() {
		if ( empty( $_GET['supcomp_notice'] ) ) {
			return;
		}
		$type = sanitize_key( wp_unslash( $_GET['supcomp_notice'] ) );
		$n    = isset( $_GET['n'] ) ? (int) wp_unslash( $_GET['n'] ) : 0;
		$messages = array(
			'paused'        => array( 'success', __( 'Offer paused.', 'supplement-compare' ) ),
			'rejected'      => array( 'success', __( 'Offer rejected.', 'supplement-compare' ) ),
			'deferred'      => array( 'success', __( 'Offer re-queued for review.', 'supplement-compare' ) ),
			'bulk_pause'    => array( 'success', sprintf( __( 'Paused %d offers.', 'supplement-compare' ), $n ) ),
			'bulk_reject'   => array( 'success', sprintf( __( 'Rejected %d offers.', 'supplement-compare' ), $n ) ),
			'bulk_defer'    => array( 'success', sprintf( __( 'Deferred %d offers.', 'supplement-compare' ), $n ) ),
			'noop'          => array( 'warning', __( 'No bulk action / no rows selected.', 'supplement-compare' ) ),
			'error'         => array( 'error',   __( 'Something went wrong.', 'supplement-compare' ) ),
		);
		if ( ! isset( $messages[ $type ] ) ) {
			return;
		}
		list( $class, $text ) = $messages[ $type ];
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $text )
		);
	}
}
