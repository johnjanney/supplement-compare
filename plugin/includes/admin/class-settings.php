<?php
/**
 * Settings page. Built on the WordPress Settings API, which provides nonces
 * and capability checks via `options.php` and `settings_fields()`.
 *
 * Stored options:
 *   supcomp_default_currency        — ISO 4217, defaults to USD
 *   supcomp_staleness_warn_hours    — soft threshold (offer visually downgraded)
 *   supcomp_staleness_hide_hours    — hard threshold (offer excluded from public JSON)
 *   supcomp_extract_stale_minutes   — how long an extractor run may sit "in flight"
 *                                     with no queued Action Scheduler job before the
 *                                     stale-run reaper fails it (default 30, range 5–1440)
 *   supcomp_price_move_window_days  — on the compare table, show a price-direction
 *                                     arrow + % change to the right of a merchant's
 *                                     price when that price last moved within this
 *                                     many days. 0 disables the indicator.
 *   supcomp_affiliate_disclosure    — disclosure text rendered on every comparison page
 *   supcomp_default_compare_view    — which compare-table column set loads by default
 *                                     ('cost_per_serving' | 'cost_per_active_unit')
 *   supcomp_multi_compare_view_enabled — whether visitors see the Cost / Serving vs
 *                                        Cost / Active Unit radio toggle on the
 *                                        detail view. When off, the default view
 *                                        above is the only view that renders.
 *   supcomp_filter_in_stock_enabled — whether the "In stock only" checkbox renders
 *   supcomp_filter_third_party_enabled — whether the "Third-party tested only" checkbox renders
 *   supcomp_filter_coa_enabled      — whether the "COA available only" checkbox renders
 *   supcomp_filter_search_enabled   — whether the search-ingredient input renders on the list view
 *   supcomp_filter_form_enabled     — whether the "All forms" select renders on the list view
 *   supcomp_filter_ingredient_enabled — whether the "All ingredients" select renders on the list view
 *   supcomp_filter_merchant_enabled — whether the "All merchants" select renders on the list view
 *   supcomp_filter_price_range_enabled — whether the min/max price inputs render on the list view
 *   supcomp_subhead_detail_enabled  — whether the canonical-product detail page renders the
 *                                     subhead under the title (ingredient · category · form · unit).
 *                                     Applies to both the JS-rendered shortcode detail view and
 *                                     the PHP-rendered /compare/{slug}/ landing page.
 *   supcomp_subhead_list_enabled    — whether each row in the list table shows the ingredient ·
 *                                     category line under the canonical product name.
 *
 * Note on two staleness thresholds: PROJECTBRIEF.md §1 Phase 1 mentions
 * "staleness threshold hours" in the singular, but §6 defines two distinct
 * thresholds (48h soft, 168h hard). Both are exposed here. Defaults match
 * OPEN_QUESTIONS.md Q-004; the operator's import cadence determines whether
 * to keep them or adjust.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Settings {

	const PAGE_SLUG    = 'supcomp-settings';
	const OPTION_GROUP = 'supcomp_settings';
	const SECTION_ID   = 'supcomp_general';

	public static function register() {
		// Changing the publish threshold reshapes the public universe, so the
		// static JSON export must regenerate. Live surfaces (/compare page,
		// sitemap) already read the option directly, but the exported file is
		// only rebuilt on supcomp_data_changed — fire it when the value changes.
		add_action( 'update_option_supcomp_min_active_offers_to_publish', array( __CLASS__, 'on_publish_threshold_changed' ), 10, 2 );

		register_setting(
			self::OPTION_GROUP,
			'supcomp_default_currency',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_currency' ),
				'default'           => 'USD',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'supcomp_staleness_warn_hours',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 48,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'supcomp_staleness_hide_hours',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 168,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'supcomp_min_active_offers_to_publish',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_min_active_offers' ),
				'default'           => 1,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'supcomp_extract_stale_minutes',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_stale_minutes' ),
				'default'           => 30,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'supcomp_price_move_window_days',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_price_move_window' ),
				'default'           => 30,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'supcomp_affiliate_disclosure',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'supcomp_default_compare_view',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_compare_view' ),
				'default'           => 'cost_per_active_unit',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'supcomp_multi_compare_view_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( __CLASS__, 'sanitize_bool' ),
				'default'           => true,
			)
		);

		foreach ( self::filter_toggle_options() as $option_name => $_label ) {
			register_setting(
				self::OPTION_GROUP,
				$option_name,
				array(
					'type'              => 'boolean',
					'sanitize_callback' => array( __CLASS__, 'sanitize_bool' ),
					'default'           => true,
				)
			);
		}

		foreach ( self::list_control_toggle_options() as $option_name => $_label ) {
			register_setting(
				self::OPTION_GROUP,
				$option_name,
				array(
					'type'              => 'boolean',
					'sanitize_callback' => array( __CLASS__, 'sanitize_bool' ),
					'default'           => true,
				)
			);
		}

		foreach ( self::subhead_toggle_options() as $option_name => $_label ) {
			register_setting(
				self::OPTION_GROUP,
				$option_name,
				array(
					'type'              => 'boolean',
					'sanitize_callback' => array( __CLASS__, 'sanitize_bool' ),
					'default'           => true,
				)
			);
		}

		add_settings_section(
			self::SECTION_ID,
			__( 'General', 'supplement-compare' ),
			array( __CLASS__, 'render_section_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'supcomp_default_currency',
			__( 'Default currency', 'supplement-compare' ),
			array( __CLASS__, 'render_currency_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID
		);

		add_settings_field(
			'supcomp_staleness_warn_hours',
			__( 'Staleness warning (hours)', 'supplement-compare' ),
			array( __CLASS__, 'render_warn_hours_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID
		);

		add_settings_field(
			'supcomp_staleness_hide_hours',
			__( 'Staleness hide threshold (hours)', 'supplement-compare' ),
			array( __CLASS__, 'render_hide_hours_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID
		);

		add_settings_field(
			'supcomp_min_active_offers_to_publish',
			__( 'Minimum active offers to publish', 'supplement-compare' ),
			array( __CLASS__, 'render_min_active_offers_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID
		);

		add_settings_field(
			'supcomp_extract_stale_minutes',
			__( 'Extractor: stale-run timeout (minutes)', 'supplement-compare' ),
			array( __CLASS__, 'render_stale_minutes_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID
		);

		add_settings_field(
			'supcomp_price_move_window_days',
			__( 'Price-direction indicator (days)', 'supplement-compare' ),
			array( __CLASS__, 'render_price_move_window_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID
		);

		add_settings_field(
			'supcomp_affiliate_disclosure',
			__( 'Affiliate disclosure text', 'supplement-compare' ),
			array( __CLASS__, 'render_disclosure_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID
		);

		add_settings_field(
			'supcomp_multi_compare_view_enabled',
			__( 'Multi compare-table view', 'supplement-compare' ),
			array( __CLASS__, 'render_multi_compare_view_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID
		);

		add_settings_field(
			'supcomp_default_compare_view',
			__( 'Default compare-table view', 'supplement-compare' ),
			array( __CLASS__, 'render_compare_view_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID
		);

		add_settings_field(
			'supcomp_filter_toggles',
			__( 'Filter checkboxes', 'supplement-compare' ),
			array( __CLASS__, 'render_filter_toggles_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID
		);

		add_settings_field(
			'supcomp_list_control_toggles',
			__( 'List view filter controls', 'supplement-compare' ),
			array( __CLASS__, 'render_list_control_toggles_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID
		);

		add_settings_field(
			'supcomp_subhead_toggles',
			__( 'Product subhead', 'supplement-compare' ),
			array( __CLASS__, 'render_subhead_toggles_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID
		);
	}

	public static function filter_toggle_options() {
		return array(
			'supcomp_filter_in_stock_enabled'    => __( 'In stock only', 'supplement-compare' ),
			'supcomp_filter_third_party_enabled' => __( 'Third-party tested only', 'supplement-compare' ),
			'supcomp_filter_coa_enabled'         => __( 'COA available only', 'supplement-compare' ),
		);
	}

	public static function list_control_toggle_options() {
		return array(
			'supcomp_filter_search_enabled'     => __( 'Search ingredient (text input)', 'supplement-compare' ),
			'supcomp_filter_form_enabled'       => __( 'All forms (dropdown)', 'supplement-compare' ),
			'supcomp_filter_ingredient_enabled' => __( 'All ingredients (dropdown)', 'supplement-compare' ),
			'supcomp_filter_merchant_enabled'   => __( 'All merchants (dropdown)', 'supplement-compare' ),
			'supcomp_filter_price_range_enabled' => __( 'Price range (min/max)', 'supplement-compare' ),
		);
	}

	public static function subhead_toggle_options() {
		return array(
			'supcomp_subhead_detail_enabled' => __( 'Detail page subhead (ingredient · category · form · active unit, under the product title)', 'supplement-compare' ),
			'supcomp_subhead_list_enabled'   => __( 'List row subhead (ingredient · category, under each product name in the main table)', 'supplement-compare' ),
		);
	}

	public static function sanitize_bool( $value ) {
		return (bool) $value;
	}

	public static function sanitize_stale_minutes( $value ) {
		// Mirror Supcomp_Extractor_Reaper's band: min 5 min (avoid reaping a
		// just-started run), max 24h. Anything out of range clamps in.
		$v = absint( $value );
		if ( $v < 5 ) {
			$v = 5;
		}
		return min( $v, 1440 );
	}

	public static function on_publish_threshold_changed( $old_value, $value ) {
		if ( (int) $old_value === (int) $value ) {
			return;
		}
		do_action(
			'supcomp_data_changed',
			array( 'source' => 'publish_threshold', 'old' => (int) $old_value, 'new' => (int) $value )
		);
	}

	public static function sanitize_min_active_offers( $value ) {
		// At least 1 (0 would mean "publish canonicals with no offers", which the
		// export's NOT NULL / active filters already preclude — clamp to the
		// no-op default). Cap at 100 so a fat-fingered entry can't hide the
		// entire catalogue by accident.
		$v = absint( $value );
		if ( $v < 1 ) {
			$v = 1;
		}
		return min( $v, 100 );
	}

	public static function sanitize_price_move_window( $value ) {
		// 0 disables the indicator; cap at ~10 years so a fat-fingered entry
		// can't make "within the window" meaningless.
		return min( absint( $value ), 3650 );
	}

	public static function sanitize_currency( $value ) {
		$value = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $value ) );
		$value = substr( $value, 0, 3 );
		return $value !== '' ? $value : 'USD';
	}

	const COMPARE_VIEWS = array( 'cost_per_serving', 'cost_per_active_unit' );

	public static function sanitize_compare_view( $value ) {
		$value = sanitize_key( (string) $value );
		return in_array( $value, self::COMPARE_VIEWS, true ) ? $value : 'cost_per_active_unit';
	}

	public static function render_section_intro() {
		echo '<p>' . esc_html__( 'Site-wide defaults. Per-merchant overrides for currency are configured on the Merchants screen.', 'supplement-compare' ) . '</p>';
	}

	public static function render_currency_field() {
		$value = get_option( 'supcomp_default_currency', 'USD' );
		printf(
			'<input type="text" name="supcomp_default_currency" value="%s" maxlength="3" size="5" class="code" /> <p class="description">%s</p>',
			esc_attr( $value ),
			esc_html__( 'ISO 4217 code. Used when a CSV row omits its currency.', 'supplement-compare' )
		);
	}

	public static function render_warn_hours_field() {
		$value = (int) get_option( 'supcomp_staleness_warn_hours', 48 );
		printf(
			'<input type="number" min="1" name="supcomp_staleness_warn_hours" value="%d" class="small-text" /> <p class="description">%s</p>',
			$value,
			esc_html__( 'Offers older than this are visually downgraded on the public site ("data may be outdated"). Default 48 hours.', 'supplement-compare' )
		);
	}

	public static function render_hide_hours_field() {
		$value = (int) get_option( 'supcomp_staleness_hide_hours', 168 );
		printf(
			'<input type="number" min="1" name="supcomp_staleness_hide_hours" value="%d" class="small-text" /> <p class="description">%s</p>',
			$value,
			esc_html__( 'Offers older than this are excluded from the public JSON entirely. Default 168 hours (7 days).', 'supplement-compare' )
		);
	}

	public static function render_min_active_offers_field() {
		$value = (int) get_option( 'supcomp_min_active_offers_to_publish', 1 );
		printf(
			'<input type="number" min="1" name="supcomp_min_active_offers_to_publish" value="%d" class="small-text" /> <p class="description">%s</p>',
			$value,
			esc_html__( 'A canonical product is hidden from the public site (JSON export, /compare page, sitemap) until it has at least this many active, non-stale offers. It still accumulates offers while hidden — watch the "Active offers" column on the Canonical Products screen. Default 1 (publish as soon as it has any active offer).', 'supplement-compare' )
		);
	}

	public static function render_stale_minutes_field() {
		$value = (int) get_option( 'supcomp_extract_stale_minutes', 30 );
		printf(
			'<input type="number" min="5" max="1440" name="supcomp_extract_stale_minutes" value="%d" class="small-text" /> <p class="description">%s</p>',
			$value,
			esc_html__( 'How long an extractor run may sit "in flight" with no queued Action Scheduler job before the stale-run reaper marks it failed. A run that is still paginating always has a queued job and is never reaped, however long it takes — so this only catches dead runs (worker killed by a host timeout / out-of-memory). Default 30 minutes; range 5–1440.', 'supplement-compare' )
		);
	}

	public static function render_price_move_window_field() {
		$value = (int) get_option( 'supcomp_price_move_window_days', 30 );
		printf(
			'<input type="number" min="0" max="3650" name="supcomp_price_move_window_days" value="%d" class="small-text" /> <p class="description">%s</p>',
			$value,
			esc_html__( 'On the per-product compare table, show a coloured arrow and % change to the right of a merchant\'s price when that price last changed within this many days (green ▼ for a drop, red ▲ for a rise). Prices that last moved before the window show no indicator. Default 30 days. Set to 0 to turn it off entirely.', 'supplement-compare' )
		);
	}

	public static function render_disclosure_field() {
		$value = (string) get_option( 'supcomp_affiliate_disclosure', '' );
		printf(
			'<textarea name="supcomp_affiliate_disclosure" rows="5" cols="80" class="large-text">%s</textarea> <p class="description">%s</p>',
			esc_textarea( $value ),
			esc_html__( 'Rendered on every page that shows comparison content (Phase 9+). Keep the language factual; avoid therapeutic claims.', 'supplement-compare' )
		);
	}

	public static function render_filter_toggles_field() {
		?>
		<fieldset>
		<?php foreach ( self::filter_toggle_options() as $option_name => $label ) :
			$enabled = (bool) get_option( $option_name, true );
		?>
			<label style="display:block;margin-bottom:0.25em">
				<input type="hidden" name="<?php echo esc_attr( $option_name ); ?>" value="0">
				<input type="checkbox" name="<?php echo esc_attr( $option_name ); ?>" value="1" <?php checked( $enabled, true ); ?>>
				<?php echo esc_html( $label ); ?>
			</label>
		<?php endforeach; ?>
			<p class="description"><?php esc_html_e( 'Each enabled box appears on the public comparison filter bar (both list and detail views). Disable a box if your dataset doesn\'t populate that field — e.g. unchecking "COA available only" when no offers have COAs recorded keeps the filter bar uncluttered.', 'supplement-compare' ); ?></p>
		</fieldset>
		<?php
	}

	public static function render_list_control_toggles_field() {
		?>
		<fieldset>
		<?php foreach ( self::list_control_toggle_options() as $option_name => $label ) :
			$enabled = (bool) get_option( $option_name, true );
		?>
			<label style="display:block;margin-bottom:0.25em">
				<input type="hidden" name="<?php echo esc_attr( $option_name ); ?>" value="0">
				<input type="checkbox" name="<?php echo esc_attr( $option_name ); ?>" value="1" <?php checked( $enabled, true ); ?>>
				<?php echo esc_html( $label ); ?>
			</label>
		<?php endforeach; ?>
			<p class="description"><?php esc_html_e( 'Each enabled control appears above the main (list) table. Disable any of these to simplify the filter bar — useful when the dataset is small enough that a search field, a dropdown, or the price-range inputs is unnecessary. The detail view (per-canonical comparison) is unaffected. Pre-filtering via shortcode attributes (e.g. [supplement_compare ingredient="L-Theanine"]) still applies even when the matching control is hidden.', 'supplement-compare' ); ?></p>
		</fieldset>
		<?php
	}

	public static function render_subhead_toggles_field() {
		?>
		<fieldset>
		<?php foreach ( self::subhead_toggle_options() as $option_name => $label ) :
			$enabled = (bool) get_option( $option_name, true );
		?>
			<label style="display:block;margin-bottom:0.25em">
				<input type="hidden" name="<?php echo esc_attr( $option_name ); ?>" value="0">
				<input type="checkbox" name="<?php echo esc_attr( $option_name ); ?>" value="1" <?php checked( $enabled, true ); ?>>
				<?php echo esc_html( $label ); ?>
			</label>
		<?php endforeach; ?>
			<p class="description"><?php esc_html_e( 'Each enabled subhead renders as a small grey line of meta-text. The detail toggle applies to both the shortcode-rendered detail view and the dedicated /compare/{slug}/ landing page. Disable either subhead to keep the page cleaner when the product title already communicates enough on its own.', 'supplement-compare' ); ?></p>
		</fieldset>
		<?php
	}

	public static function render_compare_view_field() {
		$value      = self::sanitize_compare_view( get_option( 'supcomp_default_compare_view', 'cost_per_active_unit' ) );
		$multi_on   = (bool) get_option( 'supcomp_multi_compare_view_enabled', true );
		?>
		<fieldset>
			<label>
				<input type="radio" name="supcomp_default_compare_view" value="cost_per_active_unit" <?php checked( $value, 'cost_per_active_unit' ); ?>>
				<?php esc_html_e( 'Cost / Active Unit (Merchant, Total active, Cost / active unit, Price, Coupon, Buy)', 'supplement-compare' ); ?>
			</label><br>
			<label>
				<input type="radio" name="supcomp_default_compare_view" value="cost_per_serving" <?php checked( $value, 'cost_per_serving' ); ?>>
				<?php esc_html_e( 'Cost / Serving (Merchant, Serving size, Servings, Cost / serving, Price, Coupon, Buy)', 'supplement-compare' ); ?>
			</label>
			<p class="description">
			<?php if ( $multi_on ) : ?>
				<?php esc_html_e( 'Which column set the public compare table opens with. Visitors can flip between the two views with a radio toggle above the table; this setting only changes which view loads by default.', 'supplement-compare' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Multi compare-table view is off, so this is the only view visitors see — the toggle above the table is hidden.', 'supplement-compare' ); ?>
			<?php endif; ?>
			</p>
		</fieldset>
		<?php
	}

	public static function render_multi_compare_view_field() {
		$enabled = (bool) get_option( 'supcomp_multi_compare_view_enabled', true );
		?>
		<fieldset>
			<label>
				<input type="hidden" name="supcomp_multi_compare_view_enabled" value="0">
				<input type="checkbox" name="supcomp_multi_compare_view_enabled" value="1" <?php checked( $enabled, true ); ?>>
				<?php esc_html_e( 'Let visitors switch between Cost / Serving and Cost / Active Unit on the detail view', 'supplement-compare' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'When checked, the per-canonical detail view shows a "Show:" radio toggle above the table and visitors can flip between the two column sets. When unchecked, the radio toggle is hidden and visitors only ever see the Default compare-table view selected below.', 'supplement-compare' ); ?></p>
		</fieldset>
		<?php
	}

	const NONCE_REGEN = 'supcomp_regenerate_json';

	public static function register_hooks() {
		add_action( 'admin_post_supcomp_regenerate_json', array( __CLASS__, 'handle_regenerate' ) );
	}

	public static function render() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'supplement-compare' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Supplement Compare — Settings', 'supplement-compare' ); ?></h1>

			<?php self::render_notice(); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<hr style="margin:2em 0">
			<h2><?php esc_html_e( 'Public JSON export', 'supplement-compare' ); ?></h2>
			<?php self::render_export_status(); ?>
		</div>
		<?php
	}

	private static function render_export_status() {
		$path  = Supcomp_JSON_Exporter::output_path();
		$url   = Supcomp_JSON_Exporter::output_url();
		$exists = $path !== '' && file_exists( $path );
		$size  = $exists ? size_format( filesize( $path ) ) : '—';
		$mtime = $exists ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', filemtime( $path ) ), 'Y-m-d H:i' ) : '—';
		$last  = Supcomp_JSON_Exporter::last_generated_at();
		$last  = $last ? get_date_from_gmt( $last, 'Y-m-d H:i' ) : '—';
		$next  = wp_next_scheduled( Supcomp_JSON_Exporter::CRON_HOOK );
		$next  = $next ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'Y-m-d H:i' ) : '—';
		?>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'File path', 'supplement-compare' ); ?></th><td><code style="font-size:11px"><?php echo esc_html( $path ); ?></code></td></tr>
			<tr><th><?php esc_html_e( 'Public URL', 'supplement-compare' ); ?></th><td><?php if ( $exists && $url ) : ?><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $url ); ?></a><?php else : ?><span style="color:#888"><?php echo esc_html( $url ?: __( '(uploads dir not resolvable)', 'supplement-compare' ) ); ?></span><?php endif; ?></td></tr>
			<tr><th><?php esc_html_e( 'File on disk', 'supplement-compare' ); ?></th><td><?php echo $exists ? esc_html( $size . ' — last written ' . $mtime ) : '<span style="color:#888">' . esc_html__( '(no file written yet)', 'supplement-compare' ) . '</span>'; ?></td></tr>
			<tr><th><?php esc_html_e( 'Last recorded regenerate', 'supplement-compare' ); ?></th><td><?php echo esc_html( $last ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Next scheduled cron', 'supplement-compare' ); ?></th><td><?php echo esc_html( $next ); ?></td></tr>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="supcomp_regenerate_json">
			<?php wp_nonce_field( self::NONCE_REGEN ); ?>
			<?php submit_button( __( 'Regenerate now', 'supplement-compare' ), 'primary', '', false ); ?>
		</form>

		<p class="description"><?php esc_html_e( 'The JSON regenerates automatically after any offer state change (save / approve / pause / etc.), after each CSV import, and once an hour as a backup. Use this button to force a regenerate (e.g. after editing canonical product display names you want reflected immediately).', 'supplement-compare' ); ?></p>
		<?php
	}

	public static function handle_regenerate() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'supplement-compare' ) );
		}
		check_admin_referer( self::NONCE_REGEN );

		$result = Supcomp_JSON_Exporter::generate();
		if ( is_wp_error( $result ) ) {
			$msg = $result->get_error_message();
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'supcomp_notice' => 'regen_error', 'msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
			exit;
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => self::PAGE_SLUG,
					'supcomp_notice' => 'regen_ok',
					'canonicals'     => (int) $result['canonical_products'],
					'offers'         => (int) $result['offers'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function render_notice() {
		if ( empty( $_GET['supcomp_notice'] ) ) {
			return;
		}
		$type       = sanitize_key( wp_unslash( $_GET['supcomp_notice'] ) );
		$msg        = isset( $_GET['msg'] ) ? wp_unslash( $_GET['msg'] ) : '';
		$canonicals = isset( $_GET['canonicals'] ) ? (int) wp_unslash( $_GET['canonicals'] ) : 0;
		$offers     = isset( $_GET['offers'] ) ? (int) wp_unslash( $_GET['offers'] ) : 0;
		if ( $type === 'regen_ok' ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( __( 'Public JSON regenerated: %1$d canonical products, %2$d offers.', 'supplement-compare' ), $canonicals, $offers ) )
			);
		} elseif ( $type === 'regen_error' ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $msg !== '' ? $msg : __( 'Regeneration failed.', 'supplement-compare' ) )
			);
		}
	}
}
