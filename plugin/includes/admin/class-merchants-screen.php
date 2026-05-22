<?php
/**
 * Merchants admin screen.
 *
 *   admin.php?page=supcomp-merchants                   — list
 *   admin.php?page=supcomp-merchants&action=new        — create form
 *   admin.php?page=supcomp-merchants&action=edit&id=N  — edit form (with template tester)
 *
 * The edit form's affiliate URL template tester posts to admin-ajax.php with
 * action=supcomp_preview_affiliate_url; the handler lives here in
 * handle_ajax_preview() and runs the same Supcomp_Affiliate_URL_Template
 * engine that Phase 7's click-redirect will use.
 *
 * Capability: manage_options. Nonces on every form. No CSV import for Phase 3.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Merchants_Screen {

	const PAGE_SLUG       = 'supcomp-merchants';
	const NONCE_SAVE      = 'supcomp_save_merchant';
	const NONCE_STAT      = 'supcomp_set_merchant_status';
	const NONCE_PREVIEW   = 'supcomp_preview_affiliate_url';

	public static function register_hooks() {
		add_action( 'admin_post_supcomp_save_merchant', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_supcomp_set_merchant_status', array( __CLASS__, 'handle_status' ) );
		add_action( 'wp_ajax_supcomp_preview_affiliate_url', array( __CLASS__, 'handle_ajax_preview' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue( $hook_suffix ) {
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== self::PAGE_SLUG ) {
			return;
		}
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		if ( ! in_array( $action, array( 'new', 'edit' ), true ) ) {
			return;
		}

		wp_enqueue_script(
			'supcomp-merchants-preview',
			plugins_url( 'assets/admin/merchants-preview.js', SUPPLEMENT_COMPARE_PLUGIN_FILE ),
			array(),
			SUPPLEMENT_COMPARE_VERSION,
			true
		);
		wp_localize_script(
			'supcomp-merchants-preview',
			'supcompMerchantsPreview',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_PREVIEW ),
				'i18n'    => array(
					'loading'      => __( 'Generating preview…', 'supplement-compare' ),
					'noTemplate'   => __( 'Enter a template above first.', 'supplement-compare' ),
					'noUrls'       => __( 'Add at least one product URL to test.', 'supplement-compare' ),
					'networkError' => __( 'Network error:', 'supplement-compare' ),
					'header_input' => __( 'Product URL', 'supplement-compare' ),
					'header_out'   => __( 'Affiliate URL', 'supplement-compare' ),
				),
			)
		);
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
			case 'list':
			default:
				self::render_list();
				break;
		}
	}

	// ---------- list ----------

	private static function render_list() {
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$platform = isset( $_GET['platform'] ) ? sanitize_key( wp_unslash( $_GET['platform'] ) ) : '';
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$rows = Supcomp_Merchants_Repo::query(
			array(
				'status'   => $status,
				'platform' => $platform,
				'search'   => $search,
			)
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Merchants', 'supplement-compare' ); ?></h1>
			<a href="<?php echo esc_url( self::url( array( 'action' => 'new' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'supplement-compare' ); ?></a>
			<hr class="wp-header-end">

			<?php self::render_notice(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<p class="search-box">
					<label class="screen-reader-text" for="supcomp-merchant-search"><?php esc_html_e( 'Search', 'supplement-compare' ); ?></label>
					<input type="search" id="supcomp-merchant-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name, slug, site URL…', 'supplement-compare' ); ?>">

					<select name="platform">
						<option value=""><?php esc_html_e( 'All platforms', 'supplement-compare' ); ?></option>
						<?php foreach ( Supcomp_Installer::MERCHANT_PLATFORMS as $p ) : ?>
							<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $platform, $p ); ?>><?php echo esc_html( $p ); ?></option>
						<?php endforeach; ?>
					</select>

					<select name="status">
						<option value=""><?php esc_html_e( 'All statuses', 'supplement-compare' ); ?></option>
						<?php foreach ( Supcomp_Installer::MERCHANT_STATUSES as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( $s ); ?></option>
						<?php endforeach; ?>
					</select>

					<?php submit_button( __( 'Filter', 'supplement-compare' ), '', '', false ); ?>
				</p>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Slug', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Site URL', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Platform', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Currency', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Template?', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Status', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'supplement-compare' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'No merchants yet. Add one to get started.', 'supplement-compare' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><strong><a href="<?php echo esc_url( self::url( array( 'action' => 'edit', 'id' => $r->id ) ) ); ?>"><?php echo esc_html( $r->name ); ?></a></strong></td>
							<td><code><?php echo esc_html( $r->slug ); ?></code></td>
							<td><a href="<?php echo esc_url( $r->site_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $r->site_url ); ?></a></td>
							<td><?php echo esc_html( $r->platform ); ?></td>
							<td><?php echo esc_html( $r->default_currency ); ?></td>
							<td><?php echo empty( $r->affiliate_url_template ) ? '—' : '✓'; ?></td>
							<td><?php echo esc_html( $r->status ); ?></td>
							<td>
								<a href="<?php echo esc_url( self::url( array( 'action' => 'edit', 'id' => $r->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'supplement-compare' ); ?></a>
								&nbsp;|&nbsp;
								<?php
								if ( $r->status === 'paused' ) {
									$next_status = 'active';
									$next_label  = __( 'Resume', 'supplement-compare' );
								} else {
									$next_status = 'paused';
									$next_label  = __( 'Pause', 'supplement-compare' );
								}
								?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<input type="hidden" name="action" value="supcomp_set_merchant_status">
									<input type="hidden" name="id" value="<?php echo (int) $r->id; ?>">
									<input type="hidden" name="status" value="<?php echo esc_attr( $next_status ); ?>">
									<?php wp_nonce_field( self::NONCE_STAT . '_' . $r->id ); ?>
									<?php submit_button( $next_label, 'link-delete', 'submit', false ); ?>
								</form>
								<?php if ( Supcomp_Deletion_Service::merchant_is_deletable( $r ) ) : ?>
									&nbsp;|&nbsp;
									<a href="<?php echo esc_url( Supcomp_Deletion_Admin::confirm_url( 'merchant', (int) $r->id ) ); ?>" style="color:#a00"><?php esc_html_e( 'Delete', 'supplement-compare' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	// ---------- form ----------

	private static function render_form( $id ) {
		$row = $id ? Supcomp_Merchants_Repo::get( $id ) : null;
		if ( $id && ! $row ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Merchant not found', 'supplement-compare' ) . '</h1></div>';
			return;
		}

		$slug                   = $row ? $row->slug : '';
		$name                   = $row ? $row->name : '';
		$site_url               = $row ? $row->site_url : '';
		$platform               = $row ? $row->platform : 'generic';
		$default_currency       = $row ? $row->default_currency : (string) get_option( 'supcomp_default_currency', 'USD' );
		$affiliate_url_template = $row ? (string) $row->affiliate_url_template : '';
		$status                 = $row ? $row->status : 'active';
		$notes                  = $row ? (string) $row->notes : '';

		$heading = $id ? __( 'Edit Merchant', 'supplement-compare' ) : __( 'Add Merchant', 'supplement-compare' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $heading ); ?></h1>
			<?php self::render_notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="supcomp_save_merchant">
				<?php if ( $id ) : ?>
					<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
				<?php endif; ?>
				<?php wp_nonce_field( self::NONCE_SAVE ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th><label for="supcomp-slug"><?php esc_html_e( 'Slug', 'supplement-compare' ); ?> <span class="description">*</span></label></th>
						<td><input type="text" id="supcomp-slug" name="slug" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" required>
							<p class="description"><?php esc_html_e( 'URL-safe identifier, e.g. "nootropics-depot". Internal — never shown publicly.', 'supplement-compare' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="supcomp-name"><?php esc_html_e( 'Name', 'supplement-compare' ); ?> <span class="description">*</span></label></th>
						<td><input type="text" id="supcomp-name" name="name" value="<?php echo esc_attr( $name ); ?>" class="regular-text" required>
							<p class="description"><?php esc_html_e( 'Display name shown on offer cards.', 'supplement-compare' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="supcomp-site-url"><?php esc_html_e( 'Site URL', 'supplement-compare' ); ?> <span class="description">*</span></label></th>
						<td><input type="url" id="supcomp-site-url" name="site_url" value="<?php echo esc_attr( $site_url ); ?>" class="regular-text" required placeholder="https://example.com">
							<p class="description"><?php esc_html_e( 'Merchant homepage URL. This is the natural key that matches the Python extractor\'s CSV `site` column to this merchant row at import time.', 'supplement-compare' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="supcomp-platform"><?php esc_html_e( 'Platform', 'supplement-compare' ); ?></label></th>
						<td>
							<select id="supcomp-platform" name="platform">
								<?php foreach ( Supcomp_Installer::MERCHANT_PLATFORMS as $p ) : ?>
									<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $platform, $p ); ?>><?php echo esc_html( $p ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Storefront platform — informational; matches the CSV source column.', 'supplement-compare' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="supcomp-currency"><?php esc_html_e( 'Default currency', 'supplement-compare' ); ?></label></th>
						<td><input type="text" id="supcomp-currency" name="default_currency" value="<?php echo esc_attr( $default_currency ); ?>" maxlength="3" size="5" class="code">
							<p class="description"><?php esc_html_e( 'ISO 4217 code. Used when this merchant\'s CSV rows omit currency. Falls back to the site-wide setting.', 'supplement-compare' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="supcomp-template"><?php esc_html_e( 'Affiliate URL template', 'supplement-compare' ); ?></label></th>
						<td>
							<input type="text" id="supcomp-template" name="affiliate_url_template" value="<?php echo esc_attr( $affiliate_url_template ); ?>" class="large-text code" placeholder="{product_url}?aff=your-id">
							<p class="description">
								<?php
								echo wp_kses_post(
									__(
										'Buy Now links go through <code>/out/{offer_id}</code> which substitutes this template at click time. Four patterns from PROJECTBRIEF §5 are supported. The plugin auto-flips an appended <code>?</code> to <code>&</code> when the product URL already has a query string.',
										'supplement-compare'
									)
								);
								?>
							</p>
							<details>
								<summary><?php esc_html_e( 'Pattern reference', 'supplement-compare' ); ?></summary>
								<ul style="margin-left:1em;list-style:disc">
									<li><strong><?php esc_html_e( 'Simple query append', 'supplement-compare' ); ?>:</strong> <code>{product_url}?aff=john</code></li>
									<li><strong><?php esc_html_e( 'Multiple params', 'supplement-compare' ); ?>:</strong> <code>{product_url}?utm_source=affiliate&ref=john</code></li>
									<li><strong><?php esc_html_e( 'Network redirect', 'supplement-compare' ); ?>:</strong> <code>https://partners.example.com/c/?id=42&u={url_encoded_product_url}</code></li>
									<li><strong><?php esc_html_e( 'Path-based', 'supplement-compare' ); ?>:</strong> <code>https://merchant.com/ref/john{path}</code></li>
								</ul>
								<p><strong><?php esc_html_e( 'Variables:', 'supplement-compare' ); ?></strong>
									<code>{product_url}</code>, <code>{url_encoded_product_url}</code>, <code>{path}</code>, <code>{handle}</code>
								</p>
							</details>
						</td>
					</tr>
					<tr>
						<th><label for="supcomp-test-urls"><?php esc_html_e( 'Template tester', 'supplement-compare' ); ?></label></th>
						<td>
							<textarea id="supcomp-test-urls" rows="3" class="large-text code" placeholder="https://example.com/products/foo&#10;https://example.com/products/bar?variant=42"></textarea>
							<p>
								<button type="button" id="supcomp-preview-btn" class="button"><?php esc_html_e( 'Test template', 'supplement-compare' ); ?></button>
								<span class="description"><?php esc_html_e( 'One product URL per line. Runs the same engine the live /out/ redirect will use.', 'supplement-compare' ); ?></span>
							</p>
							<div id="supcomp-preview-results"></div>
						</td>
					</tr>
					<tr>
						<th><label for="supcomp-status"><?php esc_html_e( 'Status', 'supplement-compare' ); ?></label></th>
						<td>
							<select id="supcomp-status" name="status">
								<?php foreach ( Supcomp_Installer::MERCHANT_STATUSES as $s ) : ?>
									<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( $s ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Paused merchants stay in the database but new CSV imports are rejected and existing offers stop appearing publicly. Dead = permanently retired.', 'supplement-compare' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="supcomp-notes"><?php esc_html_e( 'Notes', 'supplement-compare' ); ?></label></th>
						<td><textarea id="supcomp-notes" name="notes" rows="3" class="large-text"><?php echo esc_textarea( $notes ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Operator-only. Useful for affiliate program ID, network name, contact, terms.', 'supplement-compare' ); ?></p></td>
					</tr>
				</table>

				<?php submit_button( $id ? __( 'Save Merchant', 'supplement-compare' ) : __( 'Create Merchant', 'supplement-compare' ) ); ?>
				<a href="<?php echo esc_url( self::url() ); ?>" class="button"><?php esc_html_e( 'Cancel', 'supplement-compare' ); ?></a>
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

		$data = wp_unslash( $_POST );
		unset( $data['action'], $data['_wpnonce'], $data['_wp_http_referer'], $data['id'] );

		$result = Supcomp_Merchants_Repo::upsert( $data );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( self::url( array( 'action' => 'new', 'supcomp_notice' => 'error', 'msg' => rawurlencode( $result->get_error_message() ) ) ) );
			exit;
		}

		wp_safe_redirect( self::url( array( 'action' => 'edit', 'id' => $result['id'], 'supcomp_notice' => $result['created'] ? 'created' : 'updated' ) ) );
		exit;
	}

	public static function handle_status() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'supplement-compare' ) );
		}
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( self::NONCE_STAT . '_' . $id );

		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( $id && Supcomp_Merchants_Repo::set_status( $id, $status ) ) {
			$notice = ( $status === 'paused' ) ? 'paused' : ( $status === 'active' ? 'resumed' : 'updated' );
		} else {
			$notice = 'error';
		}

		wp_safe_redirect( self::url( array( 'supcomp_notice' => $notice ) ) );
		exit;
	}

	public static function handle_ajax_preview() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_send_json_error( __( 'Forbidden.', 'supplement-compare' ), 403 );
		}
		check_ajax_referer( self::NONCE_PREVIEW );

		$template = isset( $_POST['template'] ) ? wp_unslash( $_POST['template'] ) : '';
		$urls     = isset( $_POST['urls'] ) ? (array) wp_unslash( $_POST['urls'] ) : array();

		$valid = Supcomp_Affiliate_URL_Template::validate( $template );
		if ( is_wp_error( $valid ) ) {
			wp_send_json_error( $valid->get_error_message(), 400 );
		}

		$results = array();
		foreach ( $urls as $url ) {
			$url = trim( (string) $url );
			if ( $url === '' ) {
				continue;
			}
			$out = Supcomp_Affiliate_URL_Template::apply( $template, $url );
			if ( is_wp_error( $out ) ) {
				$results[] = array(
					'input' => $url,
					'error' => $out->get_error_message(),
				);
			} else {
				$results[] = array(
					'input'  => $url,
					'output' => $out,
				);
			}
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	// ---------- helpers ----------

	private static function url( $args = array() ) {
		$args = array_merge( array( 'page' => self::PAGE_SLUG ), $args );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private static function render_notice() {
		if ( empty( $_GET['supcomp_notice'] ) ) {
			return;
		}
		$type = sanitize_key( wp_unslash( $_GET['supcomp_notice'] ) );
		$msg  = isset( $_GET['msg'] ) ? wp_unslash( $_GET['msg'] ) : '';

		$messages = array(
			'created' => array( 'success', __( 'Merchant created.', 'supplement-compare' ) ),
			'updated' => array( 'success', __( 'Merchant saved.', 'supplement-compare' ) ),
			'paused'  => array( 'success', __( 'Merchant paused.', 'supplement-compare' ) ),
			'resumed' => array( 'success', __( 'Merchant resumed.', 'supplement-compare' ) ),
			'error'   => array( 'error',   $msg !== '' ? $msg : __( 'Something went wrong.', 'supplement-compare' ) ),
		);
		if ( ! isset( $messages[ $type ] ) ) {
			return;
		}
		list( $css_class, $text ) = $messages[ $type ];
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $css_class ),
			esc_html( $text )
		);
	}
}
