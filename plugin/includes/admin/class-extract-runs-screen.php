<?php
/**
 * Extractor Runs admin screen — per-attempt history.
 *
 * Each row in wp_supcomp_extract_runs is one (run_id, site) attempt.
 * This screen lists the most recent 100 with their site label, platform
 * used, duration, status, offer count, and a truncated error excerpt.
 *
 * Filter by status (failed-only is the common operator use). Clicking
 * the run id opens a single-attempt detail view with the full error text.
 *
 * Read-only — there's no "Retry failed attempt" action yet. Operators
 * who want to re-run a failed site go to Extractor Sites and click
 * Run now on that row.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Extract_Runs_Screen {

	const PAGE_SLUG = 'supcomp-extract-runs';

	public static function render() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'supplement-compare' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		if ( $action === 'view' ) {
			self::render_detail( isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0 );
			return;
		}
		self::render_list();
	}

	private static function render_list() {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$rows   = Supcomp_Extract_Runs_Repo::recent_with_sites( 100, $status );
		$counts = Supcomp_Extract_Runs_Repo::counts_by_status( 24 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Extractor Runs', 'supplement-compare' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'One row per (run_id, site) attempt. Most recent 100 shown. Last 24 hours summary:', 'supplement-compare' ); ?>
				<?php
				$summary_parts = array();
				foreach ( array( 'complete', 'running', 'pending', 'failed', 'canceled' ) as $s ) {
					$n = $counts[ $s ] ?? 0;
					if ( $n > 0 ) {
						$summary_parts[] = "<strong>$s:</strong> $n";
					}
				}
				echo $summary_parts ? wp_kses_post( implode( ' &nbsp; ', $summary_parts ) ) : '<em>(no runs)</em>';
				?>
			</p>

			<ul class="subsubsub" style="margin:1em 0">
				<?php
				$filters = array(
					''         => __( 'All',      'supplement-compare' ),
					'running'  => __( 'Running',  'supplement-compare' ),
					'pending'  => __( 'Pending',  'supplement-compare' ),
					'complete' => __( 'Complete', 'supplement-compare' ),
					'failed'   => __( 'Failed',   'supplement-compare' ),
					'canceled' => __( 'Canceled', 'supplement-compare' ),
				);
				$i = 0;
				$count_filters = count( $filters );
				foreach ( $filters as $key => $label ) {
					$url    = $key === '' ? admin_url( 'admin.php?page=' . self::PAGE_SLUG ) : add_query_arg( 'status', $key, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
					$active = $status === $key ? ' class="current"' : '';
					echo '<li><a' . $active . ' href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
					if ( ++$i < $count_filters ) {
						echo ' |</li>';
					} else {
						echo '</li>';
					}
				}
				?>
			</ul>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:5em"><?php esc_html_e( 'Attempt', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Site', 'supplement-compare' ); ?></th>
						<th style="width:7em"><?php esc_html_e( 'Platform', 'supplement-compare' ); ?></th>
						<th style="width:9em"><?php esc_html_e( 'Status', 'supplement-compare' ); ?></th>
						<th style="width:13em"><?php esc_html_e( 'Started (UTC)', 'supplement-compare' ); ?></th>
						<th style="width:7em"><?php esc_html_e( 'Duration', 'supplement-compare' ); ?></th>
						<th style="width:6em;text-align:right"><?php esc_html_e( 'Offers', 'supplement-compare' ); ?></th>
						<th style="width:7em"><?php esc_html_e( 'Trigger', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Error excerpt', 'supplement-compare' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="9"><em><?php esc_html_e( 'No runs match this filter.', 'supplement-compare' ); ?></em></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( add_query_arg( array( 'action' => 'view', 'id' => (int) $row->id ), admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) ); ?>">#<?php echo (int) $row->id; ?></a></td>
								<td>
									<?php if ( $row->site_label || $row->site_slug ) : ?>
										<strong><?php echo esc_html( $row->site_label ?: $row->site_slug ); ?></strong>
										<br><code style="font-size:11px"><?php echo esc_html( $row->site_url ); ?></code>
									<?php else : ?>
										<em><?php esc_html_e( '(site deleted)', 'supplement-compare' ); ?></em>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $row->platform_used ? $row->platform_used : '—' ); ?></td>
								<td><?php self::render_status_badge( $row->status ); ?></td>
								<td><?php echo esc_html( $row->started_at ); ?></td>
								<td><?php echo esc_html( self::duration_label( $row->started_at, $row->finished_at, $row->status ) ); ?></td>
								<td style="text-align:right"><?php echo (int) $row->offer_count; ?></td>
								<td><?php echo esc_html( $row->triggered_by ); ?></td>
								<td>
									<?php if ( $row->error_text ) : ?>
										<code style="color:#900;font-size:11px"><?php echo esc_html( self::truncate( $row->error_text, 120 ) ); ?></code>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_detail( $id ) {
		$row = Supcomp_Extract_Runs_Repo::get( $id );
		if ( ! $row ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Run not found', 'supplement-compare' ) . '</h1></div>';
			return;
		}
		$site = $row->site_id ? Supcomp_Extract_Sites_Repo::get( (int) $row->site_id ) : null;
		?>
		<div class="wrap">
			<h1><?php echo esc_html( sprintf( __( 'Extractor run #%d', 'supplement-compare' ), (int) $row->id ) ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">&laquo; <?php esc_html_e( 'Back to runs', 'supplement-compare' ); ?></a></p>

			<table class="form-table">
				<tr><th><?php esc_html_e( 'Status', 'supplement-compare' ); ?></th><td><?php self::render_status_badge( $row->status ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Run id', 'supplement-compare' ); ?></th><td><code><?php echo esc_html( $row->run_id ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Site', 'supplement-compare' ); ?></th><td><?php echo $site ? esc_html( $site->label ?: $site->slug ) . ' (' . esc_html( $site->site_url ) . ')' : '<em>(deleted)</em>'; ?></td></tr>
				<tr><th><?php esc_html_e( 'Platform used', 'supplement-compare' ); ?></th><td><?php echo esc_html( $row->platform_used ?: '—' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Triggered by', 'supplement-compare' ); ?></th><td><?php echo esc_html( $row->triggered_by ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Started', 'supplement-compare' ); ?></th><td><?php echo esc_html( $row->started_at ); ?> UTC</td></tr>
				<tr><th><?php esc_html_e( 'Finished', 'supplement-compare' ); ?></th><td><?php echo esc_html( $row->finished_at ?: '—' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Duration', 'supplement-compare' ); ?></th><td><?php echo esc_html( self::duration_label( $row->started_at, $row->finished_at, $row->status ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Offer count', 'supplement-compare' ); ?></th><td><?php echo (int) $row->offer_count; ?></td></tr>
			</table>

			<?php if ( $row->error_text ) : ?>
				<h2><?php esc_html_e( 'Error log', 'supplement-compare' ); ?></h2>
				<pre style="background:#fcecec;border:1px solid #f0b0b0;padding:1em;max-height:30em;overflow:auto;font-size:12px;white-space:pre-wrap"><?php echo esc_html( $row->error_text ); ?></pre>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Sibling attempts in this run', 'supplement-compare' ); ?></h2>
			<?php $siblings = Supcomp_Extract_Runs_Repo::by_run( $row->run_id ); ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Attempt', 'supplement-compare' ); ?></th>
					<th><?php esc_html_e( 'Site id', 'supplement-compare' ); ?></th>
					<th><?php esc_html_e( 'Status', 'supplement-compare' ); ?></th>
					<th><?php esc_html_e( 'Offers', 'supplement-compare' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $siblings as $s ) : ?>
						<tr<?php echo ( (int) $s->id === (int) $row->id ) ? ' style="background:#fffbe6"' : ''; ?>>
							<td><a href="<?php echo esc_url( add_query_arg( array( 'action' => 'view', 'id' => (int) $s->id ), admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) ); ?>">#<?php echo (int) $s->id; ?></a></td>
							<td><?php echo $s->site_id ? (int) $s->site_id : '—'; ?></td>
							<td><?php self::render_status_badge( $s->status ); ?></td>
							<td><?php echo (int) $s->offer_count; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_status_badge( $status ) {
		$colors = array(
			'complete' => array( '#d4edda', '#155724' ),
			'running'  => array( '#cce5ff', '#004085' ),
			'pending'  => array( '#fff3cd', '#856404' ),
			'failed'   => array( '#f8d7da', '#721c24' ),
			'canceled' => array( '#e2e3e5', '#383d41' ),
		);
		list( $bg, $fg ) = $colors[ $status ] ?? array( '#eee', '#666' );
		printf(
			'<span style="background:%s;color:%s;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:bold">%s</span>',
			esc_attr( $bg ),
			esc_attr( $fg ),
			esc_html( $status )
		);
	}

	private static function duration_label( $started_at, $finished_at, $status ) {
		if ( ! $started_at ) {
			return '—';
		}
		$start = strtotime( $started_at . ' UTC' );
		if ( $start === false ) {
			return '—';
		}
		$end = $finished_at ? strtotime( $finished_at . ' UTC' ) : time();
		if ( $end === false ) {
			return '—';
		}
		$secs = max( 0, $end - $start );
		$suffix = ( ! $finished_at && in_array( $status, array( 'running', 'pending' ), true ) ) ? ' …' : '';
		if ( $secs < 60 ) {
			return $secs . 's' . $suffix;
		}
		if ( $secs < 3600 ) {
			return sprintf( '%dm %ds%s', intdiv( $secs, 60 ), $secs % 60, $suffix );
		}
		return sprintf( '%dh %dm%s', intdiv( $secs, 3600 ), intdiv( $secs % 3600, 60 ), $suffix );
	}

	private static function truncate( $text, $max ) {
		return strlen( $text ) > $max ? substr( $text, 0, $max ) . '…' : $text;
	}
}
