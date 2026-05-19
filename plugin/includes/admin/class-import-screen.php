<?php
/**
 * CSV import admin screen.
 *
 *   admin.php?page=supcomp-import                — history (recent import runs)
 *   admin.php?page=supcomp-import&action=upload  — file upload form
 *   admin.php?page=supcomp-import&action=detail&run=N — run detail view
 *
 * Upload handler posts to admin-post.php (action=supcomp_run_csv_import). On
 * success it stores a per-user transient with the import result and redirects
 * to the detail view (live import) or back to the upload form (dry-run).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Import_Screen {

	const PAGE_SLUG    = 'supcomp-import';
	const NONCE_UPLOAD = 'supcomp_run_csv_import';
	const TRANSIENT_PREFIX = 'supcomp_csv_import_result_';

	public static function register_hooks() {
		add_action( 'admin_post_supcomp_run_csv_import', array( __CLASS__, 'handle_upload' ) );
	}

	public static function render() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'supplement-compare' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'history';
		switch ( $action ) {
			case 'upload':
				self::render_upload();
				break;
			case 'detail':
				$run_id = isset( $_GET['run'] ) ? absint( wp_unslash( $_GET['run'] ) ) : 0;
				self::render_detail( $run_id );
				break;
			case 'history':
			default:
				self::render_history();
				break;
		}
	}

	// ---------- history ----------

	private static function render_history() {
		$runs = Supcomp_Import_Runs_Repo::recent( 50 );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'CSV Imports', 'supplement-compare' ); ?></h1>
			<a href="<?php echo esc_url( self::url( array( 'action' => 'upload' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Upload CSV', 'supplement-compare' ); ?></a>
			<hr class="wp-header-end">

			<?php self::render_notice(); ?>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Run', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'File', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Imported', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Status', 'supplement-compare' ); ?></th>
						<th title="<?php esc_attr_e( 'Rows in CSV', 'supplement-compare' ); ?>"><?php esc_html_e( 'Rows', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'New', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Updated', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Stale', 'supplement-compare' ); ?></th>
						<th><?php esc_html_e( 'Errored', 'supplement-compare' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $runs ) ) : ?>
						<tr><td colspan="10"><?php esc_html_e( 'No imports yet. Upload a CSV to get started.', 'supplement-compare' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $runs as $r ) : ?>
						<tr>
							<td>#<?php echo (int) $r->id; ?></td>
							<td><code><?php echo esc_html( $r->csv_filename ); ?></code></td>
							<td><?php echo esc_html( self::pretty_date( $r->imported_at ) ); ?></td>
							<td><?php echo esc_html( $r->status ); ?></td>
							<td><?php echo (int) $r->row_count; ?></td>
							<td><?php echo (int) $r->rows_inserted; ?></td>
							<td><?php echo (int) $r->rows_updated; ?></td>
							<td><?php echo (int) $r->rows_marked_stale; ?></td>
							<td><?php echo (int) $r->rows_errored; ?></td>
							<td><a href="<?php echo esc_url( self::url( array( 'action' => 'detail', 'run' => $r->id ) ) ); ?>"><?php esc_html_e( 'Detail', 'supplement-compare' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	// ---------- upload ----------

	private static function render_upload() {
		$max_bytes = wp_max_upload_size();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Upload CSV', 'supplement-compare' ); ?></h1>
			<?php self::render_notice(); ?>
			<?php self::render_dry_run_result(); ?>

			<p><?php
				printf(
					/* translators: %s is the formatted max upload size, e.g. "8 MB" */
					esc_html__( 'Max upload size: %s. The CSV must match the column contract in PROJECTBRIEF.md §4. Required columns: export_run_id, exported_at, source, site, source_product_id, product_title, on_sale, stock_status, source_product_url, variation_retrieval_status. Each row\'s `site` must match a merchant that exists and is active.', 'supplement-compare' ),
					esc_html( size_format( $max_bytes ) )
				);
			?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="supcomp_run_csv_import">
				<?php wp_nonce_field( self::NONCE_UPLOAD ); ?>

				<p>
					<input type="file" name="csvfile" accept=".csv,text/csv" required>
				</p>
				<p>
					<label>
						<input type="checkbox" name="dry_run" value="1">
						<?php esc_html_e( 'Dry run — validate only, do not write to the database', 'supplement-compare' ); ?>
					</label>
				</p>

				<?php submit_button( __( 'Run Import', 'supplement-compare' ) ); ?>
				<a href="<?php echo esc_url( self::url() ); ?>" class="button"><?php esc_html_e( 'Cancel', 'supplement-compare' ); ?></a>
			</form>
		</div>
		<?php
	}

	private static function render_dry_run_result() {
		$key    = self::TRANSIENT_PREFIX . 'dryrun_' . get_current_user_id();
		$result = get_transient( $key );
		if ( ! $result ) {
			return;
		}
		delete_transient( $key );
		?>
		<h2><?php esc_html_e( 'Dry-run result', 'supplement-compare' ); ?></h2>
		<p>
			<?php echo esc_html( sprintf( __( 'Would insert: %d', 'supplement-compare' ), (int) $result['inserted'] ) ); ?>
			&nbsp;|&nbsp;
			<?php echo esc_html( sprintf( __( 'Would update: %d', 'supplement-compare' ), (int) $result['updated'] ) ); ?>
			&nbsp;|&nbsp;
			<?php echo esc_html( sprintf( __( 'Row errors: %d', 'supplement-compare' ), count( $result['validation_errors'] ) ) ); ?>
		</p>
		<?php if ( ! empty( $result['missing_merchants'] ) ) : ?>
			<div class="notice notice-warning inline"><p>
				<strong><?php esc_html_e( 'Unknown merchant site URLs in this CSV:', 'supplement-compare' ); ?></strong>
				<ul style="margin:0.5em 0 0 1.5em">
					<?php foreach ( $result['missing_merchants'] as $site => $count ) : ?>
						<li><code><?php echo esc_html( $site ); ?></code> — <?php echo esc_html( sprintf( _n( '%d row', '%d rows', $count, 'supplement-compare' ), $count ) ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p><?php esc_html_e( 'Create these merchants before re-running the import.', 'supplement-compare' ); ?></p>
			</p></div>
		<?php endif; ?>
		<?php if ( ! empty( $result['validation_errors'] ) ) : ?>
			<details open style="margin-top:0.5em">
				<summary><?php esc_html_e( 'Per-row errors', 'supplement-compare' ); ?></summary>
				<ul>
					<?php foreach ( $result['validation_errors'] as $row_num => $msg ) : ?>
						<li><strong><?php echo esc_html( sprintf( __( 'row %d', 'supplement-compare' ), $row_num ) ); ?>:</strong> <?php echo esc_html( $msg ); ?></li>
					<?php endforeach; ?>
				</ul>
			</details>
		<?php endif; ?>
		<?php
	}

	// ---------- detail ----------

	private static function render_detail( $run_id ) {
		$run = $run_id ? Supcomp_Import_Runs_Repo::get( $run_id ) : null;
		if ( ! $run ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Run not found', 'supplement-compare' ) . '</h1></div>';
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( sprintf( __( 'Import #%d', 'supplement-compare' ), (int) $run->id ) ); ?></h1>
			<p>
				<a href="<?php echo esc_url( self::url() ); ?>">&laquo; <?php esc_html_e( 'Back to history', 'supplement-compare' ); ?></a>
			</p>
			<?php self::render_notice(); ?>

			<table class="form-table" role="presentation">
				<tr><th><?php esc_html_e( 'File', 'supplement-compare' ); ?></th><td><code><?php echo esc_html( $run->csv_filename ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'export_run_id', 'supplement-compare' ); ?></th><td><code><?php echo esc_html( $run->export_run_id ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'CSV exported at', 'supplement-compare' ); ?></th><td><?php echo esc_html( self::pretty_date( $run->exported_at ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Imported at', 'supplement-compare' ); ?></th><td><?php echo esc_html( self::pretty_date( $run->imported_at ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Status', 'supplement-compare' ); ?></th><td><?php echo esc_html( $run->status ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Rows in file', 'supplement-compare' ); ?></th><td><?php echo (int) $run->row_count; ?></td></tr>
				<tr><th><?php esc_html_e( 'New offers (pending)', 'supplement-compare' ); ?></th><td><?php echo (int) $run->rows_inserted; ?></td></tr>
				<tr><th><?php esc_html_e( 'Updated offers', 'supplement-compare' ); ?></th><td><?php echo (int) $run->rows_updated; ?></td></tr>
				<tr><th><?php esc_html_e( 'Marked stale', 'supplement-compare' ); ?></th><td><?php echo (int) $run->rows_marked_stale; ?></td></tr>
				<tr><th><?php esc_html_e( 'Errored rows', 'supplement-compare' ); ?></th><td><?php echo (int) $run->rows_errored; ?></td></tr>
			</table>

			<?php if ( ! empty( $run->error_log ) ) : ?>
				<h2><?php esc_html_e( 'Error log', 'supplement-compare' ); ?></h2>
				<pre style="background:#f6f7f7;border:1px solid #ddd;padding:1em;max-height:30em;overflow:auto"><?php echo esc_html( $run->error_log ); ?></pre>
			<?php endif; ?>
		</div>
		<?php
	}

	// ---------- POST handler ----------

	public static function handle_upload() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'supplement-compare' ) );
		}
		check_admin_referer( self::NONCE_UPLOAD );

		if ( empty( $_FILES['csvfile']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csvfile']['tmp_name'] ) ) {
			wp_safe_redirect( self::url( array( 'action' => 'upload', 'supcomp_notice' => 'error', 'msg' => rawurlencode( __( 'No file uploaded.', 'supplement-compare' ) ) ) ) );
			exit;
		}

		$filename = isset( $_FILES['csvfile']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['csvfile']['name'] ) ) : 'upload.csv';
		$dry_run  = ! empty( $_POST['dry_run'] );
		$tmp_path = $_FILES['csvfile']['tmp_name'];

		$validated = Supcomp_CSV_Validator::validate( $tmp_path );

		if ( $validated['fatal'] !== null ) {
			wp_safe_redirect( self::url( array( 'action' => 'upload', 'supcomp_notice' => 'error', 'msg' => rawurlencode( $validated['fatal'] ) ) ) );
			exit;
		}

		if ( ! $validated['ok'] ) {
			// Validation gate: row errors → no writes regardless of dry_run.
			$result = array(
				'inserted'          => 0,
				'updated'           => 0,
				'validation_errors' => $validated['errors'],
				'missing_merchants' => $validated['missing_merchants'],
			);
			set_transient( self::TRANSIENT_PREFIX . 'dryrun_' . get_current_user_id(), $result, MINUTE_IN_SECONDS * 10 );
			wp_safe_redirect( self::url( array( 'action' => 'upload', 'supcomp_notice' => 'validation_failed' ) ) );
			exit;
		}

		if ( $dry_run ) {
			$dry = Supcomp_CSV_Importer::import( $validated, array( 'dry_run' => true, 'filename' => $filename ) );
			$result = array(
				'inserted'          => $dry['inserted'],
				'updated'           => $dry['updated'],
				'validation_errors' => array(),
				'missing_merchants' => array(),
			);
			set_transient( self::TRANSIENT_PREFIX . 'dryrun_' . get_current_user_id(), $result, MINUTE_IN_SECONDS * 10 );
			wp_safe_redirect( self::url( array( 'action' => 'upload', 'supcomp_notice' => 'dry_run_ok' ) ) );
			exit;
		}

		$run_result = Supcomp_CSV_Importer::import( $validated, array( 'dry_run' => false, 'filename' => $filename ) );
		wp_safe_redirect( self::url( array( 'action' => 'detail', 'run' => $run_result['run_id'], 'supcomp_notice' => 'imported' ) ) );
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
			'imported'          => array( 'success', __( 'Import complete. See run detail below.', 'supplement-compare' ) ),
			'dry_run_ok'        => array( 'info',    __( 'Dry run complete. No changes written.', 'supplement-compare' ) ),
			'validation_failed' => array( 'error',   __( 'Validation failed. No changes written. See errors below.', 'supplement-compare' ) ),
			'error'             => array( 'error',   $msg !== '' ? $msg : __( 'Something went wrong.', 'supplement-compare' ) ),
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

	private static function pretty_date( $mysql_datetime ) {
		if ( empty( $mysql_datetime ) || $mysql_datetime === '0000-00-00 00:00:00' ) {
			return '—';
		}
		return get_date_from_gmt( $mysql_datetime, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}
}
