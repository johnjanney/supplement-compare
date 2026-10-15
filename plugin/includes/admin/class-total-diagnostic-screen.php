<?php
/**
 * Total Active Diagnostic admin screen (v1.42.0).
 *
 * Read-only. Takes every offer whose total active / container is already
 * known — typed by the operator, or approved — re-reads its latest raw
 * merchant row, and reports (via Supcomp_Total_Active_Probe) where that total
 * appears in the merchant data and whether today's strength × servings math
 * reproduces it. The results decide what Total-active auto-extraction should
 * target first. Nothing is written.
 *
 * Runs only when the operator clicks "Run diagnostic" (it re-normalizes every
 * confirmed offer), within a time budget so a large catalogue can't time out
 * the request. "Download CSV" exports the per-offer rows.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Total_Diagnostic_Screen {

	const PAGE_SLUG     = 'supcomp-total-diagnostic';
	const NONCE_CSV     = 'supcomp_total_diagnostic_csv';
	const MAX_OFFERS    = 5000;
	const TIME_BUDGET_S = 20;
	const MAX_LISTED    = 100;

	const SOURCE_LABELS = array(
		'title'               => 'Title / variant title',
		'attribute'           => 'Product attribute',
		'pack'                => 'Pack notation (N × amount)',
		'description'         => 'Description only',
		'per_serving_x_count' => 'Only as per-serving × count',
		'not_found'           => 'Not found',
	);

	public static function register_hooks() {
		add_action( 'admin_post_supcomp_total_diagnostic_csv', array( __CLASS__, 'handle_csv' ) );
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function render() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'supplement-compare' ) );
		}
		$run = ! empty( $_GET['run'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Total Active Diagnostic', 'supplement-compare' ); ?></h1>
			<p class="description" style="max-width:60em">
				<?php esc_html_e( 'Read-only. Looks at every offer whose Total active / container is already confirmed — a total you typed in the offer editor, or the total of an offer you approved — and checks where that number appears in the merchant\'s own data (title, attributes, description) and whether today\'s automatic strength × servings math gets it right. Nothing is changed.', 'supplement-compare' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( self::url( array( 'run' => 1 ) ) ); ?>"><?php esc_html_e( 'Run diagnostic', 'supplement-compare' ); ?></a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=supcomp_total_diagnostic_csv' ), self::NONCE_CSV ) ); ?>"><?php esc_html_e( 'Download CSV', 'supplement-compare' ); ?></a>
			</p>
			<?php
			if ( $run ) {
				self::render_results( self::collect() );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Run the probe over every confirmed offer.
	 *
	 * @return array{rows: array[], complete: bool, queue_missing: int}
	 */
	public static function collect() {
		global $wpdb;
		$o  = Supcomp_Offers_Repo::table();
		$m  = $wpdb->prefix . 'supcomp_merchants';
		$ci = $wpdb->prefix . 'supcomp_canonical_ingredients';

		$offers = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.*, m.name AS merchant_name, m.platform AS merchant_platform, ci.default_unit AS ingredient_unit
				 FROM {$o} o
				 LEFT JOIN {$m} m ON m.id = o.merchant_id
				 LEFT JOIN {$ci} ci ON ci.id = o.ingredient_id
				 WHERE o.total_active_per_container > 0
				    OR ( o.visibility_status IN ('active', 'paused') AND o.total_strength > 0 )
				 ORDER BY o.id ASC
				 LIMIT %d",
				self::MAX_OFFERS
			)
		);

		$started  = microtime( true );
		$rows     = array();
		$complete = count( $offers ) < self::MAX_OFFERS;
		foreach ( $offers as $offer ) {
			if ( microtime( true ) - $started > self::TIME_BUDGET_S ) {
				$complete = false;
				break;
			}
			$rows[] = self::probe_offer( $offer );
		}

		$queue_missing = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$o}
			 WHERE visibility_status IN ('pending', 'needs_review')
			   AND ( total_strength IS NULL OR total_strength <= 0 )"
		);

		return array(
			'rows'          => $rows,
			'complete'      => $complete,
			'queue_missing' => $queue_missing,
		);
	}

	private static function probe_offer( $offer ) {
		$operator = $offer->total_active_per_container !== null && (float) $offer->total_active_per_container > 0;
		$truth    = $operator ? (float) $offer->total_active_per_container : (float) $offer->total_strength;
		$unit     = $offer->strength_unit ? $offer->strength_unit : ( $offer->ingredient_unit ? $offer->ingredient_unit : 'mg' );

		// Latest raw merchant row; fall back to the offer's own title fields
		// when the raw history has been pruned.
		$raw     = Supcomp_Offers_Repo::latest_raw_for( $offer );
		$raw_row = $raw && $raw->raw_csv_row_json ? json_decode( (string) $raw->raw_csv_row_json, true ) : null;
		$has_raw = is_array( $raw_row );
		if ( ! $has_raw ) {
			$raw_row = array(
				'product_title' => $offer->product_title,
				'variant_title' => $offer->variant_title,
				'brand'         => $offer->brand,
				'sku'           => $offer->sku,
			);
		}

		// Today's automatic answer, recomputed from the raw row rather than
		// read from the offer (whose fields may carry operator edits).
		$auto = Supcomp_Normalizer::normalize( $raw_row );

		$result = Supcomp_Total_Active_Probe::probe(
			array(
				'truth'                 => $truth,
				'truth_unit'            => $unit,
				'product_title'         => isset( $raw_row['product_title'] ) ? (string) $raw_row['product_title'] : '',
				'variant_title'         => isset( $raw_row['variant_title'] ) ? (string) $raw_row['variant_title'] : '',
				'description'           => isset( $raw_row['description'] ) ? (string) $raw_row['description'] : '',
				'raw_attributes_json'   => isset( $raw_row['raw_attributes_json'] ) ? (string) $raw_row['raw_attributes_json'] : '',
				'current_strength'      => $auto['strength_per_serving'],
				'current_strength_unit' => $auto['strength_unit'],
				'current_servings'      => $auto['servings_per_container'],
			)
		);

		// What the single-container filler (v1.43.0) would suggest for this
		// offer if it arrived fresh, scored against the confirmed total.
		$fill = Supcomp_Total_Filler::suggest(
			array(
				'product_title'       => isset( $raw_row['product_title'] ) ? (string) $raw_row['product_title'] : '',
				'variant_title'       => isset( $raw_row['variant_title'] ) ? (string) $raw_row['variant_title'] : '',
				'raw_attributes_json' => isset( $raw_row['raw_attributes_json'] ) ? (string) $raw_row['raw_attributes_json'] : '',
				'ingredient_form'     => $auto['ingredient_form'],
				'servings'            => $auto['servings_per_container'],
				'has_siblings'        => Supcomp_Total_Filler::has_siblings( $offer ),
				'variant_level_attributes' => Supcomp_Total_Filler::variant_level_attributes( $raw_row ),
			)
		);
		$fill_status = 'none';
		if ( $fill ) {
			$conv        = Supcomp_Total_Active_Probe::convert( $fill['total'], $fill['unit'], Supcomp_Total_Active_Probe::canonical_unit( $unit ) );
			$fill_status = ( $conv !== null && abs( $conv - $truth ) <= Supcomp_Total_Active_Probe::TOLERANCE * $truth ) ? 'right' : 'wrong';
		}

		return array_merge(
			array(
				'offer_id'          => (int) $offer->id,
				'fill_total'        => $fill ? $fill['total'] : null,
				'fill_unit'         => $fill ? $fill['unit'] : '',
				'fill_source'       => $fill ? $fill['source'] : '',
				'fill_status'       => $fill_status,
				'visibility'        => (string) $offer->visibility_status,
				'merchant'          => $offer->merchant_name !== null ? (string) $offer->merchant_name : '#' . (int) $offer->merchant_id,
				'platform'          => (string) ( $offer->merchant_platform ? $offer->merchant_platform : $offer->source_platform ),
				'product_title'     => (string) $offer->product_title,
				'variant_title'     => (string) $offer->variant_title,
				'truth'             => $truth,
				'truth_unit'        => (string) $unit,
				'truth_source'      => $operator ? 'operator_total' : 'approved_derived',
				'has_raw'           => $has_raw,
				'auto_strength'     => $auto['strength_per_serving'],
				'auto_unit'         => $auto['strength_unit'],
				'auto_servings'     => $auto['servings_per_container'],
			),
			$result
		);
	}

	private static function render_results( array $data ) {
		$rows = $data['rows'];
		$n    = count( $rows );
		if ( $n === 0 ) {
			echo '<p><em>' . esc_html__( 'No confirmed totals yet. The diagnostic needs offers with a Total active / container typed in, or approved offers with a computed total.', 'supplement-compare' ) . '</em></p>';
			return;
		}

		$by_truth = array( 'operator_total' => 0, 'approved_derived' => 0 );
		$sources  = array();
		$status   = array( 'ok' => 0, 'wrong' => 0, 'missing' => 0 );
		$s_total  = 0;
		$no_raw   = 0;
		$attr_key = array();
		$merchant = array();
		foreach ( $rows as $r ) {
			++$by_truth[ $r['truth_source'] ];
			$sources[ $r['best_source'] ][ $r['truth_source'] ] = ( $sources[ $r['best_source'] ][ $r['truth_source'] ] ?? 0 ) + 1;
			++$status[ $r['current_status'] ];
			if ( $r['strength_is_total'] ) {
				++$s_total;
			}
			if ( ! $r['has_raw'] ) {
				++$no_raw;
			}
			if ( in_array( 'attribute', $r['found_in'], true ) && $r['best_source'] === 'attribute' ) {
				$k              = $r['attribute_key'] !== '' ? $r['attribute_key'] : '(unnamed)';
				$attr_key[ $k ] = ( $attr_key[ $k ] ?? 0 ) + 1;
			}
			$mk = $r['merchant'];
			if ( ! isset( $merchant[ $mk ] ) ) {
				$merchant[ $mk ] = array( 'platform' => $r['platform'], 'n' => 0, 'ok' => 0, 'wrong' => 0, 'missing' => 0, 'sources' => array() );
			}
			++$merchant[ $mk ]['n'];
			++$merchant[ $mk ][ $r['current_status'] ];
			$merchant[ $mk ]['sources'][ $r['best_source'] ] = ( $merchant[ $mk ]['sources'][ $r['best_source'] ] ?? 0 ) + 1;
		}
		arsort( $attr_key );
		ksort( $merchant );

		$pct = static function ( $x ) use ( $n ) {
			return sprintf( '%d (%s%%)', $x, number_format( 100 * $x / $n, 0 ) );
		};
		?>
		<?php if ( ! $data['complete'] ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( __( 'Partial run: stopped after %d offers (time or size limit). The CSV export has the same limit.', 'supplement-compare' ), $n ) ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Sample', 'supplement-compare' ); ?></h2>
		<p>
			<?php
			echo esc_html( sprintf(
				__( '%1$d confirmed offers: %2$d with a total you typed in, %3$d approved with a computed total. %4$d had no raw merchant row left (titles only). %5$d offers in the queue have no total at all.', 'supplement-compare' ),
				$n,
				$by_truth['operator_total'],
				$by_truth['approved_derived'],
				$no_raw,
				$data['queue_missing']
			) );
			?>
		</p>
		<p class="description" style="max-width:60em"><?php esc_html_e( '"Approved" totals were usually produced by the same strength × servings math being tested, so they flatter it. The "Typed in" column is the honest one.', 'supplement-compare' ); ?></p>

		<h2><?php esc_html_e( 'Where the confirmed total appears', 'supplement-compare' ); ?></h2>
		<table class="widefat striped" style="max-width:50em">
			<thead><tr>
				<th><?php esc_html_e( 'Best source', 'supplement-compare' ); ?></th>
				<th><?php esc_html_e( 'Typed in', 'supplement-compare' ); ?></th>
				<th><?php esc_html_e( 'Approved', 'supplement-compare' ); ?></th>
				<th><?php esc_html_e( 'All', 'supplement-compare' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( self::SOURCE_LABELS as $key => $label ) : ?>
					<?php
					$op = $sources[ $key ]['operator_total'] ?? 0;
					$ap = $sources[ $key ]['approved_derived'] ?? 0;
					?>
					<tr>
						<td><?php echo esc_html( $label ); ?></td>
						<td><?php echo (int) $op; ?></td>
						<td><?php echo (int) $ap; ?></td>
						<td><?php echo esc_html( $pct( $op + $ap ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Priority when found in several places: title, then attribute, then pack notation, then description.', 'supplement-compare' ); ?></p>

		<h2><?php esc_html_e( 'Today\'s automatic total (strength × servings)', 'supplement-compare' ); ?></h2>
		<table class="widefat striped" style="max-width:50em">
			<tbody>
				<tr><td><?php esc_html_e( 'Matches the confirmed total', 'supplement-compare' ); ?></td><td><?php echo esc_html( $pct( $status['ok'] ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Wrong', 'supplement-compare' ); ?></td><td><?php echo esc_html( $pct( $status['wrong'] ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Missing (strength or servings not found)', 'supplement-compare' ); ?></td><td><?php echo esc_html( $pct( $status['missing'] ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Strength picked up was already the container total (then multiplied by servings)', 'supplement-compare' ); ?></td><td><?php echo esc_html( $pct( $s_total ) ); ?></td></tr>
			</tbody>
		</table>

		<?php
		$fill = array();
		foreach ( $rows as $r ) {
			$k          = $r['fill_status'] === 'none' ? 'none' : $r['fill_source'] . '|' . $r['fill_status'];
			$fill[ $k ] = ( $fill[ $k ] ?? 0 ) + 1;
		}
		$fill_right = 0;
		$fill_wrong = 0;
		foreach ( $rows as $r ) {
			$fill_right += $r['fill_status'] === 'right' ? 1 : 0;
			$fill_wrong += $r['fill_status'] === 'wrong' ? 1 : 0;
		}
		?>
		<h2><?php esc_html_e( 'Automatic total suggestion (what "Fill missing totals" would do)', 'supplement-compare' ); ?></h2>
		<table class="widefat striped" style="max-width:50em">
			<tbody>
				<tr><td><?php esc_html_e( 'Would suggest your total', 'supplement-compare' ); ?></td><td><?php echo esc_html( $pct( $fill_right ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Would suggest a different total', 'supplement-compare' ); ?></td><td><?php echo esc_html( $pct( $fill_wrong ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Would leave it blank', 'supplement-compare' ); ?></td><td><?php echo esc_html( $pct( $fill['none'] ?? 0 ) ); ?></td></tr>
				<?php
				$fill_sources = array(
					Supcomp_Total_Filler::SOURCE_VARIANT, Supcomp_Total_Filler::SOURCE_TITLE, Supcomp_Total_Filler::SOURCE_ATTRIBUTE,
					Supcomp_Total_Filler::SOURCE_PACK_COUNT, Supcomp_Total_Filler::SOURCE_PACK_STATED,
					Supcomp_Total_Filler::SOURCE_BLEND_SUM, Supcomp_Total_Filler::SOURCE_BLEND_STATED,
					Supcomp_Total_Filler::SOURCE_BLEND_SUM . '_pack', Supcomp_Total_Filler::SOURCE_BLEND_STATED . '_pack',
				);
				foreach ( $fill_sources as $src ) :
					?>
					<tr>
						<td style="padding-left:2em"><?php echo esc_html( ucfirst( Supcomp_Total_Filler::source_label( $src ) ) ); ?></td>
						<td><?php echo esc_html( sprintf( __( '%1$d right, %2$d different', 'supplement-compare' ), $fill[ $src . '|right' ] ?? 0, $fill[ $src . '|wrong' ] ?? 0 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $attr_key ) : ?>
			<h2><?php esc_html_e( 'Attribute names holding the total', 'supplement-compare' ); ?></h2>
			<table class="widefat striped" style="max-width:50em">
				<thead><tr><th><?php esc_html_e( 'Attribute', 'supplement-compare' ); ?></th><th><?php esc_html_e( 'Offers', 'supplement-compare' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( array_slice( $attr_key, 0, 20, true ) as $k => $c ) : ?>
						<tr><td><code><?php echo esc_html( $k ); ?></code></td><td><?php echo (int) $c; ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2><?php esc_html_e( 'By merchant', 'supplement-compare' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Merchant', 'supplement-compare' ); ?></th>
				<th><?php esc_html_e( 'Platform', 'supplement-compare' ); ?></th>
				<th><?php esc_html_e( 'Offers', 'supplement-compare' ); ?></th>
				<th><?php esc_html_e( 'Auto OK', 'supplement-compare' ); ?></th>
				<th><?php esc_html_e( 'Auto wrong', 'supplement-compare' ); ?></th>
				<th><?php esc_html_e( 'Auto missing', 'supplement-compare' ); ?></th>
				<?php foreach ( self::SOURCE_LABELS as $label ) : ?>
					<th><?php echo esc_html( $label ); ?></th>
				<?php endforeach; ?>
			</tr></thead>
			<tbody>
				<?php foreach ( $merchant as $name => $mrow ) : ?>
					<tr>
						<td><?php echo esc_html( $name ); ?></td>
						<td><?php echo esc_html( $mrow['platform'] ); ?></td>
						<td><?php echo (int) $mrow['n']; ?></td>
						<td><?php echo (int) $mrow['ok']; ?></td>
						<td><?php echo (int) $mrow['wrong']; ?></td>
						<td><?php echo (int) $mrow['missing']; ?></td>
						<?php foreach ( array_keys( self::SOURCE_LABELS ) as $key ) : ?>
							<td><?php echo (int) ( $mrow['sources'][ $key ] ?? 0 ); ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$problems = array_values( array_filter( $rows, static function ( $r ) {
			return $r['current_status'] !== 'ok';
		} ) );
		?>
		<h2><?php echo esc_html( sprintf( __( 'Offers where the automatic total is wrong or missing (%d)', 'supplement-compare' ), count( $problems ) ) ); ?></h2>
		<?php if ( count( $problems ) > self::MAX_LISTED ) : ?>
			<p class="description"><?php echo esc_html( sprintf( __( 'Showing the first %d. Download the CSV for all of them.', 'supplement-compare' ), self::MAX_LISTED ) ); ?></p>
		<?php endif; ?>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Offer', 'supplement-compare' ); ?></th>
				<th><?php esc_html_e( 'Merchant', 'supplement-compare' ); ?></th>
				<th><?php esc_html_e( 'Confirmed', 'supplement-compare' ); ?></th>
				<th><?php esc_html_e( 'Automatic', 'supplement-compare' ); ?></th>
				<th><?php esc_html_e( 'Found in', 'supplement-compare' ); ?></th>
				<th style="width:35%"><?php esc_html_e( 'Snippet', 'supplement-compare' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( array_slice( $problems, 0, self::MAX_LISTED ) as $r ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( self::edit_url( $r ) ); ?>"><?php echo esc_html( $r['product_title'] ); ?></a>
							<?php if ( $r['variant_title'] !== '' ) : ?>
								<br><span class="description"><?php echo esc_html( $r['variant_title'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $r['merchant'] ); ?></td>
						<td><?php echo esc_html( self::num( $r['truth'] ) . ' ' . $r['truth_unit'] ); ?></td>
						<td><?php echo esc_html( self::auto_label( $r ) ); ?></td>
						<td>
							<?php echo esc_html( self::SOURCE_LABELS[ $r['best_source'] ] ); ?>
							<?php if ( $r['attribute_key'] !== '' ) : ?>
								<br><code><?php echo esc_html( $r['attribute_key'] ); ?></code>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $r['snippet'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function handle_csv() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'supplement-compare' ) );
		}
		check_admin_referer( self::NONCE_CSV );

		$data = self::collect();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="supcomp-total-active-diagnostic-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out     = fopen( 'php://output', 'w' );
		$columns = array(
			'offer_id', 'visibility', 'merchant', 'platform', 'product_title', 'variant_title',
			'truth', 'truth_unit', 'truth_source', 'has_raw',
			'auto_strength', 'auto_unit', 'auto_servings', 'current_total', 'current_status', 'strength_is_total',
			'best_source', 'found_in', 'attribute_key', 'context_says_total', 'snippet',
			'fill_total', 'fill_unit', 'fill_source', 'fill_status',
		);
		fputcsv( $out, $columns );
		foreach ( $data['rows'] as $r ) {
			$line = array();
			foreach ( $columns as $c ) {
				$v = $r[ $c ];
				if ( is_bool( $v ) ) {
					$v = $v ? '1' : '0';
				} elseif ( is_array( $v ) ) {
					$v = implode( '|', $v );
				}
				$line[] = self::csv_safe( (string) $v );
			}
			fputcsv( $out, $line );
		}
		if ( ! $data['complete'] ) {
			fputcsv( $out, array( '# partial run: time or size limit reached' ) );
		}
		fclose( $out );
		exit;
	}

	// ---------- helpers ----------

	/** Neutralize spreadsheet formula injection in exported text cells. */
	private static function csv_safe( $v ) {
		return ( $v !== '' && strpbrk( $v[0], '=+-@' ) !== false && ! is_numeric( $v ) ) ? "'" . $v : $v;
	}

	private static function edit_url( array $r ) {
		$page = in_array( $r['visibility'], array( 'pending', 'needs_review' ), true ) ? 'supcomp-pending' : 'supcomp-active';
		return add_query_arg( array( 'page' => $page, 'action' => 'edit', 'id' => $r['offer_id'] ), admin_url( 'admin.php' ) );
	}

	private static function auto_label( array $r ) {
		if ( $r['current_status'] === 'missing' ) {
			$parts = array();
			$parts[] = $r['auto_strength'] !== null ? self::num( $r['auto_strength'] ) . ' ' . $r['auto_unit'] : __( 'no strength', 'supplement-compare' );
			$parts[] = $r['auto_servings'] !== null ? sprintf( __( '%d servings', 'supplement-compare' ), $r['auto_servings'] ) : __( 'no servings', 'supplement-compare' );
			return implode( ', ', $parts );
		}
		$label = sprintf(
			'%s %s × %d = %s %s',
			self::num( $r['auto_strength'] ),
			$r['auto_unit'],
			(int) $r['auto_servings'],
			self::num( $r['current_total'] ),
			$r['truth_unit']
		);
		if ( $r['strength_is_total'] ) {
			$label .= ' — ' . __( 'strength was already the total', 'supplement-compare' );
		}
		return $label;
	}

	private static function num( $v ) {
		if ( $v === null || $v === '' ) {
			return '';
		}
		$s = number_format( (float) $v, 4, '.', '' );
		return rtrim( rtrim( $s, '0' ), '.' );
	}
}
