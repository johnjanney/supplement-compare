<?php
/**
 * Shared offer detail / edit form. Used by both the pending queue and the
 * active offers screens. Renders the side-by-side raw-vs-normalized view
 * plus the operator-curated fields, and handles the POST that comes back
 * from any of the action buttons (Save, Approve, Reject, Pause, Defer).
 *
 * The Phase 6 deliverable — "operator can move offers from pending →
 * active in under 10 seconds for clean cases" — assumes the operator can
 * click Approve directly from the list. The detail form is for the cases
 * that need a closer look or a manual fix-up.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Offer_Form {

	const NONCE_SAVE = 'supcomp_save_offer';

	public static function register_hooks() {
		add_action( 'admin_post_supcomp_save_offer', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Render the edit form. The caller (a screen class) passes the offer
	 * id and a return URL to redirect back to after Save / Approve / etc.
	 */
	public static function render( $offer_id, $return_url ) {
		$offer = Supcomp_Offers_Repo::get_with_joins( $offer_id );
		if ( ! $offer ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Offer not found', 'supplement-compare' ) . '</h1></div>';
			return;
		}

		$raw          = Supcomp_Offers_Repo::latest_raw_for( $offer );
		$ingredients  = Supcomp_Ingredients_Repo::active_for_select();
		$canonicals   = Supcomp_Canonical_Products_Repo::for_picker();
		$certs        = Supcomp_Offers_Repo::decode_certifications( $offer->certifications_json );
		$certs_text   = implode( ', ', $certs );

		?>
		<div class="wrap">
			<h1>
				<?php echo esc_html( $offer->product_title ); ?>
				<?php if ( $offer->variant_title ) : ?>
					<span class="title-count"><?php echo esc_html( '— ' . $offer->variant_title ); ?></span>
				<?php endif; ?>
			</h1>
			<p>
				<a href="<?php echo esc_url( $return_url ); ?>">&laquo; <?php esc_html_e( 'Back to list', 'supplement-compare' ); ?></a>
				&nbsp;|&nbsp;
				<?php echo esc_html( sprintf( __( 'Merchant: %s', 'supplement-compare' ), $offer->merchant_name ? $offer->merchant_name : '(missing)' ) ); ?>
				&nbsp;|&nbsp;
				<?php echo esc_html( sprintf( __( 'Visibility: %s', 'supplement-compare' ), $offer->visibility_status ) ); ?>
				&nbsp;|&nbsp;
				<?php self::render_confidence_badge( $offer->match_confidence ); ?>
			</p>

			<?php self::render_notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="supcomp_save_offer">
				<input type="hidden" name="id" value="<?php echo (int) $offer->id; ?>">
				<input type="hidden" name="return" value="<?php echo esc_attr( $return_url ); ?>">
				<?php wp_nonce_field( self::NONCE_SAVE ); ?>

				<div class="supcomp-offer-form" style="display:grid;grid-template-columns:1fr 1fr;gap:1.5em">

					<div>
						<h2><?php esc_html_e( 'Source (read-only)', 'supplement-compare' ); ?></h2>
						<table class="form-table" role="presentation">
							<?php
							self::row( __( 'Brand', 'supplement-compare' ), $offer->brand );
							self::row( __( 'SKU', 'supplement-compare' ), $offer->sku );
							self::row( __( 'Barcode (UPC/GTIN)', 'supplement-compare' ), $offer->barcode );
							self::row( __( 'Product URL', 'supplement-compare' ), $offer->source_product_url, true );
							if ( $offer->source_variant_url ) {
								self::row( __( 'Variant URL', 'supplement-compare' ), $offer->source_variant_url, true );
							}
							self::row( __( 'Current price', 'supplement-compare' ), self::format_price( $offer->current_price, $offer->currency ) );
							self::row( __( 'Regular price', 'supplement-compare' ), self::format_price( $offer->regular_price, $offer->currency ) );
							self::row( __( 'Sale price', 'supplement-compare' ), self::format_price( $offer->sale_price, $offer->currency ) );
							self::row( __( 'Stock', 'supplement-compare' ), $offer->stock_status );
							self::row( __( 'Last synced', 'supplement-compare' ), $offer->last_synced_at );
							?>
						</table>

						<p style="margin-top:1em">
							<a href="<?php echo esc_url( home_url( '/out/' . (int) $offer->id ) ); ?>" target="_blank" rel="noopener" class="button">
								<?php esc_html_e( 'Test Buy Now (/out/N)', 'supplement-compare' ); ?>
							</a>
							<span class="description"><?php esc_html_e( 'Opens the live redirect in a new tab — logs a click and 302s to the merchant via the affiliate template.', 'supplement-compare' ); ?></span>
						</p>

						<?php if ( $raw && ! empty( $raw->raw_csv_row_json ) ) : ?>
							<details>
								<summary><?php esc_html_e( 'Raw CSV row', 'supplement-compare' ); ?></summary>
								<pre style="background:#f6f7f7;border:1px solid #ddd;padding:0.5em;max-height:24em;overflow:auto;font-size:11px"><?php
									echo esc_html( self::pretty_json( $raw->raw_csv_row_json ) );
								?></pre>
							</details>
						<?php endif; ?>
					</div>

					<div>
						<h2><?php esc_html_e( 'Normalized (editable)', 'supplement-compare' ); ?></h2>
						<table class="form-table" role="presentation">

							<tr>
								<th><label for="supcomp-canonical-id"><?php esc_html_e( 'Canonical product', 'supplement-compare' ); ?></label></th>
								<td>
									<?php self::render_canonical_picker( $canonicals, (int) $offer->canonical_product_id ); ?>
									<p class="description"><?php esc_html_e( 'Operator-confirmed canonical product for this offer. When set, the canonical\'s ingredient/form/strength are authoritative.', 'supplement-compare' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><label for="supcomp-ingredient-id"><?php esc_html_e( 'Ingredient', 'supplement-compare' ); ?></label></th>
								<td>
									<select id="supcomp-ingredient-id" name="ingredient_id">
										<option value=""><?php esc_html_e( '— Unset —', 'supplement-compare' ); ?></option>
										<?php foreach ( $ingredients as $ing ) : ?>
											<option value="<?php echo (int) $ing->id; ?>" <?php selected( (int) $offer->ingredient_id, (int) $ing->id ); ?>><?php echo esc_html( $ing->name ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>

							<tr>
								<th><label for="supcomp-form"><?php esc_html_e( 'Form', 'supplement-compare' ); ?></label></th>
								<td>
									<select id="supcomp-form" name="ingredient_form">
										<option value=""><?php esc_html_e( '— Unset —', 'supplement-compare' ); ?></option>
										<?php foreach ( Supcomp_Installer::PRODUCT_FORMS as $f ) : ?>
											<option value="<?php echo esc_attr( $f ); ?>" <?php selected( $offer->ingredient_form, $f ); ?>><?php echo esc_html( $f ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>

							<tr>
								<th><label for="supcomp-strength"><?php esc_html_e( 'Active mass / serving', 'supplement-compare' ); ?></label></th>
								<td>
									<input type="number" id="supcomp-strength" name="strength_per_serving" value="<?php echo esc_attr( self::trim_decimal( $offer->strength_per_serving ) ); ?>" step="0.0001" min="0" class="regular-text" style="width:8em">
									<select id="supcomp-strength-unit" name="strength_unit">
										<option value=""><?php esc_html_e( '— unit —', 'supplement-compare' ); ?></option>
										<?php foreach ( Supcomp_Installer::INGREDIENT_UNITS as $u ) : ?>
											<option value="<?php echo esc_attr( $u ); ?>" <?php selected( $offer->strength_unit, $u ); ?>><?php echo esc_html( $u ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>

							<tr>
								<th><label for="supcomp-servings"><?php esc_html_e( 'Servings / container', 'supplement-compare' ); ?></label></th>
								<td>
									<input type="number" id="supcomp-servings" name="servings_per_container" value="<?php echo esc_attr( $offer->servings_per_container !== null ? (int) $offer->servings_per_container : '' ); ?>" min="1" step="1" class="small-text">
								</td>
							</tr>

							<tr>
								<th><label for="supcomp-total-active"><?php esc_html_e( 'Total active / container', 'supplement-compare' ); ?></label></th>
								<td>
									<input type="number" id="supcomp-total-active" name="total_active_per_container" value="<?php echo esc_attr( self::trim_decimal( self::seed_total_active( $offer ) ) ); ?>" step="0.0001" min="0" class="regular-text" style="width:10em">
									<span id="supcomp-total-active-unit" class="description" style="margin-left:.4em"><?php echo esc_html( $offer->strength_unit ); ?></span>
									<p class="description"><?php esc_html_e( 'Sum across all servings. Fill any two of {active mass/serving, servings/container, total active} and the third will be computed on save. If all three are filled, active-mass/serving and servings/container take precedence.', 'supplement-compare' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><label for="supcomp-stdpct"><?php esc_html_e( 'Standardization %', 'supplement-compare' ); ?></label></th>
								<td>
									<input type="number" id="supcomp-stdpct" name="standardization_percentage" value="<?php echo esc_attr( self::trim_decimal( $offer->standardization_percentage ) ); ?>" step="0.01" min="0" max="100" class="small-text"> %
								</td>
							</tr>

							<tr>
								<th><?php esc_html_e( 'Derived', 'supplement-compare' ); ?></th>
								<td>
									<p style="margin:0">
										<strong><?php esc_html_e( 'Total strength:', 'supplement-compare' ); ?></strong>
										<?php echo esc_html( self::trim_decimal( $offer->total_strength ) ?: '—' ); ?>
										&nbsp;&nbsp;
										<strong><?php esc_html_e( 'Active / serving:', 'supplement-compare' ); ?></strong>
										<?php echo esc_html( self::trim_decimal( $offer->active_compound_per_serving ) ?: '—' ); ?>
										&nbsp;&nbsp;
										<strong><?php esc_html_e( 'Cost / active unit:', 'supplement-compare' ); ?></strong>
										<?php echo esc_html( self::trim_decimal( $offer->cost_per_active_unit ) ?: '—' ); ?>
									</p>
									<p class="description"><?php esc_html_e( 'Recomputed on save.', 'supplement-compare' ); ?></p>
								</td>
							</tr>

							<tr>
								<th colspan="2"><h3 style="margin:1em 0 0"><?php esc_html_e( 'Trust signals', 'supplement-compare' ); ?></h3></th>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Third-party tested', 'supplement-compare' ); ?></th>
								<td>
									<label><input type="checkbox" name="third_party_tested" value="1" <?php checked( (int) $offer->third_party_tested, 1 ); ?>> <?php esc_html_e( 'Brand publishes independent lab results', 'supplement-compare' ); ?></label>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'COA available', 'supplement-compare' ); ?></th>
								<td>
									<label><input type="checkbox" name="coa_available" value="1" <?php checked( (int) $offer->coa_available, 1 ); ?>> <?php esc_html_e( 'Certificate of Analysis is publicly accessible', 'supplement-compare' ); ?></label>
								</td>
							</tr>
							<tr>
								<th><label for="supcomp-coa-url"><?php esc_html_e( 'COA URL', 'supplement-compare' ); ?></label></th>
								<td>
									<input type="url" id="supcomp-coa-url" name="coa_url" value="<?php echo esc_attr( $offer->coa_url ); ?>" class="regular-text" placeholder="https://merchant.com/coa.pdf">
								</td>
							</tr>
							<tr>
								<th><label for="supcomp-certs"><?php esc_html_e( 'Certifications', 'supplement-compare' ); ?></label></th>
								<td>
									<input type="text" id="supcomp-certs" name="certifications" value="<?php echo esc_attr( $certs_text ); ?>" class="regular-text" placeholder="NSF, USP, Informed_Sport, GMP">
									<p class="description"><?php esc_html_e( 'Comma-separated. Examples: NSF, USP, Informed_Sport, NSF_Certified_for_Sport, GMP.', 'supplement-compare' ); ?></p>
								</td>
							</tr>

							<tr>
								<th><label for="supcomp-notes"><?php esc_html_e( 'Operator notes', 'supplement-compare' ); ?></label></th>
								<td>
									<textarea id="supcomp-notes" name="operator_notes" rows="3" class="large-text"><?php echo esc_textarea( $offer->operator_notes ); ?></textarea>
								</td>
							</tr>

						</table>
					</div>
				</div>

				<script>
				(function () {
					var strengthEl = document.getElementById('supcomp-strength');
					var servingsEl = document.getElementById('supcomp-servings');
					var totalEl    = document.getElementById('supcomp-total-active');
					var unitSel    = document.getElementById('supcomp-strength-unit');
					var unitLbl    = document.getElementById('supcomp-total-active-unit');
					if ( ! strengthEl || ! servingsEl || ! totalEl ) { return; }

					function num( el ) {
						var v = parseFloat( el.value );
						return isFinite( v ) && v > 0 ? v : null;
					}
					function fmt( n ) {
						if ( n === null || ! isFinite( n ) ) { return ''; }
						var s = n.toFixed( 4 );
						if ( s.indexOf( '.' ) !== -1 ) { s = s.replace( /0+$/, '' ).replace( /\.$/, '' ); }
						return s;
					}
					function recalc( source ) {
						var s = num( strengthEl ), n = num( servingsEl ), t = num( totalEl );
						if ( source === 'strength' && s !== null && n !== null ) {
							totalEl.value = fmt( s * n );
						} else if ( source === 'servings' && s !== null && n !== null ) {
							totalEl.value = fmt( s * n );
						} else if ( source === 'total' && t !== null && n !== null ) {
							strengthEl.value = fmt( t / n );
						} else if ( source === 'total' && t !== null && s !== null ) {
							servingsEl.value = String( Math.max( 1, Math.round( t / s ) ) );
						} else if ( s !== null && n !== null && t === null ) {
							totalEl.value = fmt( s * n );
						} else if ( t !== null && n !== null && s === null ) {
							strengthEl.value = fmt( t / n );
						} else if ( t !== null && s !== null && n === null ) {
							servingsEl.value = String( Math.max( 1, Math.round( t / s ) ) );
						}
					}
					function syncUnit() {
						if ( unitSel && unitLbl ) { unitLbl.textContent = unitSel.value || ''; }
					}
					strengthEl.addEventListener( 'input', function () { recalc( 'strength' ); } );
					servingsEl.addEventListener( 'input', function () { recalc( 'servings' ); } );
					totalEl.addEventListener( 'input', function () { recalc( 'total' ); } );
					if ( unitSel ) { unitSel.addEventListener( 'change', syncUnit ); }
				})();
				</script>

				<p class="submit" style="margin-top:1em;border-top:1px solid #ddd;padding-top:1em">
					<button type="submit" name="post_action" value="save" class="button button-primary"><?php esc_html_e( 'Save', 'supplement-compare' ); ?></button>
					<?php if ( $offer->visibility_status !== 'active' ) : ?>
						<button type="submit" name="post_action" value="approve" class="button button-primary"><?php esc_html_e( 'Save & Approve', 'supplement-compare' ); ?></button>
					<?php endif; ?>
					<?php if ( $offer->visibility_status !== 'paused' ) : ?>
						<button type="submit" name="post_action" value="pause" class="button"><?php esc_html_e( 'Save & Pause', 'supplement-compare' ); ?></button>
					<?php endif; ?>
					<?php if ( $offer->visibility_status !== 'rejected' ) : ?>
						<button type="submit" name="post_action" value="reject" class="button"><?php esc_html_e( 'Save & Reject', 'supplement-compare' ); ?></button>
					<?php endif; ?>
					<?php if ( $offer->visibility_status !== 'needs_review' ) : ?>
						<button type="submit" name="post_action" value="defer" class="button"><?php esc_html_e( 'Save & Defer', 'supplement-compare' ); ?></button>
					<?php endif; ?>
					<a href="<?php echo esc_url( $return_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'supplement-compare' ); ?></a>
					<?php if ( Supcomp_Deletion_Service::offer_is_deletable( $offer ) ) : ?>
						<a href="<?php echo esc_url( Supcomp_Deletion_Admin::confirm_url( 'offer', (int) $offer->id, $return_url ) ); ?>" class="button" style="background:#fcecec;color:#a00;border-color:#a00;margin-left:1em"><?php esc_html_e( 'Delete permanently…', 'supplement-compare' ); ?></a>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}

	public static function handle_save() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'supplement-compare' ) );
		}
		check_admin_referer( self::NONCE_SAVE );

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$return = isset( $_POST['return'] ) ? esc_url_raw( wp_unslash( $_POST['return'] ) ) : admin_url( 'admin.php?page=supcomp-pending' );
		$post_action = isset( $_POST['post_action'] ) ? sanitize_key( wp_unslash( $_POST['post_action'] ) ) : 'save';

		if ( $id <= 0 ) {
			wp_safe_redirect( add_query_arg( 'supcomp_notice', 'error', $return ) );
			exit;
		}

		$data = wp_unslash( $_POST );
		unset( $data['action'], $data['_wpnonce'], $data['_wp_http_referer'], $data['id'], $data['return'], $data['post_action'] );

		// Unchecked checkboxes don't appear in $_POST.
		if ( ! isset( $data['third_party_tested'] ) ) {
			$data['third_party_tested'] = 0;
		}
		if ( ! isset( $data['coa_available'] ) ) {
			$data['coa_available'] = 0;
		}

		self::resolve_total_active_input( $data );

		Supcomp_Offers_Repo::manual_update( $id, $data );

		// Recompute derivations off the fresh state.
		$offer = Supcomp_Offers_Repo::get( $id );
		if ( $offer ) {
			$ingredient = $offer->ingredient_id ? Supcomp_Ingredients_Repo::get( (int) $offer->ingredient_id ) : null;
			$derived    = Supcomp_Offer_Derivations::compute( $offer, $ingredient );
			Supcomp_Offers_Repo::apply_derivations( $id, $derived );
		}

		do_action( 'supcomp_data_changed', array( 'source' => 'offer_form', 'offer_id' => $id ) );

		// Visibility flip if the operator clicked one of the workflow buttons.
		$visibility_map = array(
			'approve' => 'active',
			'reject'  => 'rejected',
			'pause'   => 'paused',
			'defer'   => 'needs_review',
		);
		if ( isset( $visibility_map[ $post_action ] ) ) {
			Supcomp_Offers_Repo::set_visibility( $id, $visibility_map[ $post_action ] );
			$notice = $post_action . 'd'; // approved / rejected / paused / deferred
			if ( $post_action === 'defer' ) {
				$notice = 'deferred';
			}
			wp_safe_redirect( add_query_arg( 'supcomp_notice', $notice, $return ) );
			exit;
		}

		// Plain save — stay on edit form so operator can keep tweaking.
		$stay = add_query_arg(
			array(
				'action'         => 'edit',
				'id'             => $id,
				'supcomp_notice' => 'saved',
			),
			parse_url( $return, PHP_URL_PATH ) ? $return : admin_url( 'admin.php' )
		);
		// Simpler: use the form's return URL but switch action=edit&id=N
		$base = isset( $_POST['return'] ) ? esc_url_raw( wp_unslash( $_POST['return'] ) ) : admin_url( 'admin.php?page=supcomp-pending' );
		$stay = add_query_arg( array( 'action' => 'edit', 'id' => $id, 'supcomp_notice' => 'saved' ), $base );
		wp_safe_redirect( $stay );
		exit;
	}

	// ---------- helpers ----------

	private static function render_canonical_picker( $canonicals, $current_id ) {
		?>
		<select id="supcomp-canonical-id" name="canonical_product_id" style="min-width:30em">
			<option value=""><?php esc_html_e( '— No canonical match (operator must pick) —', 'supplement-compare' ); ?></option>
			<?php
			$current_group = null;
			foreach ( $canonicals as $cp ) {
				$group = $cp->ingredient_name ? $cp->ingredient_name : __( 'Unassigned', 'supplement-compare' );
				if ( $group !== $current_group ) {
					if ( $current_group !== null ) {
						echo '</optgroup>';
					}
					echo '<optgroup label="' . esc_attr( $group ) . '">';
					$current_group = $group;
				}
				$has_strength = $cp->strength_per_serving !== null && $cp->strength_per_serving !== '';
				$label        = $has_strength
					? sprintf(
						'%s — %s %s %s',
						$cp->display_name,
						self::trim_decimal( $cp->strength_per_serving ),
						$cp->ingredient_unit ? $cp->ingredient_unit : '',
						$cp->ingredient_form
					)
					: sprintf( '%s — %s', $cp->display_name, $cp->ingredient_form );
				$selected = selected( $current_id, (int) $cp->id, false );
				printf(
					'<option value="%d" %s>%s</option>',
					(int) $cp->id,
					$selected,
					esc_html( $label )
				);
			}
			if ( $current_group !== null ) {
				echo '</optgroup>';
			}
			?>
		</select>
		<?php
	}

	public static function render_confidence_badge( $confidence ) {
		if ( $confidence === null || $confidence === '' ) {
			echo '<span class="supcomp-conf-badge" style="background:#eee;color:#666;padding:2px 6px;border-radius:3px;font-size:11px">'
				. esc_html__( 'no match', 'supplement-compare' ) . '</span>';
			return;
		}
		$c = (float) $confidence;
		if ( $c >= 0.95 ) {
			$bg = '#d4edda'; $fg = '#155724';
		} elseif ( $c >= 0.85 ) {
			$bg = '#cce5ff'; $fg = '#004085';
		} elseif ( $c >= 0.65 ) {
			$bg = '#fff3cd'; $fg = '#856404';
		} else {
			$bg = '#eee'; $fg = '#666';
		}
		printf(
			'<span class="supcomp-conf-badge" style="background:%s;color:%s;padding:2px 6px;border-radius:3px;font-size:11px">%s %s</span>',
			esc_attr( $bg ),
			esc_attr( $fg ),
			esc_html__( 'confidence', 'supplement-compare' ),
			esc_html( number_format( $c, 2 ) )
		);
	}

	private static function row( $label, $value, $is_url = false ) {
		?>
		<tr>
			<th style="width:35%"><?php echo esc_html( $label ); ?></th>
			<td>
				<?php if ( $value === null || $value === '' ) : ?>
					<span style="color:#888">—</span>
				<?php elseif ( $is_url ) : ?>
					<a href="<?php echo esc_url( $value ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $value ); ?></code></a>
				<?php else : ?>
					<?php echo esc_html( (string) $value ); ?>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function format_price( $value, $currency ) {
		if ( $value === null || $value === '' ) {
			return '';
		}
		return ( $currency ? $currency . ' ' : '' ) . number_format( (float) $value, 2 );
	}

	/**
	 * Best-effort starting value for the Total Active input field. Prefers
	 * the stored derived `total_strength` so the operator sees the same
	 * number the comparison table will. Falls back to strength × servings
	 * when total_strength hasn't been computed yet (fresh insert before
	 * derivations run).
	 */
	private static function seed_total_active( $offer ) {
		if ( $offer->total_strength !== null && $offer->total_strength !== '' && (float) $offer->total_strength > 0 ) {
			return $offer->total_strength;
		}
		if ( $offer->strength_per_serving !== null && $offer->servings_per_container !== null ) {
			$s = (float) $offer->strength_per_serving;
			$n = (int) $offer->servings_per_container;
			if ( $s > 0 && $n > 0 ) {
				return $s * $n;
			}
		}
		return '';
	}

	/**
	 * If the operator filled the Total Active phantom input, fold it into
	 * the canonical fields (`strength_per_serving` / `servings_per_container`)
	 * so the existing sanitizer + derivations pipeline can take over. If all
	 * three are filled, strength + servings win and total is dropped — they
	 * are the stored columns and total is always derived per PROJECTBRIEF §6.
	 *
	 * Mutates $data in place and strips the phantom key.
	 */
	private static function resolve_total_active_input( array &$data ) {
		$total = isset( $data['total_active_per_container'] ) ? trim( (string) $data['total_active_per_container'] ) : '';
		unset( $data['total_active_per_container'] );
		if ( $total === '' ) {
			return;
		}
		$total_f = (float) $total;
		if ( $total_f <= 0 ) {
			return;
		}

		$strength = isset( $data['strength_per_serving'] ) ? trim( (string) $data['strength_per_serving'] ) : '';
		$servings = isset( $data['servings_per_container'] ) ? trim( (string) $data['servings_per_container'] ) : '';

		if ( $strength === '' && $servings !== '' && (int) $servings > 0 ) {
			$data['strength_per_serving'] = $total_f / (int) $servings;
		} elseif ( $servings === '' && $strength !== '' && (float) $strength > 0 ) {
			$data['servings_per_container'] = max( 1, (int) round( $total_f / (float) $strength ) );
		}
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

	private static function pretty_json( $json ) {
		$decoded = json_decode( (string) $json, true );
		if ( $decoded === null ) {
			return (string) $json;
		}
		return wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	private static function render_notice() {
		if ( empty( $_GET['supcomp_notice'] ) ) {
			return;
		}
		$type = sanitize_key( wp_unslash( $_GET['supcomp_notice'] ) );
		$messages = array(
			'saved'    => array( 'success', __( 'Saved.', 'supplement-compare' ) ),
			'approved' => array( 'success', __( 'Approved. Offer is now active.', 'supplement-compare' ) ),
			'rejected' => array( 'success', __( 'Rejected.', 'supplement-compare' ) ),
			'paused'   => array( 'success', __( 'Paused.', 'supplement-compare' ) ),
			'deferred' => array( 'success', __( 'Deferred — moved to needs_review.', 'supplement-compare' ) ),
			'error'    => array( 'error',   __( 'Something went wrong.', 'supplement-compare' ) ),
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
