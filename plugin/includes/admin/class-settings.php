<?php
/**
 * Settings page. Built on the WordPress Settings API, which provides nonces
 * and capability checks via `options.php` and `settings_fields()`.
 *
 * Stored options:
 *   supcomp_default_currency        — ISO 4217, defaults to USD
 *   supcomp_staleness_warn_hours    — soft threshold (offer visually downgraded)
 *   supcomp_staleness_hide_hours    — hard threshold (offer excluded from public JSON)
 *   supcomp_affiliate_disclosure    — disclosure text rendered on every comparison page
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
			'supcomp_affiliate_disclosure',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);

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
			'supcomp_affiliate_disclosure',
			__( 'Affiliate disclosure text', 'supplement-compare' ),
			array( __CLASS__, 'render_disclosure_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID
		);
	}

	public static function sanitize_currency( $value ) {
		$value = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $value ) );
		$value = substr( $value, 0, 3 );
		return $value !== '' ? $value : 'USD';
	}

	public static function render_section_intro() {
		echo '<p>' . esc_html__( 'Site-wide defaults. Per-merchant overrides for currency are configured on the Merchants screen once Phase 3 lands.', 'supplement-compare' ) . '</p>';
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

	public static function render_disclosure_field() {
		$value = (string) get_option( 'supcomp_affiliate_disclosure', '' );
		printf(
			'<textarea name="supcomp_affiliate_disclosure" rows="5" cols="80" class="large-text">%s</textarea> <p class="description">%s</p>',
			esc_textarea( $value ),
			esc_html__( 'Rendered on every page that shows comparison content (Phase 9+). Keep the language factual; avoid therapeutic claims.', 'supplement-compare' )
		);
	}

	public static function render() {
		if ( ! current_user_can( Supcomp_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'supplement-compare' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Supplement Compare — Settings', 'supplement-compare' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
