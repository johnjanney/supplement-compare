<?php
/**
 * Cleanup admin screen — bulk hard-delete operations for the five
 * common "messy data" patterns (rejected offers, dead offers, empty dead
 * merchants, empty retired canonicals, empty retired ingredients).
 *
 * Each operation:
 *   - Shows the current count up-front.
 *   - Confirms before running (browser confirm dialog).
 *   - Runs the bulk action via Supcomp_Deletion_Service, then redirects
 *     back with a result notice.
 *
 * State gates are enforced by the service. The screen just renders the
 * counts + the trigger forms; nothing fancy. Per-row delete still goes
 * through the deletion-admin confirmation flow.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Cleanup_Screen {

	const PAGE_SLUG = 'supcomp-cleanup';
	const NONCE     = 'supcomp_run_cleanup';

	public static function register_hooks() {
		add_action( 'admin_post_supcomp_run_cleanup', array( __CLASS__, 'handle_run' ) );
	}

	public static function render() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'supplement-compare' ) );
		}

		$counts = Supcomp_Deletion_Service::cleanup_counts();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Database Cleanup', 'supplement-compare' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Bulk-delete rows that have already been soft-trashed (rejected, dead, retired). State gates are enforced — active and paused rows are never touched here. Cascade rules: per-offer audit rows are deleted; click_log rows are preserved with the FK set to NULL.', 'supplement-compare' ); ?>
			</p>

			<?php self::render_notice(); ?>

			<table class="widefat striped" style="max-width:70em">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Operation', 'supplement-compare' ); ?></th>
						<th style="width:8em;text-align:right"><?php esc_html_e( 'Eligible', 'supplement-compare' ); ?></th>
						<th style="width:14em"><?php esc_html_e( 'Action', 'supplement-compare' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					self::row(
						'rejected_offers',
						__( 'Rejected offers', 'supplement-compare' ),
						__( 'Offers where the operator clicked "Reject" — they should never have been on the public site. Cascade-deletes their price history and raw CSV snapshots; nulls click_log offer_id.', 'supplement-compare' ),
						(int) $counts['rejected_offers']
					);
					self::row(
						'dead_offers',
						__( 'Dead offers', 'supplement-compare' ),
						__( 'Offers auto-marked "dead" by the stale detector after being missing from imports past the threshold. Same cascade as rejected.', 'supplement-compare' ),
						(int) $counts['dead_offers']
					);
					self::row(
						'empty_dead_merchants',
						__( 'Empty dead merchants', 'supplement-compare' ),
						__( 'Merchants in status "dead" that have no offers. Pure orphan rows from testing or dropped affiliate relationships.', 'supplement-compare' ),
						(int) $counts['empty_dead_merchants']
					);
					self::row(
						'empty_retired_canonicals',
						__( 'Empty retired canonicals', 'supplement-compare' ),
						__( 'Canonical products in status "retired" with no offers attached. Cleared canonicals from past experiments.', 'supplement-compare' ),
						(int) $counts['empty_retired_canonicals']
					);
					self::row(
						'empty_retired_ingredients',
						__( 'Empty retired ingredients', 'supplement-compare' ),
						__( 'Ingredients in status "retired" with no canonical products and no offers referencing them. Truly orphan.', 'supplement-compare' ),
						(int) $counts['empty_retired_ingredients']
					);
					?>
				</tbody>
			</table>

			<h2 style="margin-top:2em"><?php esc_html_e( 'Stuck extractor runs', 'supplement-compare' ); ?></h2>
			<?php $stuck = Supcomp_Extract_Runs_Repo::count_open_attempts(); ?>
			<p class="description" style="max-width:70em">
				<?php esc_html_e( 'Extractor attempts still flagged "in flight" whose Action Scheduler job died mid-run (host timeout / out-of-memory). They normally self-heal once they pass the stale-run threshold (Settings → "Extractor: stale-run timeout"), and the Extractor Sites screen sweeps them on load — but you can clear the orphans immediately here. Runs that still have a live job queued are left untouched. This marks the attempt failed; it deletes no offers.', 'supplement-compare' ); ?>
			</p>
			<p>
				<strong><?php echo (int) $stuck; ?></strong> <?php esc_html_e( 'open attempt(s) currently in flight.', 'supplement-compare' ); ?>
				<?php if ( $stuck > 0 ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:.5em" onsubmit="return confirm('<?php echo esc_js( __( 'Mark all orphaned (dead) extractor runs as failed? Runs with a live job still queued are left untouched.', 'supplement-compare' ) ); ?>');">
						<input type="hidden" name="action" value="supcomp_run_cleanup">
						<input type="hidden" name="op" value="stuck_extract_runs">
						<?php wp_nonce_field( self::NONCE ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Clear stuck runs now', 'supplement-compare' ); ?></button>
					</form>
				<?php endif; ?>
			</p>

			<h2 style="margin-top:2em"><?php esc_html_e( 'How to soft-trash a row', 'supplement-compare' ); ?></h2>
			<ul style="list-style:disc;margin-left:2em">
				<li><?php echo wp_kses( __( '<strong>Offer:</strong> Pending Queue → open the row → Save &amp; Reject. Or batch via the bulk actions dropdown.', 'supplement-compare' ), array( 'strong' => array() ) ); ?></li>
				<li><?php echo wp_kses( __( '<strong>Merchant:</strong> Merchants screen → edit → Status = dead → Save. (Pause is a softer signal; only "dead" is purgeable.)', 'supplement-compare' ), array( 'strong' => array() ) ); ?></li>
				<li><?php echo wp_kses( __( '<strong>Ingredient / Canonical product:</strong> their respective screens → Retire action on the row.', 'supplement-compare' ), array( 'strong' => array() ) ); ?></li>
			</ul>
		</div>
		<?php
	}

	private static function row( $op_key, $title, $description, $count ) {
		?>
		<tr>
			<td>
				<strong><?php echo esc_html( $title ); ?></strong>
				<p class="description" style="margin:.3em 0 0"><?php echo esc_html( $description ); ?></p>
			</td>
			<td style="text-align:right;font-weight:bold"><?php echo $count; ?></td>
			<td>
				<?php if ( $count > 0 ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( sprintf( __( 'Permanently delete %d row(s)? This cannot be undone.', 'supplement-compare' ), $count ) ); ?>');">
						<input type="hidden" name="action" value="supcomp_run_cleanup">
						<input type="hidden" name="op" value="<?php echo esc_attr( $op_key ); ?>">
						<?php wp_nonce_field( self::NONCE ); ?>
						<button type="submit" class="button button-primary" style="background:#a00;border-color:#900">
							<?php esc_html_e( 'Delete all', 'supplement-compare' ); ?>
						</button>
					</form>
				<?php else : ?>
					<em><?php esc_html_e( 'Nothing to do', 'supplement-compare' ); ?></em>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	public static function handle_run() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'supplement-compare' ) );
		}
		check_admin_referer( self::NONCE );

		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		switch ( $op ) {
			case 'rejected_offers':
				$result = Supcomp_Deletion_Service::bulk_delete_rejected_offers();
				break;
			case 'dead_offers':
				$result = Supcomp_Deletion_Service::bulk_delete_dead_offers();
				break;
			case 'empty_dead_merchants':
				$result = Supcomp_Deletion_Service::bulk_delete_empty_dead_merchants();
				break;
			case 'empty_retired_canonicals':
				$result = Supcomp_Deletion_Service::bulk_delete_empty_retired_canonicals();
				break;
			case 'empty_retired_ingredients':
				$result = Supcomp_Deletion_Service::bulk_delete_empty_retired_ingredients();
				break;
			case 'stuck_extract_runs':
				// Threshold 0 = consider every open attempt; the reaper's
				// live-action guard still spares in-flight chains.
				$reap   = Supcomp_Extractor_Reaper::reap( 0 );
				$result = array( 'deleted' => (int) $reap['deleted'], 'considered' => (int) $reap['considered'] );
				break;
			default:
				wp_safe_redirect( add_query_arg( 'supcomp_notice', 'cleanup_unknown', $base ) );
				exit;
		}

		wp_safe_redirect( add_query_arg(
			array(
				'supcomp_notice'             => 'cleanup_done',
				'supcomp_cleanup_op'         => $op,
				'supcomp_cleanup_deleted'    => (int) $result['deleted'],
				'supcomp_cleanup_considered' => (int) $result['considered'],
			),
			$base
		) );
		exit;
	}

	private static function render_notice() {
		$type = isset( $_GET['supcomp_notice'] ) ? sanitize_key( wp_unslash( $_GET['supcomp_notice'] ) ) : '';
		if ( $type !== 'cleanup_done' ) {
			return;
		}
		$op         = isset( $_GET['supcomp_cleanup_op'] )         ? sanitize_key( wp_unslash( $_GET['supcomp_cleanup_op'] ) ) : '';
		$deleted    = isset( $_GET['supcomp_cleanup_deleted'] )    ? absint( $_GET['supcomp_cleanup_deleted'] )                : 0;
		$considered = isset( $_GET['supcomp_cleanup_considered'] ) ? absint( $_GET['supcomp_cleanup_considered'] )             : 0;

		// The stuck-run reaper isn't a delete cascade — its "skipped" count
		// means "still had a live job, left alone", so give it its own copy.
		if ( $op === 'stuck_extract_runs' ) {
			$left = max( 0, $considered - $deleted );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: 1: count reaped, 2: count left in flight */
					__( 'Cleared %1$d stuck extractor run(s). %2$d still had a live job queued and were left alone.', 'supplement-compare' ),
					$deleted,
					$left
				) )
			);
			return;
		}

		$op_labels = array(
			'rejected_offers'           => __( 'rejected offers', 'supplement-compare' ),
			'dead_offers'               => __( 'dead offers', 'supplement-compare' ),
			'empty_dead_merchants'      => __( 'empty dead merchants', 'supplement-compare' ),
			'empty_retired_canonicals'  => __( 'empty retired canonicals', 'supplement-compare' ),
			'empty_retired_ingredients' => __( 'empty retired ingredients', 'supplement-compare' ),
		);
		$label = $op_labels[ $op ] ?? $op;

		$skipped = $considered - $deleted;
		$msg = sprintf(
			/* translators: 1: count deleted, 2: label, 3: count skipped */
			__( 'Cleanup complete: %1$d %2$s deleted. (%3$d skipped — usually means a downstream row blocks the cascade.)', 'supplement-compare' ),
			$deleted,
			$label,
			max( 0, $skipped )
		);

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $msg )
		);
	}
}
