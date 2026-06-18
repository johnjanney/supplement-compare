<?php
/**
 * Extractor Sites admin screen.
 *
 *   admin.php?page=supcomp-extract-sites                   — list
 *   admin.php?page=supcomp-extract-sites&action=new        — create form
 *   admin.php?page=supcomp-extract-sites&action=edit&id=N  — edit form
 *
 * Phase A: read/write CRUD only. No "Run now" button, no scheduling, no
 * test-connection — those land in Phase B + Phase E once the extractor
 * actually scrapes.
 *
 * Capability: manage_options. Nonces on every form.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Extract_Sites_Screen {

	const PAGE_SLUG      = 'supcomp-extract-sites';
	const NONCE_SAVE     = 'supcomp_save_extract_site';
	const NONCE_DELETE   = 'supcomp_delete_extract_site';
	const NONCE_RUN      = 'supcomp_run_extract';
	const NONCE_SCHEDULE = 'supcomp_save_extract_schedule';

	public static function register_hooks() {
		add_action( 'admin_post_supcomp_save_extract_site',     array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_supcomp_delete_extract_site',   array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_supcomp_run_extract',           array( __CLASS__, 'handle_run' ) );
		add_action( 'admin_post_supcomp_save_extract_schedule', array( __CLASS__, 'handle_save_schedule' ) );
	}

	public static function render() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'supplement-compare' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		switch ( $action ) {
			case 'new':
				self::render_form( 0 );
				break;
			case 'edit':
				$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
				self::render_form( $id );
				break;
			default:
				self::render_list();
		}
	}

	// ---------- list ----------

	private static function render_list() {
		// Self-heal: fail any orphaned "in flight" attempts (dead chains older
		// than the configured threshold) before reading state, so the table
		// below reflects reality. Throttled internally.
		Supcomp_Extractor_Reaper::maybe_reap_on_load();

		$sites          = Supcomp_Extract_Sites_Repo::list_all();
		$open_attempts  = Supcomp_Extract_Runs_Repo::open_attempts_by_site();
		$current_freq   = Supcomp_Extractor_Scheduler::get_schedule();
		$next_scheduled = Supcomp_Extractor_Scheduler::next_scheduled_at();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Extractor Sites', 'supplement-compare' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add new', 'supplement-compare' ); ?></a>
			<?php if ( ! empty( $sites ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:.5em" onsubmit="return confirm('<?php echo esc_js( __( 'Queue a refresh for all enabled sites?', 'supplement-compare' ) ); ?>');">
					<input type="hidden" name="action" value="supcomp_run_extract">
					<input type="hidden" name="scope" value="all">
					<?php wp_nonce_field( self::NONCE_RUN ); ?>
					<button type="submit" class="page-title-action"><?php esc_html_e( 'Refresh all enabled', 'supplement-compare' ); ?></button>
				</form>
			<?php endif; ?>

			<p class="description">
				<?php
				echo wp_kses(
					__( 'Sites the in-plugin extractor will scrape. <strong>Phase D (v1.6.0): Shopify, WooCommerce, and generic JSON-LD (sitemap-discovered product pages) are all supported.</strong> Each "Refresh" enqueues an Action Scheduler job that runs out-of-request, so the admin returns immediately.', 'supplement-compare' ),
					array( 'strong' => array() )
				);
				?>
			</p>

			<?php self::render_notice(); ?>

			<h2 style="margin-top:1.5em"><?php esc_html_e( 'Scheduled runs', 'supplement-compare' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="background:#f6f7f7;border:1px solid #c3c4c7;padding:1em 1.2em;max-width:60em">
				<input type="hidden" name="action" value="supcomp_save_extract_schedule">
				<?php wp_nonce_field( self::NONCE_SCHEDULE ); ?>
				<label style="display:inline-block;margin-right:1em">
					<strong><?php esc_html_e( 'Frequency:', 'supplement-compare' ); ?></strong>
					<select name="frequency">
						<?php foreach ( Supcomp_Extractor_Scheduler::valid_frequencies() as $f ) : ?>
							<option value="<?php echo esc_attr( $f ); ?>" <?php selected( $current_freq, $f ); ?>><?php echo esc_html( Supcomp_Extractor_Scheduler::schedule_label( $f ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Save schedule', 'supplement-compare' ); ?></button>
				<?php if ( $current_freq !== 'off' && $next_scheduled > 0 ) : ?>
					<p class="description" style="margin:.6em 0 0">
						<?php
						printf(
							/* translators: %s = time, e.g. "2 hours" */
							esc_html__( 'Next scheduled run: in %s (server time: %s UTC).', 'supplement-compare' ),
							esc_html( human_time_diff( time(), $next_scheduled ) ),
							esc_html( gmdate( 'Y-m-d H:i', $next_scheduled ) )
						);
						?>
					</p>
				<?php elseif ( $current_freq === 'off' ) : ?>
					<p class="description" style="margin:.6em 0 0">
						<?php esc_html_e( 'Manual triggers only. Use the "Run now" / "Refresh all enabled" buttons below.', 'supplement-compare' ); ?>
					</p>
				<?php endif; ?>
				<p class="description" style="margin:.6em 0 0">
					<?php esc_html_e( 'WP-Cron only fires when a visitor hits the site (or an external pinger like cron-job.org hits /wp-cron.php). On low-traffic sites, scheduled runs may drift — set up a 5-minute pinger if you need reliable timing.', 'supplement-compare' ); ?>
				</p>
			</form>

			<h2 style="margin-top:1.5em"><?php esc_html_e( 'Sites', 'supplement-compare' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Label', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Slug', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'URL', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Platform', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Merchant', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Enabled', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Last run', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Status', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Offers', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'supplement-compare' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $sites ) ) : ?>
						<tr><td colspan="10"><em><?php esc_html_e( 'No extractor sites configured yet.', 'supplement-compare' ); ?></em></td></tr>
					<?php else : ?>
						<?php foreach ( $sites as $site ) :
							$in_flight = $open_attempts[ (int) $site->id ] ?? array();
							$running   = false;
							foreach ( $in_flight as $att ) {
								if ( $att->status === 'running' || $att->status === 'pending' ) {
									$running = true;
									break;
								}
							}
						?>
							<tr<?php echo $running ? ' style="background:#e7f3ff"' : ''; ?>>
								<td><strong><?php echo esc_html( $site->label ? $site->label : $site->slug ); ?></strong></td>
								<td><code><?php echo esc_html( $site->slug ); ?></code></td>
								<td><a href="<?php echo esc_url( $site->site_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $site->site_url ); ?></a></td>
								<td><?php echo esc_html( $site->platform_hint ); ?></td>
								<td><?php echo esc_html( self::merchant_label( $site->merchant_id ) ); ?></td>
								<td><?php echo (int) $site->enabled === 1 ? '<span style="color:#46b450">●</span> ' . esc_html__( 'yes', 'supplement-compare' ) : '<span style="color:#888">○</span> ' . esc_html__( 'no', 'supplement-compare' ); ?></td>
								<td><?php echo esc_html( $site->last_run_at ? $site->last_run_at : '—' ); ?></td>
								<td>
									<?php if ( $running ) : ?>
										<strong style="color:#004085"><?php esc_html_e( 'in flight', 'supplement-compare' ); ?></strong>
										<br><small><?php echo (int) count( $in_flight ); ?>&nbsp;attempt(s)</small>
									<?php else : ?>
										<?php echo esc_html( $site->last_run_status ? $site->last_run_status : '—' ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo $site->last_offer_count !== null ? (int) $site->last_offer_count : '—'; ?></td>
								<td>
									<?php if ( (int) $site->enabled === 1 ) : ?>
										<?php self::render_run_form( (int) $site->id ); ?>
										&nbsp;|&nbsp;
									<?php endif; ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=edit&id=' . (int) $site->id ) ); ?>"><?php esc_html_e( 'Edit', 'supplement-compare' ); ?></a>
									&nbsp;|&nbsp;
									<?php self::render_delete_form( (int) $site->id ); ?>
								</td>
							</tr>
							<?php if ( $site->last_error ) : ?>
								<tr>
									<td colspan="10" style="background:#fcf4f4;color:#900;font-family:monospace;font-size:12px;padding:6px 12px">
										<?php echo esc_html( $site->last_error ); ?>
									</td>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_run_form( $id ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<input type="hidden" name="action" value="supcomp_run_extract">
			<input type="hidden" name="scope" value="one">
			<input type="hidden" name="site_id" value="<?php echo (int) $id; ?>">
			<?php wp_nonce_field( self::NONCE_RUN ); ?>
			<button type="submit" class="button-link"><?php esc_html_e( 'Run now', 'supplement-compare' ); ?></button>
		</form>
		<?php
	}

	private static function render_delete_form( $id ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this site? This cannot be undone.', 'supplement-compare' ) ); ?>');">
			<input type="hidden" name="action" value="supcomp_delete_extract_site">
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
			<?php wp_nonce_field( self::NONCE_DELETE ); ?>
			<button type="submit" class="button-link" style="color:#a00"><?php esc_html_e( 'Delete', 'supplement-compare' ); ?></button>
		</form>
		<?php
	}

	// ---------- form ----------

	private static function render_form( $id ) {
		$site = $id > 0 ? Supcomp_Extract_Sites_Repo::get( $id ) : null;
		if ( $id > 0 && ! $site ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Site not found', 'supplement-compare' ) . '</h1></div>';
			return;
		}

		$is_edit = ( $site !== null );
		$slug    = $is_edit ? $site->slug : '';
		$label   = $is_edit ? $site->label : '';
		$url     = $is_edit ? $site->site_url : '';
		$hint    = $is_edit ? $site->platform_hint : 'auto';
		$merch   = $is_edit ? (int) $site->merchant_id : 0;
		$cookies = $is_edit && isset( $site->request_cookies ) ? (string) $site->request_cookies : '';
		$crawl_all = $is_edit && isset( $site->crawl_all_sitemap_urls ) ? (int) $site->crawl_all_sitemap_urls : 0;
		$enabled = $is_edit ? (int) $site->enabled : 1;

		$merchants = Supcomp_Merchants_Repo::active_for_select();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $is_edit ? __( 'Edit extractor site', 'supplement-compare' ) : __( 'New extractor site', 'supplement-compare' ) ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">&laquo; <?php esc_html_e( 'Back to list', 'supplement-compare' ); ?></a></p>

			<?php self::render_notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="supcomp_save_extract_site">
				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="id" value="<?php echo (int) $site->id; ?>">
				<?php endif; ?>
				<?php wp_nonce_field( self::NONCE_SAVE ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th><label for="supcomp-site-slug"><?php esc_html_e( 'Slug', 'supplement-compare' ); ?></label></th>
						<td>
							<input type="text" id="supcomp-site-slug" name="slug" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" required>
							<p class="description"><?php esc_html_e( 'Lowercase identifier, used in logs and URLs. e.g. examplestore', 'supplement-compare' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="supcomp-site-label"><?php esc_html_e( 'Label', 'supplement-compare' ); ?></label></th>
						<td>
							<input type="text" id="supcomp-site-label" name="label" value="<?php echo esc_attr( $label ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Display name shown in this admin screen. Optional.', 'supplement-compare' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="supcomp-site-url"><?php esc_html_e( 'Site URL', 'supplement-compare' ); ?></label></th>
						<td>
							<input type="url" id="supcomp-site-url" name="site_url" value="<?php echo esc_attr( $url ); ?>" class="regular-text" required placeholder="https://example.com">
							<p class="description"><?php esc_html_e( 'Public store URL. The extractor probes /products.json (Shopify) and /wp-json/wc/store/v1/products (Woo) under this base.', 'supplement-compare' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="supcomp-site-hint"><?php esc_html_e( 'Platform hint', 'supplement-compare' ); ?></label></th>
						<td>
							<select id="supcomp-site-hint" name="platform_hint">
								<?php foreach ( Supcomp_Installer::EXTRACT_SITE_PLATFORM_HINTS as $h ) : ?>
									<option value="<?php echo esc_attr( $h ); ?>" <?php selected( $hint, $h ); ?>><?php echo esc_html( $h ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Leave on "auto" to let the extractor try Shopify → Woo → generic JSON-LD in order ("auto" also finds Wix sites via the generic step). Pin a specific platform if a site supports multiple. "wix" uses the generic JSON-LD engine but skips the Shopify/Woo probes and labels offers as "wix".', 'supplement-compare' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Crawl all sitemap URLs', 'supplement-compare' ); ?></th>
						<td>
							<label><input type="checkbox" name="crawl_all_sitemap_urls" value="1" <?php checked( $crawl_all, 1 ); ?>> <?php esc_html_e( 'Treat every sitemap URL as a possible product page', 'supplement-compare' ); ?></label>
							<p class="description"><?php esc_html_e( 'Generic JSON-LD only. By default the generic handler keeps sitemap URLs whose path looks like a product (/product/, /shop/, /p/, …). Turn this on for headless or single-page-app storefronts (e.g. a Next.js site) whose products live at top-level slugs like /my-product with no such prefix — the handler then fetches every sitemap URL and keeps the ones that carry Product structured data, ignoring the rest. Costs extra fetches (spread across scheduled runs); leave off for normal Shopify/Woo/WordPress stores.', 'supplement-compare' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="supcomp-site-merchant"><?php esc_html_e( 'Merchant', 'supplement-compare' ); ?></label></th>
						<td>
							<select id="supcomp-site-merchant" name="merchant_id">
								<option value="0"><?php esc_html_e( '— Unset (resolve at import time) —', 'supplement-compare' ); ?></option>
								<?php foreach ( $merchants as $m ) : ?>
									<option value="<?php echo (int) $m->id; ?>" <?php selected( $merch, (int) $m->id ); ?>><?php echo esc_html( $m->name . ' (' . $m->slug . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Optional link to a Merchants row — needed for the affiliate URL template to fire on /out/N redirects.', 'supplement-compare' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="supcomp-site-cookies"><?php esc_html_e( 'Request cookies', 'supplement-compare' ); ?></label></th>
						<td>
							<input type="text" id="supcomp-site-cookies" name="request_cookies" value="<?php echo esc_attr( $cookies ); ?>" class="large-text code" placeholder="age_gate=1; age_gate_birthdate=1990-01-01">
							<p class="description"><?php esc_html_e( 'Optional. A Cookie header sent with every request to this site — use it to get past an age-verification gate that redirects the extractor to a landing page (the run otherwise completes with 0 offers). Capture it from your browser after you click through the gate: DevTools → Application → Cookies (or copy the request "Cookie" header from the Network tab), then paste the relevant name=value pairs here, separated by "; ". Leave blank for normal sites.', 'supplement-compare' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Enabled', 'supplement-compare' ); ?></th>
						<td>
							<label><input type="checkbox" name="enabled" value="1" <?php checked( $enabled, 1 ); ?>> <?php esc_html_e( 'Include in scheduled extractor runs', 'supplement-compare' ); ?></label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php echo esc_html( $is_edit ? __( 'Save changes', 'supplement-compare' ) : __( 'Add site', 'supplement-compare' ) ); ?></button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'supplement-compare' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	// ---------- POST handlers ----------

	public static function handle_save() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'supplement-compare' ) );
		}
		check_admin_referer( self::NONCE_SAVE );

		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$data = array(
			'slug'          => isset( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : '',
			'label'         => isset( $_POST['label'] ) ? wp_unslash( $_POST['label'] ) : '',
			'site_url'      => isset( $_POST['site_url'] ) ? wp_unslash( $_POST['site_url'] ) : '',
			'platform_hint'   => isset( $_POST['platform_hint'] ) ? wp_unslash( $_POST['platform_hint'] ) : 'auto',
			'merchant_id'     => isset( $_POST['merchant_id'] ) ? absint( $_POST['merchant_id'] ) : 0,
			'request_cookies' => isset( $_POST['request_cookies'] ) ? wp_unslash( $_POST['request_cookies'] ) : '',
			'crawl_all_sitemap_urls' => isset( $_POST['crawl_all_sitemap_urls'] ),
			'enabled'         => isset( $_POST['enabled'] ),
		);

		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		if ( $id > 0 ) {
			$ok = Supcomp_Extract_Sites_Repo::update( $id, $data );
			wp_safe_redirect( add_query_arg( 'supcomp_notice', $ok ? 'saved' : 'error', add_query_arg( array( 'action' => 'edit', 'id' => $id ), $base ) ) );
		} else {
			$new_id = Supcomp_Extract_Sites_Repo::insert( $data );
			if ( $new_id > 0 ) {
				wp_safe_redirect( add_query_arg( 'supcomp_notice', 'created', add_query_arg( array( 'action' => 'edit', 'id' => $new_id ), $base ) ) );
			} else {
				wp_safe_redirect( add_query_arg( 'supcomp_notice', 'error', add_query_arg( 'action', 'new', $base ) ) );
			}
		}
		exit;
	}

	public static function handle_save_schedule() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'supplement-compare' ) );
		}
		check_admin_referer( self::NONCE_SCHEDULE );
		$freq = isset( $_POST['frequency'] ) ? sanitize_key( wp_unslash( $_POST['frequency'] ) ) : 'off';
		Supcomp_Extractor_Scheduler::set_schedule( $freq );
		wp_safe_redirect( add_query_arg( 'supcomp_notice', 'schedule_saved', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	public static function handle_run() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'supplement-compare' ) );
		}
		check_admin_referer( self::NONCE_RUN );

		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'one';
		$base  = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		$site_ids = array();
		if ( $scope === 'one' ) {
			$id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
			if ( $id > 0 ) {
				$site_ids = array( $id );
			}
		}
		// 'all' → pass empty $site_ids, orchestrator picks up all enabled.

		$result   = Supcomp_Extractor::run( $site_ids, 'manual' );
		$queued   = count( $result['attempt_ids'] );
		$inflight = isset( $result['skipped_in_flight'] ) ? (int) $result['skipped_in_flight'] : 0;

		if ( $queued === 0 && $inflight === 0 ) {
			$notice = 'queued_none';
		} elseif ( $queued === 0 ) {
			$notice = 'queued_inflight';
		} else {
			$notice = 'queued';
		}

		wp_safe_redirect( add_query_arg(
			array(
				'supcomp_notice'           => $notice,
				'supcomp_queued_count'     => $queued,
				'supcomp_skipped_inflight' => $inflight,
			),
			$base
		) );
		exit;
	}

	public static function handle_delete() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'supplement-compare' ) );
		}
		check_admin_referer( self::NONCE_DELETE );

		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		if ( $id > 0 ) {
			$ok = Supcomp_Extract_Sites_Repo::delete( $id );
			wp_safe_redirect( add_query_arg( 'supcomp_notice', $ok ? 'deleted' : 'error', $base ) );
		} else {
			wp_safe_redirect( add_query_arg( 'supcomp_notice', 'error', $base ) );
		}
		exit;
	}

	// ---------- helpers ----------

	private static function merchant_label( $merchant_id ) {
		if ( ! $merchant_id ) {
			return '—';
		}
		$m = Supcomp_Merchants_Repo::get( (int) $merchant_id );
		return $m ? $m->name : '#' . (int) $merchant_id;
	}

	private static function render_notice() {
		if ( empty( $_GET['supcomp_notice'] ) ) {
			return;
		}
		$type     = sanitize_key( wp_unslash( $_GET['supcomp_notice'] ) );
		$queued_count = isset( $_GET['supcomp_queued_count'] ) ? absint( $_GET['supcomp_queued_count'] ) : 0;
		$inflight     = isset( $_GET['supcomp_skipped_inflight'] ) ? absint( $_GET['supcomp_skipped_inflight'] ) : 0;
		$queued_text  = sprintf(
			/* translators: %d = number of sites queued */
			_n(
				'Queued %d site for extraction. Action Scheduler will process it shortly; refresh this page to see status updates.',
				'Queued %d sites for extraction. Action Scheduler will process them shortly; refresh this page to see status updates.',
				max( 1, $queued_count ),
				'supplement-compare'
			),
			$queued_count
		);
		// A run can both queue some sites and skip others that are already in
		// flight — append the dedupe note so the operator knows nothing stacked.
		if ( $inflight > 0 ) {
			$queued_text .= ' ' . sprintf(
				/* translators: %d = number of sites skipped because a run was already in flight */
				_n(
					'%d site already had a run in flight and was skipped.',
					'%d sites already had a run in flight and were skipped.',
					$inflight,
					'supplement-compare'
				),
				$inflight
			);
		}

		$inflight_only_text = ( $inflight === 1 )
			? __( 'No new run queued — that site already has a run in flight. Re-clicking "Run now" while a run is in flight is safely ignored; it no longer stacks duplicate attempts.', 'supplement-compare' )
			: sprintf(
				/* translators: %d = number of sites already in flight */
				__( 'No new run queued — all %d targeted sites already have a run in flight. Re-running while a run is in flight is safely ignored; it no longer stacks duplicate attempts.', 'supplement-compare' ),
				$inflight
			);

		$messages = array(
			'saved'           => array( 'success', __( 'Site updated.', 'supplement-compare' ) ),
			'created'         => array( 'success', __( 'Site added.', 'supplement-compare' ) ),
			'deleted'         => array( 'success', __( 'Site deleted.', 'supplement-compare' ) ),
			'queued'          => array( 'success', $queued_text ),
			'queued_none'     => array( 'warning', __( 'No sites were queued — check that at least one site is enabled and that Action Scheduler is loaded.', 'supplement-compare' ) ),
			'queued_inflight' => array( 'info', $inflight_only_text ),
			'schedule_saved'  => array( 'success', __( 'Schedule updated. WP-Cron reconciled.', 'supplement-compare' ) ),
			'error'           => array( 'error',   __( 'Something went wrong — check that slug is unique and URL is well-formed.', 'supplement-compare' ) ),
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
