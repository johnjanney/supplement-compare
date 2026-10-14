<?php
/**
 * Learned Mappings admin screen (v1.41.0).
 *
 * Lists the title → canonical mappings the matcher has learned from operator
 * approvals (Supcomp_Match_Memory_Repo). The operator can re-point a mapping
 * at a different canonical or delete it — the escape hatch for one bad
 * approval that would otherwise keep producing the same wrong suggestion.
 * "Rebuild from approved offers" re-runs the one-time backfill; it only adds
 * missing mappings and never overwrites existing ones.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Match_Memory_Screen {

	const PAGE_SLUG = 'supcomp-match-memory';
	const NONCE     = 'supcomp_match_memory_action';
	const PER_PAGE  = 50;

	public static function register_hooks() {
		add_action( 'admin_post_supcomp_match_memory_action', array( __CLASS__, 'handle_action' ) );
	}

	public static function render() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'supplement-compare' ) );
		}

		$search     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page       = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$total      = Supcomp_Match_Memory_Repo::count_all( $search );
		$rows       = Supcomp_Match_Memory_Repo::paginate( $page, self::PER_PAGE, $search );
		$canonicals = Supcomp_Canonical_Products_Repo::for_picker();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Learned Mappings', 'supplement-compare' ); ?></h1>
			<span class="title-count">&nbsp;<?php echo esc_html( sprintf( _n( '%d mapping', '%d mappings', $total, 'supplement-compare' ), $total ) ); ?></span>
			<hr class="wp-header-end">

			<p class="description" style="max-width:60em">
				<?php esc_html_e( 'Each time you approve an offer (or pick its canonical in the editor), its title is reduced to a key — strength, units, form words and punctuation removed — and remembered against the canonical. New offers with the same key get that canonical as their suggested match: 0.90 when learned at the same merchant, 0.80 when learned elsewhere. Suggestions still need your approval. Fix or delete a mapping here if an approval was wrong.', 'supplement-compare' ); ?>
			</p>

			<?php self::render_notice(); ?>

			<form method="get" style="display:inline-block">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search key or title…', 'supplement-compare' ); ?>">
				<?php submit_button( __( 'Search', 'supplement-compare' ), '', '', false ); ?>
				<?php if ( $search !== '' ) : ?>
					<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Clear', 'supplement-compare' ); ?></a>
				<?php endif; ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:1em">
				<input type="hidden" name="action" value="supcomp_match_memory_action">
				<input type="hidden" name="op" value="rebuild">
				<?php wp_nonce_field( self::NONCE ); ?>
				<button type="submit" class="button" title="<?php esc_attr_e( 'Adds mappings for approved offers that are not remembered yet. Never overwrites an existing mapping.', 'supplement-compare' ); ?>"><?php esc_html_e( 'Rebuild from approved offers', 'supplement-compare' ); ?></button>
			</form>

			<?php if ( empty( $rows ) ) : ?>
				<p><em>
					<?php
					echo $search !== ''
						? esc_html( sprintf( __( 'No mappings match “%s”.', 'supplement-compare' ), $search ) )
						: esc_html__( 'Nothing learned yet. Mappings are added automatically when you approve offers that have a canonical.', 'supplement-compare' );
					?>
				</em></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped" style="margin-top:1em">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Title key', 'supplement-compare' ); ?></th>
							<th><?php esc_html_e( 'Merchant', 'supplement-compare' ); ?></th>
							<th style="width:32%"><?php esc_html_e( 'Canonical product', 'supplement-compare' ); ?></th>
							<th style="width:7em"><?php esc_html_e( 'Confirmed', 'supplement-compare' ); ?></th>
							<th style="width:10em"><?php esc_html_e( 'Last confirmed', 'supplement-compare' ); ?></th>
							<th style="width:6em"><?php esc_html_e( 'Action', 'supplement-compare' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $r ) : ?>
							<tr>
								<td>
									<code><?php echo esc_html( $r->title_key ); ?></code>
									<?php if ( $r->sample_title !== '' ) : ?>
										<p class="description" style="margin:.2em 0 0"><?php echo esc_html( sprintf( __( 'e.g. “%s”', 'supplement-compare' ), $r->sample_title ) ); ?></p>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $r->merchant_name !== null ? $r->merchant_name : sprintf( '#%d', (int) $r->merchant_id ) ); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="supcomp_match_memory_action">
										<input type="hidden" name="op" value="repoint">
										<input type="hidden" name="id" value="<?php echo (int) $r->id; ?>">
										<?php wp_nonce_field( self::NONCE ); ?>
										<select name="canonical_product_id" style="max-width:70%">
											<?php if ( $r->canonical_status === null || $r->canonical_status === 'retired' ) : ?>
												<option value="" selected><?php esc_html_e( '(retired or missing — ignored)', 'supplement-compare' ); ?></option>
											<?php endif; ?>
											<?php foreach ( $canonicals as $cp ) : ?>
												<option value="<?php echo (int) $cp->id; ?>" <?php selected( (int) $r->canonical_product_id, (int) $cp->id ); ?>>
													<?php echo esc_html( $cp->ingredient_name ? $cp->ingredient_name . ' — ' . $cp->display_name : $cp->display_name ); ?>
												</option>
											<?php endforeach; ?>
										</select>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Save', 'supplement-compare' ); ?></button>
									</form>
								</td>
								<td>
									<?php echo (int) $r->times_confirmed; ?>×
									<p class="description" style="margin:0"><?php echo esc_html( $r->source ); ?></p>
								</td>
								<td><?php echo esc_html( self::pretty_date( $r->last_confirmed_at ) ); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this mapping? Offers already matched keep their canonical; new offers with this title key will no longer get this suggestion.', 'supplement-compare' ) ); ?>');">
										<input type="hidden" name="action" value="supcomp_match_memory_action">
										<input type="hidden" name="op" value="delete">
										<input type="hidden" name="id" value="<?php echo (int) $r->id; ?>">
										<?php wp_nonce_field( self::NONCE ); ?>
										<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'supplement-compare' ); ?></button>
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

	public static function handle_action() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'supplement-compare' ) );
		}
		check_admin_referer( self::NONCE );

		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$args = array( 'supcomp_notice' => 'error' );

		if ( $op === 'delete' && $id > 0 ) {
			$args['supcomp_notice'] = Supcomp_Match_Memory_Repo::delete( $id ) ? 'deleted' : 'error';
		} elseif ( $op === 'repoint' && $id > 0 ) {
			$canonical_id = isset( $_POST['canonical_product_id'] ) ? absint( $_POST['canonical_product_id'] ) : 0;
			$row          = Supcomp_Match_Memory_Repo::get( $id );
			if ( $row && $canonical_id > 0 && Supcomp_Match_Memory_Repo::set_canonical( $id, $canonical_id ) ) {
				$result                 = Supcomp_Rematcher::rematch_for_keys( array( $row->title_key ) );
				$args['supcomp_notice'] = 'repointed';
				$args['m']              = $result['matched'];
			}
		} elseif ( $op === 'rebuild' ) {
			$keys                   = Supcomp_Match_Memory_Repo::backfill_from_approved();
			$result                 = Supcomp_Rematcher::rematch_for_keys( $keys );
			$args['supcomp_notice'] = 'rebuilt';
			$args['n']              = count( $keys );
			$args['m']              = $result['matched'];
		}

		wp_safe_redirect( add_query_arg( $args, self::url() ) );
		exit;
	}

	private static function url() {
		return add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) );
	}

	private static function render_notice() {
		$type = isset( $_GET['supcomp_notice'] ) ? sanitize_key( wp_unslash( $_GET['supcomp_notice'] ) ) : '';
		$n    = isset( $_GET['n'] ) ? (int) wp_unslash( $_GET['n'] ) : 0;
		$m    = isset( $_GET['m'] ) ? (int) wp_unslash( $_GET['m'] ) : 0;
		$messages = array(
			'deleted'   => array( 'success', __( 'Mapping deleted.', 'supplement-compare' ) ),
			'repointed' => array( 'success', sprintf( __( 'Mapping updated. %d unmatched queue offers picked up the new suggestion.', 'supplement-compare' ), $m ) ),
			'rebuilt'   => array( 'success', sprintf( __( 'Added %1$d mappings from approved offers. %2$d unmatched queue offers now have a suggestion.', 'supplement-compare' ), $n, $m ) ),
			'error'     => array( 'error', __( 'Something went wrong — the mapping may have been removed, or the canonical is retired.', 'supplement-compare' ) ),
		);
		if ( ! isset( $messages[ $type ] ) ) {
			return;
		}
		list( $class, $text ) = $messages[ $type ];
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $text ) );
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
			if ( $i === (int) $page ) {
				printf( '<span class="button button-primary" style="margin:0 .2em">%d</span>', $i );
			} else {
				printf( '<a class="button" style="margin:0 .2em" href="%s">%d</a>', esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ), $i );
			}
		}
		echo '</p>';
	}

	private static function pretty_date( $mysql_utc ) {
		if ( empty( $mysql_utc ) ) {
			return '—';
		}
		$ts = strtotime( $mysql_utc . ' UTC' );
		return $ts === false ? (string) $mysql_utc : wp_date( 'Y-m-d H:i', $ts );
	}
}
