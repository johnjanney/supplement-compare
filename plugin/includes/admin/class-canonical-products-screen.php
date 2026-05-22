<?php
/**
 * Canonical products admin screen.
 *
 * Same URL routing pattern as the ingredients screen:
 *   admin.php?page=supcomp-canonical                       — list
 *   admin.php?page=supcomp-canonical&action=new            — create form
 *   admin.php?page=supcomp-canonical&action=edit&id=N      — edit form
 *   admin.php?page=supcomp-canonical&action=import         — CSV upload form
 *
 * As of v1.1.1, strength_per_serving and servings_per_container are no
 * longer surfaced in this screen — those values live at the offer level
 * and drive the per-offer cost-per-active-unit math. The schema columns
 * remain (CSV import still accepts them, existing rows keep their values),
 * but the admin form no longer reads or writes them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Canonical_Products_Screen {

	const PAGE_SLUG  = 'supcomp-canonical';
	const NONCE_SAVE = 'supcomp_save_canonical_product';
	const NONCE_STAT = 'supcomp_set_canonical_product_status';
	const NONCE_IMP  = 'supcomp_import_canonical_products';

	public static function register_hooks() {
		add_action( 'admin_post_supcomp_save_canonical_product', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_supcomp_set_canonical_product_status', array( __CLASS__, 'handle_status' ) );
		add_action( 'admin_post_supcomp_import_canonical_products', array( __CLASS__, 'handle_import' ) );
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
			case 'import':
				self::render_import();
				break;
			case 'list':
			default:
				self::render_list();
				break;
		}
	}

	// ---------- list ----------

	private static function render_list() {
		$ingredient_id = isset( $_GET['ingredient_id'] ) ? absint( wp_unslash( $_GET['ingredient_id'] ) ) : 0;
		$status        = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$form          = isset( $_GET['form'] ) ? sanitize_key( wp_unslash( $_GET['form'] ) ) : '';
		$search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$rows         = Supcomp_Canonical_Products_Repo::query(
			array(
				'ingredient_id' => $ingredient_id,
				'status'        => $status,
				'form'          => $form,
				'search'        => $search,
			)
		);
		$ingredients = Supcomp_Ingredients_Repo::active_for_select();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Canonical Products', 'supplement-compare' ); ?></h1>
			<a href="<?php echo esc_url( self::url( array( 'action' => 'new' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'supplement-compare' ); ?></a>
			<a href="<?php echo esc_url( self::url( array( 'action' => 'import' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Import CSV', 'supplement-compare' ); ?></a>
			<hr class="wp-header-end">

			<?php self::render_notice(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<p class="search-box">
					<label class="screen-reader-text" for="supcomp-product-search"><?php esc_html_e( 'Search', 'supplement-compare' ); ?></label>
					<input type="search" id="supcomp-product-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search display name, slug, ingredient…', 'supplement-compare' ); ?>">

					<select name="ingredient_id">
						<option value="0"><?php esc_html_e( 'All ingredients', 'supplement-compare' ); ?></option>
						<?php foreach ( $ingredients as $ing ) : ?>
							<option value="<?php echo (int) $ing->id; ?>" <?php selected( $ingredient_id, $ing->id ); ?>><?php echo esc_html( $ing->name ); ?></option>
						<?php endforeach; ?>
					</select>

					<select name="form">
						<option value=""><?php esc_html_e( 'All forms', 'supplement-compare' ); ?></option>
						<?php foreach ( Supcomp_Installer::PRODUCT_FORMS as $f ) : ?>
							<option value="<?php echo esc_attr( $f ); ?>" <?php selected( $form, $f ); ?>><?php echo esc_html( $f ); ?></option>
						<?php endforeach; ?>
					</select>

					<select name="status">
						<option value=""><?php esc_html_e( 'All statuses', 'supplement-compare' ); ?></option>
						<?php foreach ( Supcomp_Installer::PRODUCT_STATUSES as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( $s ); ?></option>
						<?php endforeach; ?>
					</select>

					<?php submit_button( __( 'Filter', 'supplement-compare' ), '', '', false ); ?>
				</p>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Display name', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Slug', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Ingredient', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Form', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Std.', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'SEO', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Status', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'supplement-compare' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'No canonical products match the current filter.', 'supplement-compare' ); ?></td></tr>
					<?php endif; ?>

					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><strong><a href="<?php echo esc_url( self::url( array( 'action' => 'edit', 'id' => $r->id ) ) ); ?>"><?php echo esc_html( $r->display_name ); ?></a></strong></td>
							<td><code><?php echo esc_html( $r->slug ); ?></code></td>
							<td><?php echo esc_html( $r->ingredient_name ? $r->ingredient_name : '(missing)' ); ?></td>
							<td><?php echo $r->ingredient_form ? esc_html( $r->ingredient_form ) : '—'; ?></td>
							<td><?php echo $r->standardization_percentage !== null ? esc_html( self::trim_decimal( $r->standardization_percentage ) . '%' ) : '—'; ?></td>
							<td><?php echo (int) $r->seo_indexable ? '✓' : '—'; ?></td>
							<td><?php echo esc_html( $r->status ); ?></td>
							<td>
								<a href="<?php echo esc_url( self::url( array( 'action' => 'edit', 'id' => $r->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'supplement-compare' ); ?></a>
								&nbsp;|&nbsp;
								<?php
								$next_status = ( $r->status === 'retired' ) ? 'active' : 'retired';
								$next_label  = ( $r->status === 'retired' ) ? __( 'Restore', 'supplement-compare' ) : __( 'Retire', 'supplement-compare' );
								?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<input type="hidden" name="action" value="supcomp_set_canonical_product_status">
									<input type="hidden" name="id" value="<?php echo (int) $r->id; ?>">
									<input type="hidden" name="status" value="<?php echo esc_attr( $next_status ); ?>">
									<?php wp_nonce_field( self::NONCE_STAT . '_' . $r->id ); ?>
									<?php submit_button( $next_label, 'link-delete', 'submit', false ); ?>
								</form>
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
		$row = $id ? Supcomp_Canonical_Products_Repo::get( $id ) : null;
		if ( $id && ! $row ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Canonical product not found', 'supplement-compare' ) . '</h1></div>';
			return;
		}

		$slug                       = $row ? $row->slug : '';
		$ingredient_id              = $row ? (int) $row->ingredient_id : 0;
		$ingredient_form            = $row ? (string) $row->ingredient_form : '';
		$standardization_compound   = $row ? (string) $row->standardization_compound : '';
		$standardization_percentage = $row && $row->standardization_percentage !== null ? self::trim_decimal( $row->standardization_percentage ) : '';
		$display_name               = $row ? $row->display_name : '';
		$seo_indexable              = $row ? (int) $row->seo_indexable : 0;
		$seo_content                = $row && isset( $row->seo_content ) ? (string) $row->seo_content : '';
		$status                     = $row ? $row->status : 'draft';

		$ingredients = Supcomp_Ingredients_Repo::active_for_select();
		$heading     = $id ? __( 'Edit Canonical Product', 'supplement-compare' ) : __( 'Add Canonical Product', 'supplement-compare' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $heading ); ?></h1>
			<?php self::render_notice(); ?>

			<div class="notice notice-info inline" style="margin:12px 0;padding:8px 12px">
				<p style="margin:0">
					<strong><?php esc_html_e( 'Canonical = ingredient (+ active unit).', 'supplement-compare' ); ?></strong>
					<?php esc_html_e( 'Leave Form and Strength blank to let one canonical span every form and brand-strength of an ingredient (e.g. one canonical for Creatine that groups powders and capsules at varying doses). The per-offer total active amount, serving size, servings/container, and price drive the comparison table.', 'supplement-compare' ); ?>
				</p>
			</div>

			<?php if ( empty( $ingredients ) ) : ?>
				<div class="notice notice-warning"><p>
					<?php
					printf(
						/* translators: %s is a link to the ingredients screen */
						esc_html__( 'No active ingredients exist yet. %s before adding canonical products.', 'supplement-compare' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=supcomp-ingredients&action=new' ) ) . '">' . esc_html__( 'Add an ingredient', 'supplement-compare' ) . '</a>'
					);
					?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="supcomp_save_canonical_product">
				<?php if ( $id ) : ?>
					<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
				<?php endif; ?>
				<?php wp_nonce_field( self::NONCE_SAVE ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th><label for="supcomp-slug"><?php esc_html_e( 'Slug', 'supplement-compare' ); ?> <span class="description">*</span></label></th>
						<td><input type="text" id="supcomp-slug" name="slug" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" required>
							<p class="description"><?php esc_html_e( 'URL-safe identifier, e.g. "l-theanine-200mg-capsule".', 'supplement-compare' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="supcomp-ingredient-id"><?php esc_html_e( 'Ingredient', 'supplement-compare' ); ?> <span class="description">*</span></label></th>
						<td>
							<select id="supcomp-ingredient-id" name="ingredient_id" required>
								<option value=""><?php esc_html_e( '— Select —', 'supplement-compare' ); ?></option>
								<?php foreach ( $ingredients as $ing ) : ?>
									<option value="<?php echo (int) $ing->id; ?>" <?php selected( $ingredient_id, $ing->id ); ?>><?php echo esc_html( $ing->name ); ?> (<?php echo esc_html( $ing->slug ); ?>)</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="supcomp-form"><?php esc_html_e( 'Form', 'supplement-compare' ); ?></label></th>
						<td>
							<select id="supcomp-form" name="ingredient_form">
								<option value="" <?php selected( $ingredient_form, '' ); ?>><?php esc_html_e( '— Any form (canonical spans all forms) —', 'supplement-compare' ); ?></option>
								<?php foreach ( Supcomp_Installer::PRODUCT_FORMS as $f ) : ?>
									<option value="<?php echo esc_attr( $f ); ?>" <?php selected( $ingredient_form, $f ); ?>><?php echo esc_html( $f ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Optional. Leave blank when the canonical groups multiple forms (capsule, powder, etc.) for the same ingredient. The form of each offer is still recorded at the offer level.', 'supplement-compare' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="supcomp-stdcompound"><?php esc_html_e( 'Standardization compound (override)', 'supplement-compare' ); ?></label></th>
						<td><input type="text" id="supcomp-stdcompound" name="standardization_compound" value="<?php echo esc_attr( $standardization_compound ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Overrides the ingredient default. Leave blank to use the ingredient\'s value.', 'supplement-compare' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="supcomp-stdpct"><?php esc_html_e( 'Standardization % (override)', 'supplement-compare' ); ?></label></th>
						<td><input type="number" id="supcomp-stdpct" name="standardization_percentage" value="<?php echo esc_attr( $standardization_percentage ); ?>" step="0.01" min="0" max="100" class="small-text"> %</td>
					</tr>
					<tr>
						<th><label for="supcomp-display"><?php esc_html_e( 'Display name', 'supplement-compare' ); ?></label></th>
						<td><input type="text" id="supcomp-display" name="display_name" value="<?php echo esc_attr( $display_name ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Shown on the public site. If blank, the system derives one from the ingredient and strength.', 'supplement-compare' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="supcomp-seo"><?php esc_html_e( 'SEO indexable', 'supplement-compare' ); ?></label></th>
						<td>
							<label><input type="checkbox" id="supcomp-seo" name="seo_indexable" value="1" <?php checked( $seo_indexable, 1 ); ?>> <?php esc_html_e( 'Allow per-product SEO page to be indexed', 'supplement-compare' ); ?></label>
							<p class="description"><?php esc_html_e( 'Per PROJECTBRIEF.md §10, the per-canonical SEO page also requires at least 3 active offers before it actually indexes; this flag is the operator\'s explicit opt-in.', 'supplement-compare' ); ?></p>
							<?php if ( $row ) : ?>
								<?php
								$hide_hours = (int) get_option( 'supcomp_staleness_hide_hours', 168 );
								$hide_ts    = gmdate( 'Y-m-d H:i:s', time() - max( 1, $hide_hours ) * HOUR_IN_SECONDS );
								$active_n   = Supcomp_Offers_Repo::count_active_for_canonical( (int) $row->id, $hide_ts );
								$will_index = $seo_indexable && $active_n >= 3;
								$page_url   = home_url( '/compare/' . rawurlencode( $row->slug ) . '/' );
								?>
								<p class="description">
									<strong><?php esc_html_e( 'Active offers:', 'supplement-compare' ); ?></strong>
									<?php echo (int) $active_n; ?>
									&nbsp;|&nbsp;
									<strong><?php esc_html_e( 'Indexes:', 'supplement-compare' ); ?></strong>
									<?php
									if ( $will_index ) {
										echo '<span style="color:#155724">' . esc_html__( 'yes', 'supplement-compare' ) . '</span>';
									} else {
										$reason = ! $seo_indexable
											? __( 'SEO indexable is off', 'supplement-compare' )
											: sprintf( __( 'needs %d more active offers', 'supplement-compare' ), max( 0, 3 - $active_n ) );
										echo '<span style="color:#856404">' . esc_html__( 'no', 'supplement-compare' ) . ' (' . esc_html( $reason ) . ')</span>';
									}
									?>
									&nbsp;|&nbsp;
									<a href="<?php echo esc_url( $page_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View page →', 'supplement-compare' ); ?></a>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="supcomp-seo-content"><?php esc_html_e( 'SEO content', 'supplement-compare' ); ?></label></th>
						<td>
							<?php
							wp_editor(
								$seo_content,
								'supcomp-seo-content',
								array(
									'textarea_name' => 'seo_content',
									'media_buttons' => false,
									'teeny'         => true,
									'textarea_rows' => 8,
									'tinymce'       => array( 'toolbar1' => 'bold,italic,link,unlink,bullist,numlist,undo,redo' ),
								)
							);
							?>
							<p class="description">
								<?php esc_html_e( 'Factual reference content shown above the comparison table on /compare/{slug}/. Suggested: ingredient identity, form, standardization concept, bioavailability notes, units. Keep the language factual.', 'supplement-compare' ); ?>
							</p>
							<p class="description" style="color:#856404">
								<strong><?php esc_html_e( 'No therapeutic claims.', 'supplement-compare' ); ?></strong>
								<?php esc_html_e( 'PROJECTBRIEF.md §7 forbids therapeutic or comparative health claims in operator-facing copy. Don\'t describe a compound as treating, preventing, or improving any condition. Stick to chemistry and composition.', 'supplement-compare' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="supcomp-status"><?php esc_html_e( 'Status', 'supplement-compare' ); ?></label></th>
						<td>
							<select id="supcomp-status" name="status">
								<?php foreach ( Supcomp_Installer::PRODUCT_STATUSES as $s ) : ?>
									<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( $s ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

				</table>

				<?php submit_button( $id ? __( 'Save Canonical Product', 'supplement-compare' ) : __( 'Create Canonical Product', 'supplement-compare' ) ); ?>
				<a href="<?php echo esc_url( self::url() ); ?>" class="button"><?php esc_html_e( 'Cancel', 'supplement-compare' ); ?></a>
			</form>
		</div>
		<?php
	}

	// ---------- import ----------

	private static function render_import() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Canonical Products CSV', 'supplement-compare' ); ?></h1>
			<?php self::render_notice(); ?>

			<p><?php
				printf(
					/* translators: %s is a list of column names */
					esc_html__( 'Upload a CSV with a header row. Required columns: %s. Optional: strength_per_serving, ingredient_form, servings_per_container, standardization_compound, standardization_percentage, display_name, seo_indexable, status.', 'supplement-compare' ),
					'<code>slug</code>, <code>ingredient_slug</code>'
				);
			?></p>
			<p><?php esc_html_e( 'Rows are upserted by slug. Each row\'s ingredient_slug must match an existing canonical ingredient — import ingredients first.', 'supplement-compare' ); ?></p>
			<p><?php esc_html_e( 'A template lives at seed-data/canonical-products.example.csv in the repo.', 'supplement-compare' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="supcomp_import_canonical_products">
				<?php wp_nonce_field( self::NONCE_IMP ); ?>

				<input type="file" name="csvfile" accept=".csv,text/csv" required>
				<?php submit_button( __( 'Import', 'supplement-compare' ) ); ?>
				<a href="<?php echo esc_url( self::url() ); ?>" class="button"><?php esc_html_e( 'Cancel', 'supplement-compare' ); ?></a>
			</form>

			<?php self::render_import_report(); ?>
		</div>
		<?php
	}

	private static function render_import_report() {
		$key    = 'supcomp_import_report_canonical_' . get_current_user_id();
		$report = get_transient( $key );
		if ( ! $report ) {
			return;
		}
		delete_transient( $key );
		?>
		<h2><?php esc_html_e( 'Last import', 'supplement-compare' ); ?></h2>
		<p>
			<?php echo esc_html( sprintf( __( 'Inserted: %d', 'supplement-compare' ), (int) $report['inserted'] ) ); ?>
			&nbsp;|&nbsp;
			<?php echo esc_html( sprintf( __( 'Updated: %d', 'supplement-compare' ), (int) $report['updated'] ) ); ?>
			&nbsp;|&nbsp;
			<?php echo esc_html( sprintf( __( 'Errors: %d', 'supplement-compare' ), count( $report['errors'] ) ) ); ?>
		</p>
		<?php if ( ! empty( $report['errors'] ) ) : ?>
			<details open>
				<summary><?php esc_html_e( 'Error detail', 'supplement-compare' ); ?></summary>
				<ul>
					<?php foreach ( $report['errors'] as $where => $msg ) : ?>
						<li><strong><?php echo esc_html( $where ); ?>:</strong> <?php echo esc_html( $msg ); ?></li>
					<?php endforeach; ?>
				</ul>
			</details>
		<?php endif; ?>
		<?php
	}

	// ---------- POST handlers ----------

	public static function handle_save() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'supplement-compare' ) );
		}
		check_admin_referer( self::NONCE_SAVE );

		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$data = wp_unslash( $_POST );
		unset( $data['action'], $data['_wpnonce'], $data['_wp_http_referer'], $data['id'] );

		// Checkbox: absent in POST when unchecked.
		if ( ! isset( $data['seo_indexable'] ) ) {
			$data['seo_indexable'] = 0;
		}

		if ( $id > 0 ) {
			$data['id'] = $id;
		}
		$result = Supcomp_Canonical_Products_Repo::upsert( $data );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( self::url( array( 'action' => 'new', 'supcomp_notice' => 'error', 'msg' => rawurlencode( $result->get_error_message() ) ) ) );
			exit;
		}

		do_action( 'supcomp_data_changed', array( 'source' => 'canonical_save', 'id' => $result['id'] ) );
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
		if ( $id && Supcomp_Canonical_Products_Repo::set_status( $id, $status ) ) {
			$notice = ( $status === 'retired' ) ? 'retired' : 'restored';
			do_action( 'supcomp_data_changed', array( 'source' => 'canonical_status', 'id' => $id, 'status' => $status ) );
		} else {
			$notice = 'error';
		}

		wp_safe_redirect( self::url( array( 'supcomp_notice' => $notice ) ) );
		exit;
	}

	public static function handle_import() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'supplement-compare' ) );
		}
		check_admin_referer( self::NONCE_IMP );

		if ( empty( $_FILES['csvfile']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csvfile']['tmp_name'] ) ) {
			wp_safe_redirect( self::url( array( 'action' => 'import', 'supcomp_notice' => 'error', 'msg' => rawurlencode( __( 'No file uploaded.', 'supplement-compare' ) ) ) ) );
			exit;
		}

		$report = Supcomp_Canonical_CSV_Importer::import_canonical_products( $_FILES['csvfile']['tmp_name'] );
		if ( is_wp_error( $report ) ) {
			wp_safe_redirect( self::url( array( 'action' => 'import', 'supcomp_notice' => 'error', 'msg' => rawurlencode( $report->get_error_message() ) ) ) );
			exit;
		}

		set_transient( 'supcomp_import_report_canonical_' . get_current_user_id(), $report, MINUTE_IN_SECONDS * 10 );
		do_action( 'supcomp_data_changed', array( 'source' => 'canonical_csv_import', 'inserted' => $report['inserted'], 'updated' => $report['updated'] ) );
		wp_safe_redirect( self::url( array( 'action' => 'import', 'supcomp_notice' => 'imported' ) ) );
		exit;
	}

	// ---------- helpers ----------

	private static function url( $args = array() ) {
		$args = array_merge( array( 'page' => self::PAGE_SLUG ), $args );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private static function trim_decimal( $val ) {
		if ( $val === null || $val === '' ) {
			return '';
		}
		$str = is_string( $val ) ? $val : number_format( (float) $val, 4, '.', '' );
		// Strip trailing zeros only when the string has a decimal point.
		if ( strpos( $str, '.' ) !== false ) {
			$str = rtrim( rtrim( $str, '0' ), '.' );
		}
		return $str;
	}

	private static function render_notice() {
		if ( empty( $_GET['supcomp_notice'] ) ) {
			return;
		}
		$type = sanitize_key( wp_unslash( $_GET['supcomp_notice'] ) );
		$msg  = isset( $_GET['msg'] ) ? wp_unslash( $_GET['msg'] ) : '';

		$messages = array(
			'created'  => array( 'success', __( 'Canonical product created.', 'supplement-compare' ) ),
			'updated'  => array( 'success', __( 'Canonical product saved.', 'supplement-compare' ) ),
			'retired'  => array( 'success', __( 'Canonical product retired.', 'supplement-compare' ) ),
			'restored' => array( 'success', __( 'Canonical product restored.', 'supplement-compare' ) ),
			'imported' => array( 'success', __( 'CSV imported. See report below.', 'supplement-compare' ) ),
			'error'    => array( 'error',   $msg !== '' ? $msg : __( 'Something went wrong.', 'supplement-compare' ) ),
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
