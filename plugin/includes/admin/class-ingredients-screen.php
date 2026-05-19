<?php
/**
 * Canonical ingredients admin screen.
 *
 * URL routing (the `render()` dispatcher reads `?action=`):
 *   admin.php?page=supcomp-ingredients                        — list
 *   admin.php?page=supcomp-ingredients&action=new             — create form
 *   admin.php?page=supcomp-ingredients&action=edit&id=N       — edit form
 *   admin.php?page=supcomp-ingredients&action=import          — CSV upload form
 *
 * Form submissions POST to admin-post.php with a nonce; admin_post_* hooks
 * (registered from class-plugin.php) route them to handle_save() / etc.,
 * which process and redirect (PRG) back to the screen with a notice flag.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Ingredients_Screen {

	const PAGE_SLUG  = 'supcomp-ingredients';
	const NONCE_SAVE = 'supcomp_save_ingredient';
	const NONCE_STAT = 'supcomp_set_ingredient_status';
	const NONCE_IMP  = 'supcomp_import_ingredients';

	public static function register_hooks() {
		add_action( 'admin_post_supcomp_save_ingredient', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_supcomp_set_ingredient_status', array( __CLASS__, 'handle_status' ) );
		add_action( 'admin_post_supcomp_import_ingredients', array( __CLASS__, 'handle_import' ) );
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
		$category = isset( $_GET['category'] ) ? sanitize_key( wp_unslash( $_GET['category'] ) ) : '';
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$rows = Supcomp_Ingredients_Repo::query(
			array(
				'category' => $category,
				'status'   => $status,
				'search'   => $search,
			)
		);

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Canonical Ingredients', 'supplement-compare' ); ?></h1>
			<a href="<?php echo esc_url( self::url( array( 'action' => 'new' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'supplement-compare' ); ?></a>
			<a href="<?php echo esc_url( self::url( array( 'action' => 'import' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Import CSV', 'supplement-compare' ); ?></a>
			<hr class="wp-header-end">

			<?php self::render_notice(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<p class="search-box">
					<label class="screen-reader-text" for="supcomp-ingredient-search"><?php esc_html_e( 'Search', 'supplement-compare' ); ?></label>
					<input type="search" id="supcomp-ingredient-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name, slug, alias…', 'supplement-compare' ); ?>">

					<select name="category">
						<option value=""><?php esc_html_e( 'All categories', 'supplement-compare' ); ?></option>
						<?php foreach ( Supcomp_Installer::INGREDIENT_CATEGORIES as $c ) : ?>
							<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $category, $c ); ?>><?php echo esc_html( $c ); ?></option>
						<?php endforeach; ?>
					</select>

					<select name="status">
						<option value=""><?php esc_html_e( 'All statuses', 'supplement-compare' ); ?></option>
						<?php foreach ( Supcomp_Installer::INGREDIENT_STATUSES as $s ) : ?>
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
						<th><?php esc_html_e( 'Category', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Unit', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Elemental %', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Std. compound', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Std. default %', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Status', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'supplement-compare' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="9"><?php esc_html_e( 'No ingredients match the current filter. Add one or import a CSV to get started.', 'supplement-compare' ); ?></td></tr>
					<?php endif; ?>

					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><strong><a href="<?php echo esc_url( self::url( array( 'action' => 'edit', 'id' => $r->id ) ) ); ?>"><?php echo esc_html( $r->name ); ?></a></strong></td>
							<td><code><?php echo esc_html( $r->slug ); ?></code></td>
							<td><?php echo esc_html( $r->category ); ?></td>
							<td><?php echo esc_html( $r->default_unit ); ?></td>
							<td><?php echo $r->elemental_percentage !== null ? esc_html( rtrim( rtrim( $r->elemental_percentage, '0' ), '.' ) ) : '—'; ?></td>
							<td><?php echo esc_html( $r->standardization_compound ? $r->standardization_compound : '—' ); ?></td>
							<td><?php echo $r->standardization_default_pct !== null ? esc_html( rtrim( rtrim( $r->standardization_default_pct, '0' ), '.' ) ) : '—'; ?></td>
							<td><?php echo esc_html( $r->status ); ?></td>
							<td>
								<a href="<?php echo esc_url( self::url( array( 'action' => 'edit', 'id' => $r->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'supplement-compare' ); ?></a>
								<?php
								$next_status = ( $r->status === 'retired' ) ? 'active' : 'retired';
								$next_label  = ( $r->status === 'retired' ) ? __( 'Restore', 'supplement-compare' ) : __( 'Retire', 'supplement-compare' );
								?>
								&nbsp;|&nbsp;
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<input type="hidden" name="action" value="supcomp_set_ingredient_status">
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
		$row = $id ? Supcomp_Ingredients_Repo::get( $id ) : null;
		if ( $id && ! $row ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Ingredient not found', 'supplement-compare' ) . '</h1></div>';
			return;
		}

		$slug                       = $row ? $row->slug : '';
		$name                       = $row ? $row->name : '';
		$aliases                    = $row ? implode( ', ', Supcomp_Ingredients_Repo::decode_aliases( $row->aliases_json ) ) : '';
		$category                   = $row ? $row->category : 'other';
		$default_unit               = $row ? $row->default_unit : 'mg';
		$elemental_percentage       = $row && $row->elemental_percentage !== null ? $row->elemental_percentage : '';
		$standardization_compound   = $row ? (string) $row->standardization_compound : '';
		$standardization_default_pct = $row && $row->standardization_default_pct !== null ? $row->standardization_default_pct : '';
		$status                     = $row ? $row->status : 'draft';
		$notes                      = $row ? (string) $row->notes : '';

		$heading = $id ? __( 'Edit Ingredient', 'supplement-compare' ) : __( 'Add Ingredient', 'supplement-compare' );

		?>
		<div class="wrap">
			<h1><?php echo esc_html( $heading ); ?></h1>
			<?php self::render_notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="supcomp_save_ingredient">
				<?php if ( $id ) : ?>
					<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
				<?php endif; ?>
				<?php wp_nonce_field( self::NONCE_SAVE ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th><label for="supcomp-slug"><?php esc_html_e( 'Slug', 'supplement-compare' ); ?> <span class="description">*</span></label></th>
						<td><input type="text" id="supcomp-slug" name="slug" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" required>
							<p class="description"><?php esc_html_e( 'URL-safe identifier, e.g. "l-theanine". Becomes the natural key — used in CSV imports and links.', 'supplement-compare' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="supcomp-name"><?php esc_html_e( 'Name', 'supplement-compare' ); ?> <span class="description">*</span></label></th>
						<td><input type="text" id="supcomp-name" name="name" value="<?php echo esc_attr( $name ); ?>" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label for="supcomp-aliases"><?php esc_html_e( 'Aliases', 'supplement-compare' ); ?></label></th>
						<td><input type="text" id="supcomp-aliases" name="aliases" value="<?php echo esc_attr( $aliases ); ?>" class="large-text">
							<p class="description"><?php esc_html_e( 'Comma- or pipe-separated. Used for matching merchant titles to this canonical ingredient and for visitor search.', 'supplement-compare' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="supcomp-category"><?php esc_html_e( 'Category', 'supplement-compare' ); ?></label></th>
						<td>
							<select id="supcomp-category" name="category">
								<?php foreach ( Supcomp_Installer::INGREDIENT_CATEGORIES as $c ) : ?>
									<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $category, $c ); ?>><?php echo esc_html( $c ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="supcomp-unit"><?php esc_html_e( 'Default unit', 'supplement-compare' ); ?></label></th>
						<td>
							<select id="supcomp-unit" name="default_unit">
								<?php foreach ( Supcomp_Installer::INGREDIENT_UNITS as $u ) : ?>
									<option value="<?php echo esc_attr( $u ); ?>" <?php selected( $default_unit, $u ); ?>><?php echo esc_html( $u ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The unit the cost-per-active-unit math reports in. Most compounds: mg. Vitamins: IU or mcg. Probiotics: billion_cfu.', 'supplement-compare' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="supcomp-elemental"><?php esc_html_e( 'Elemental %', 'supplement-compare' ); ?></label></th>
						<td><input type="number" id="supcomp-elemental" name="elemental_percentage" value="<?php echo esc_attr( $elemental_percentage ); ?>" step="0.01" min="0" max="100" class="small-text"> %
							<p class="description"><?php esc_html_e( 'For minerals where the listed weight is the salt and the active fraction is smaller. E.g. magnesium glycinate is 14.10% elemental magnesium. Leave blank otherwise.', 'supplement-compare' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="supcomp-stdcompound"><?php esc_html_e( 'Standardization compound', 'supplement-compare' ); ?></label></th>
						<td><input type="text" id="supcomp-stdcompound" name="standardization_compound" value="<?php echo esc_attr( $standardization_compound ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'For herbal extracts. The active marker compound, e.g. "bacosides" for Bacopa monnieri. Leave blank otherwise.', 'supplement-compare' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="supcomp-stddefault"><?php esc_html_e( 'Standardization default %', 'supplement-compare' ); ?></label></th>
						<td><input type="number" id="supcomp-stddefault" name="standardization_default_pct" value="<?php echo esc_attr( $standardization_default_pct ); ?>" step="0.01" min="0" max="100" class="small-text"> %
							<p class="description"><?php esc_html_e( 'Typical standardization for this ingredient. Individual canonical products can override.', 'supplement-compare' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="supcomp-status"><?php esc_html_e( 'Status', 'supplement-compare' ); ?></label></th>
						<td>
							<select id="supcomp-status" name="status">
								<?php foreach ( Supcomp_Installer::INGREDIENT_STATUSES as $s ) : ?>
									<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( $s ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="supcomp-notes"><?php esc_html_e( 'Notes', 'supplement-compare' ); ?></label></th>
						<td><textarea id="supcomp-notes" name="notes" rows="3" class="large-text"><?php echo esc_textarea( $notes ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Operator-only. Never shown publicly.', 'supplement-compare' ); ?></p></td>
					</tr>
				</table>

				<?php submit_button( $id ? __( 'Save Ingredient', 'supplement-compare' ) : __( 'Create Ingredient', 'supplement-compare' ) ); ?>
				<a href="<?php echo esc_url( self::url() ); ?>" class="button"><?php esc_html_e( 'Cancel', 'supplement-compare' ); ?></a>
			</form>
		</div>
		<?php
	}

	// ---------- import ----------

	private static function render_import() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Ingredients CSV', 'supplement-compare' ); ?></h1>
			<?php self::render_notice(); ?>

			<p><?php
				printf(
					/* translators: %s is a comma-separated list of column names */
					esc_html__( 'Upload a CSV with a header row. Required column: %s. Optional columns: aliases, category, default_unit, elemental_percentage, standardization_compound, standardization_default_pct, status, notes.', 'supplement-compare' ),
					'<code>slug</code>, <code>name</code>'
				);
			?></p>
			<p><?php esc_html_e( 'Rows are upserted by slug. Re-importing the same CSV is idempotent. Removing a row from the CSV does NOT retire the ingredient; use the Retire action.', 'supplement-compare' ); ?></p>
			<p><?php esc_html_e( 'A template lives at seed-data/ingredients.example.csv in the repo.', 'supplement-compare' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="supcomp_import_ingredients">
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
		$key    = 'supcomp_import_report_ingredients_' . get_current_user_id();
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

		$data = wp_unslash( $_POST );
		// Drop fields that aren't part of the model.
		unset( $data['action'], $data['_wpnonce'], $data['_wp_http_referer'], $data['id'] );

		$result = Supcomp_Ingredients_Repo::upsert( $data );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( self::url( array( 'action' => 'new', 'supcomp_notice' => 'error', 'msg' => rawurlencode( $result->get_error_message() ) ) ) );
			exit;
		}

		do_action( 'supcomp_data_changed', array( 'source' => 'ingredient_save', 'id' => $result['id'] ) );
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
		if ( $id && Supcomp_Ingredients_Repo::set_status( $id, $status ) ) {
			$notice = ( $status === 'retired' ) ? 'retired' : 'restored';
			do_action( 'supcomp_data_changed', array( 'source' => 'ingredient_status', 'id' => $id, 'status' => $status ) );
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

		$report = Supcomp_Canonical_CSV_Importer::import_ingredients( $_FILES['csvfile']['tmp_name'] );
		if ( is_wp_error( $report ) ) {
			wp_safe_redirect( self::url( array( 'action' => 'import', 'supcomp_notice' => 'error', 'msg' => rawurlencode( $report->get_error_message() ) ) ) );
			exit;
		}

		set_transient( 'supcomp_import_report_ingredients_' . get_current_user_id(), $report, MINUTE_IN_SECONDS * 10 );
		do_action( 'supcomp_data_changed', array( 'source' => 'ingredient_csv_import', 'inserted' => $report['inserted'], 'updated' => $report['updated'] ) );

		wp_safe_redirect( self::url( array( 'action' => 'import', 'supcomp_notice' => 'imported' ) ) );
		exit;
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
			'created'  => array( 'success', __( 'Ingredient created.', 'supplement-compare' ) ),
			'updated'  => array( 'success', __( 'Ingredient saved.', 'supplement-compare' ) ),
			'retired'  => array( 'success', __( 'Ingredient retired.', 'supplement-compare' ) ),
			'restored' => array( 'success', __( 'Ingredient restored.', 'supplement-compare' ) ),
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
