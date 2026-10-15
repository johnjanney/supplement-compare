<?php
/**
 * Pending queue admin screen. The operator's main daily workflow.
 *
 * Lists offers with visibility in {pending, needs_review} alongside a
 * suggested canonical match and a confidence score. The Phase 6 brief's
 * "under 10 seconds for clean cases" target is supported by:
 *   - per-row Approve / Reject quick-buttons (one click each)
 *   - bulk Approve / Reject / Defer
 *   - confidence-threshold filter for grouping high-confidence rows
 *
 * Detail/edit view delegates to Supcomp_Offer_Form which is shared with
 * the Active Offers screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Pending_Queue_Screen {

	const PAGE_SLUG  = 'supcomp-pending';
	const NONCE_ROW  = 'supcomp_offer_row_action';
	const NONCE_BULK = 'supcomp_offer_bulk_action';
	const PER_PAGE   = 20;

	public static function register_hooks() {
		add_action( 'admin_post_supcomp_offer_row_action', array( __CLASS__, 'handle_row_action' ) );
		add_action( 'admin_post_supcomp_offer_bulk_action', array( __CLASS__, 'handle_bulk_action' ) );
		add_action( 'admin_post_supcomp_dismiss_blend_recheck', array( __CLASS__, 'handle_dismiss_blend_recheck' ) );
	}

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

	// ---------- list ----------

	private static function render_list() {
		$args         = self::query_args_from_request();
		$args['visibility'] = array( 'pending', 'needs_review' );

		$rows  = Supcomp_Offers_Repo::query_for_admin( $args );
		$total = Supcomp_Offers_Repo::count_for_admin( $args );

		$merchants   = Supcomp_Merchants_Repo::active_for_select();
		$ingredients = Supcomp_Ingredients_Repo::active_for_select();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Pending Queue', 'supplement-compare' ); ?></h1>
			<span class="title-count">&nbsp;<?php echo esc_html( sprintf( __( '%d awaiting review', 'supplement-compare' ), (int) $total ) ); ?></span>
			<hr class="wp-header-end">

			<?php self::render_notice(); ?>
			<?php self::render_blend_recheck(); ?>

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

					<select name="min_confidence">
						<option value="0" <?php selected( (float) $args['min_confidence'], 0 ); ?>><?php esc_html_e( 'Any confidence', 'supplement-compare' ); ?></option>
						<option value="0.65" <?php selected( (float) $args['min_confidence'], 0.65 ); ?>><?php esc_html_e( '≥ 0.65', 'supplement-compare' ); ?></option>
						<option value="0.85" <?php selected( (float) $args['min_confidence'], 0.85 ); ?>><?php esc_html_e( '≥ 0.85', 'supplement-compare' ); ?></option>
						<option value="0.95" <?php selected( (float) $args['min_confidence'], 0.95 ); ?>><?php esc_html_e( '≥ 0.95', 'supplement-compare' ); ?></option>
					</select>

					<select name="has_canonical">
						<option value="" <?php selected( $args['has_canonical'], '' ); ?>><?php esc_html_e( 'Any match state', 'supplement-compare' ); ?></option>
						<option value="yes" <?php selected( $args['has_canonical'], 'yes' ); ?>><?php esc_html_e( 'With canonical', 'supplement-compare' ); ?></option>
						<option value="no" <?php selected( $args['has_canonical'], 'no' ); ?>><?php esc_html_e( 'No canonical', 'supplement-compare' ); ?></option>
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
							<option value="approve"><?php esc_html_e( 'Approve', 'supplement-compare' ); ?></option>
							<option value="reject"><?php esc_html_e( 'Reject', 'supplement-compare' ); ?></option>
							<option value="defer"><?php esc_html_e( 'Defer (needs review)', 'supplement-compare' ); ?></option>
							<option value="pause"><?php esc_html_e( 'Pause', 'supplement-compare' ); ?></option>
							<option value="rematch"><?php esc_html_e( 'Re-run matching', 'supplement-compare' ); ?></option>
							<option value="fill_totals"><?php esc_html_e( 'Fill missing totals', 'supplement-compare' ); ?></option>
						</select>
						<?php submit_button( __( 'Apply', 'supplement-compare' ), '', '', false ); ?>
					</div>
					<div class="alignleft actions">
						<button type="submit" name="rematch_all" value="1" class="button" title="<?php esc_attr_e( 'Runs canonical matching again for every offer in the queue that has no canonical yet. Offers that already have one are left alone.', 'supplement-compare' ); ?>"><?php esc_html_e( 'Re-run matching on all unmatched', 'supplement-compare' ); ?></button>
						<button type="submit" name="fill_totals_all" value="1" class="button" title="<?php esc_attr_e( 'Suggests Total active / container for queue offers that have none, where the listing clearly states it: one amount in a single container, a pack (amount × vials, or a stated pack total), or a blend (sum of its parts, or a stated blend total). Totals you entered or cleared are never touched.', 'supplement-compare' ); ?>"><?php esc_html_e( 'Fill missing totals', 'supplement-compare' ); ?></button>
					</div>
				</div>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<td class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all-1"></td>
							<th><?php esc_html_e( 'Offer', 'supplement-compare' ); ?></th>
							<th><?php esc_html_e( 'Merchant', 'supplement-compare' ); ?></th>
							<th><?php esc_html_e( 'Total active / container', 'supplement-compare' ); ?></th>
							<th><?php esc_html_e( 'Price', 'supplement-compare' ); ?></th>
							<th><?php esc_html_e( 'Cost / active unit', 'supplement-compare' ); ?></th>
							<th><?php esc_html_e( 'Suggested match', 'supplement-compare' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'supplement-compare' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr><td colspan="8"><?php esc_html_e( 'No offers awaiting review.', 'supplement-compare' ); ?></td></tr>
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
									<?php
									// Total active per container = total_strength (derived, PROJECTBRIEF.md §6).
									$unit  = $r->strength_unit ? $r->strength_unit : ( $r->ingredient_unit ? $r->ingredient_unit : '' );
									$total_active = self::trim_decimal( $r->total_strength );
									if ( $total_active === '' && $r->strength_per_serving !== null && $r->servings_per_container !== null ) {
										$total_active = self::trim_decimal( (float) $r->strength_per_serving * (int) $r->servings_per_container );
									}
									echo esc_html( $total_active !== '' ? trim( $total_active . ' ' . $unit ) : '—' );
									if ( ! empty( $r->total_active_source ) && $r->total_active_per_container !== null ) {
										printf(
											' <span class="supcomp-auto-badge" title="%s" style="background:#f0f0f1;color:#50575e;padding:1px 5px;border-radius:3px;font-size:11px">%s</span>',
											esc_attr( sprintf( __( 'Filled automatically: %s. Check before approving.', 'supplement-compare' ), Supcomp_Total_Filler::source_label( $r->total_active_source ) ) ),
											esc_html__( 'auto', 'supplement-compare' )
										);
									}
									?>
								</td>
								<td>
									<?php
									if ( $r->current_price !== null ) {
										echo esc_html( ( $r->currency ? $r->currency . ' ' : '' ) . number_format( (float) $r->current_price, 2 ) );
										if ( (int) $r->on_sale ) {
											echo ' <span style="color:#d63638">⬇</span>';
										}
									} else {
										echo '—';
									}
									?>
								</td>
								<td>
									<?php
									if ( $r->cost_per_active_unit !== null ) {
										echo esc_html( ( $r->currency ? $r->currency . ' ' : '' ) . number_format( (float) $r->cost_per_active_unit, 6 ) );
									} else {
										echo '<span style="color:#888">—</span>';
									}
									?>
								</td>
								<td>
									<?php if ( $r->canonical_product_id ) : ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=supcomp-canonical&action=edit&id=' . (int) $r->canonical_product_id ) ); ?>"><?php echo esc_html( $r->canonical_display_name ); ?></a>
									<?php else : ?>
										<span style="color:#888"><?php esc_html_e( 'No canonical', 'supplement-compare' ); ?></span>
									<?php endif; ?>
									<br><?php Supcomp_Offer_Form::render_confidence_badge( $r->match_confidence ); ?>
								</td>
								<td>
									<a href="<?php echo esc_url( self::url( array( 'action' => 'edit', 'id' => $r->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'supplement-compare' ); ?></a>
									<?php self::render_row_action( $r->id, 'approve', __( 'Approve', 'supplement-compare' ), 'button-primary' ); ?>
									<?php self::render_row_action( $r->id, 'reject', __( 'Reject', 'supplement-compare' ), 'button-link-delete' ); ?>
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

	private static function render_row_action( $offer_id, $action, $label, $class ) {
		// Row actions are nonced links, NOT nested forms. Nesting a <form>
		// inside the bulk-action <form> that wraps the table is invalid HTML
		// and the browser closes the outer form at the first row, breaking
		// both bulk selection and row-action routing. Links sidestep that
		// (the standard WP_List_Table pattern). The handler verifies the
		// per-offer nonce and the manage_options capability.
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
			' <a href="%s" class="button button-small %s">%s</a>',
			esc_url( $url ),
			esc_attr( $class ),
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
				$next_off = ( $cur ) * $per;
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

	// ---------- POST handlers ----------

	public static function handle_row_action() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'supplement-compare' ) );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( self::NONCE_ROW . '_' . $id );

		$action = isset( $_GET['row_action'] ) ? sanitize_key( wp_unslash( $_GET['row_action'] ) ) : '';
		$return = isset( $_GET['return'] ) ? esc_url_raw( wp_unslash( $_GET['return'] ) ) : self::url();

		$map = array(
			'approve' => 'active',
			'reject'  => 'rejected',
			'pause'   => 'paused',
			'defer'   => 'needs_review',
			'review'  => 'needs_review',
		);
		$notice = 'error';
		if ( $id && isset( $map[ $action ] ) ) {
			$ok = Supcomp_Offers_Repo::set_visibility( $id, $map[ $action ] );
			if ( $ok && $action === 'approve' ) {
				self::learn_from_approved( array( $id ) );
			}
			$notice = $ok ? $action . 'd' : 'error';
			if ( $action === 'defer' || $action === 'review' ) {
				$notice = 'deferred';
			}
			if ( $ok ) {
				do_action( 'supcomp_data_changed', array( 'source' => 'offer_row_action', 'offer_id' => $id, 'action' => $action ) );
			}
		}
		wp_safe_redirect( add_query_arg( 'supcomp_notice', $notice, $return ) );
		exit;
	}

	/**
	 * Approved offers whose automatic blend total 1.44.0 may have doubled
	 * (Supcomp_Total_Filler::repair_blend_totals_once()). Listed until the
	 * operator dismisses it; nothing is changed automatically.
	 */
	private static function render_blend_recheck() {
		$ids = get_option( Supcomp_Total_Filler::BLEND_RECHECK_OPTION, array() );
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return;
		}
		$links = array();
		foreach ( $ids as $id ) {
			$links[] = sprintf(
				'<a href="%s">#%d</a>',
				esc_url( add_query_arg( array( 'page' => 'supcomp-active', 'action' => 'edit', 'id' => (int) $id ), admin_url( 'admin.php' ) ) ),
				(int) $id
			);
		}
		?>
		<div class="notice notice-warning">
			<p>
				<?php echo esc_html( sprintf( _n( '%d approved offer has an automatic blend total that version 1.44.0 may have doubled. Check it in the offer editor:', '%d approved offers have an automatic blend total that version 1.44.0 may have doubled. Check them in the offer editor:', count( $ids ), 'supplement-compare' ), count( $ids ) ) ); ?>
				<?php echo wp_kses( implode( ', ', $links ), array( 'a' => array( 'href' => array() ) ) ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="supcomp_dismiss_blend_recheck">
				<?php wp_nonce_field( 'supcomp_dismiss_blend_recheck' ); ?>
				<p><button type="submit" class="button button-small"><?php esc_html_e( 'Dismiss — I have checked these', 'supplement-compare' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	public static function handle_dismiss_blend_recheck() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'supplement-compare' ) );
		}
		check_admin_referer( 'supcomp_dismiss_blend_recheck' );
		delete_option( Supcomp_Total_Filler::BLEND_RECHECK_OPTION );
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function handle_bulk_action() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'supplement-compare' ) );
		}
		check_admin_referer( self::NONCE_BULK );

		$bulk   = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$ids    = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array();
		$return = isset( $_POST['return'] ) ? esc_url_raw( wp_unslash( $_POST['return'] ) ) : self::url();

		// Re-run matching: the toolbar button covers the whole queue, the bulk
		// option covers the ticked rows. Either way only offers without a
		// canonical are touched (Supcomp_Rematcher).
		if ( ! empty( $_POST['rematch_all'] ) || $bulk === 'rematch' ) {
			if ( empty( $_POST['rematch_all'] ) && empty( $ids ) ) {
				wp_safe_redirect( add_query_arg( 'supcomp_notice', 'noop', $return ) );
				exit;
			}
			$result = Supcomp_Rematcher::rematch_unmatched( empty( $_POST['rematch_all'] ) ? $ids : null );
			wp_safe_redirect(
				add_query_arg(
					array(
						'supcomp_notice' => 'rematched',
						'n'              => $result['checked'],
						'm'              => $result['matched'],
						'partial'        => $result['complete'] ? 0 : 1,
					),
					$return
				)
			);
			exit;
		}

		// Fill missing totals (v1.43.0): toolbar button = whole queue, bulk
		// option = ticked rows. Only blank, non-operator totals are filled.
		if ( ! empty( $_POST['fill_totals_all'] ) || $bulk === 'fill_totals' ) {
			if ( empty( $_POST['fill_totals_all'] ) && empty( $ids ) ) {
				wp_safe_redirect( add_query_arg( 'supcomp_notice', 'noop', $return ) );
				exit;
			}
			$result = Supcomp_Total_Filler::fill( empty( $_POST['fill_totals_all'] ) ? $ids : null );
			if ( $result['filled'] > 0 ) {
				do_action( 'supcomp_data_changed', array( 'source' => 'fill_totals', 'count' => $result['filled'] ) );
			}
			wp_safe_redirect(
				add_query_arg(
					array(
						'supcomp_notice' => 'filled_totals',
						'n'              => $result['checked'],
						'm'              => $result['filled'],
						'partial'        => $result['complete'] ? 0 : 1,
					),
					$return
				)
			);
			exit;
		}

		$map = array(
			'approve' => 'active',
			'reject'  => 'rejected',
			'pause'   => 'paused',
			'defer'   => 'needs_review',
		);
		if ( ! isset( $map[ $bulk ] ) || empty( $ids ) ) {
			wp_safe_redirect( add_query_arg( 'supcomp_notice', 'noop', $return ) );
			exit;
		}
		$n = Supcomp_Offers_Repo::bulk_set_visibility( $ids, $map[ $bulk ] );
		if ( $n > 0 && $bulk === 'approve' ) {
			self::learn_from_approved( $ids );
		}
		if ( $n > 0 ) {
			do_action( 'supcomp_data_changed', array( 'source' => 'offer_bulk_action', 'count' => $n, 'action' => $bulk ) );
		}
		wp_safe_redirect( add_query_arg( array( 'supcomp_notice' => 'bulk_' . $bulk, 'n' => $n ), $return ) );
		exit;
	}

	// ---------- helpers ----------

	/**
	 * Approving an offer confirms its canonical: remember the title → canonical
	 * mapping, then give other unmatched queue offers with the same title key
	 * a matching pass so they pick the suggestion up immediately.
	 */
	private static function learn_from_approved( array $ids ) {
		$keys = array();
		foreach ( array_filter( array_map( 'absint', $ids ) ) as $id ) {
			$key = Supcomp_Match_Memory_Repo::learn_from_offer( Supcomp_Offers_Repo::get( $id ), 'approval' );
			if ( $key !== null ) {
				$keys[ $key ] = true;
			}
		}
		if ( $keys ) {
			Supcomp_Rematcher::rematch_for_keys( array_keys( $keys ) );
		}
	}

	public static function url( $args = array() ) {
		$args = array_merge( array( 'page' => self::PAGE_SLUG ), $args );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private static function current_url() {
		// Preserve only the known filter/sort/pagination params rather than
		// reflecting every $_GET key — keys are attacker-pollutable and the
		// result is echoed back into the page (escaped, but allowlisting
		// removes the class entirely).
		$allowed = array( 's', 'merchant_id', 'ingredient_id', 'min_confidence', 'has_canonical', 'orderby', 'order', 'offset', 'paged' );
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
			'min_confidence' => isset( $_GET['min_confidence'] ) ? (float) wp_unslash( $_GET['min_confidence'] ) : 0,
			'has_canonical'  => isset( $_GET['has_canonical'] ) ? sanitize_key( wp_unslash( $_GET['has_canonical'] ) ) : '',
			'search'         => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'orderby'        => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'updated_at',
			'order'          => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'DESC',
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
		$m    = isset( $_GET['m'] ) ? (int) wp_unslash( $_GET['m'] ) : 0;
		$partial = ! empty( $_GET['partial'] );
		$messages = array(
			'approved'      => array( 'success', __( 'Offer approved.', 'supplement-compare' ) ),
			'rejected'      => array( 'success', __( 'Offer rejected.', 'supplement-compare' ) ),
			'paused'        => array( 'success', __( 'Offer paused.', 'supplement-compare' ) ),
			'deferred'      => array( 'success', __( 'Offer deferred.', 'supplement-compare' ) ),
			'bulk_approve'  => array( 'success', sprintf( __( 'Approved %d offers.', 'supplement-compare' ), $n ) ),
			'bulk_reject'   => array( 'success', sprintf( __( 'Rejected %d offers.', 'supplement-compare' ), $n ) ),
			'bulk_pause'    => array( 'success', sprintf( __( 'Paused %d offers.', 'supplement-compare' ), $n ) ),
			'bulk_defer'    => array( 'success', sprintf( __( 'Deferred %d offers.', 'supplement-compare' ), $n ) ),
			'rematched'     => array( 'success', sprintf( __( 'Re-ran matching on %1$d unmatched offers; %2$d now have a suggested canonical. Offers that already had a canonical were skipped.', 'supplement-compare' ), $n, $m ) . ( $partial ? ' ' . __( 'Stopped early to avoid a timeout — click "Re-run matching on all unmatched" again to continue.', 'supplement-compare' ) : '' ) ),
			'filled_totals' => array( 'success', sprintf( __( 'Checked %1$d queue offers with no total; filled %2$d. Each is marked "auto" — check it before approving.', 'supplement-compare' ), $n, $m ) . ( $partial ? ' ' . __( 'Stopped early to avoid a timeout — click "Fill missing totals" again to continue.', 'supplement-compare' ) : '' ) ),
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
