<?php
/**
 * Database installer.
 *
 * Creates the eight tables from PROJECTBRIEF.md §3 via dbDelta and seeds the
 * Settings page defaults. dbDelta is idempotent — re-running install() will
 * add missing columns/indexes without disturbing existing data.
 *
 * MySQL ENUMs are intentionally avoided in favor of VARCHAR with allowed
 * values declared as class constants below. Two reasons:
 *
 *   1. dbDelta has historical quirks with ENUM column comparisons; spurious
 *      ALTER TABLEs would fire on every activation.
 *   2. Adding a new allowed value to an ENUM requires a schema migration;
 *      adding one to a VARCHAR is a code-only change.
 *
 * The application layer validates against the constants.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Installer {

	const SCHEMA_VERSION = '2';
	const SCHEMA_OPTION  = 'supcomp_schema_version';

	// Allowed enum-like values, documented in PROJECTBRIEF.md §3.

	const MERCHANT_PLATFORMS = array( 'shopify', 'woocommerce', 'generic', 'manual' );
	const MERCHANT_STATUSES  = array( 'active', 'paused', 'dead' );

	const INGREDIENT_CATEGORIES = array(
		'nootropic', 'longevity', 'sports',
		'mineral', 'vitamin', 'amino_acid', 'other',
	);
	const INGREDIENT_UNITS     = array( 'mg', 'mcg', 'g', 'IU', 'billion_cfu' );
	const INGREDIENT_STATUSES  = array( 'active', 'draft', 'retired' );

	const PRODUCT_FORMS    = array(
		'capsule', 'tablet', 'softgel', 'powder',
		'liquid', 'sublingual', 'gummy', 'other',
	);
	const PRODUCT_STATUSES = array( 'active', 'draft', 'retired' );

	const STOCK_STATUSES = array(
		'in_stock', 'out_of_stock', 'backorder', 'unavailable', 'unknown',
	);

	const VISIBILITY_STATUSES = array(
		'pending', 'active', 'paused', 'rejected',
		'stale', 'dead', 'needs_review',
	);

	const VARIATION_RETRIEVAL_STATUSES = array(
		'not_applicable', 'retrieved', 'failed', 'fallback_parent_only',
	);

	const IMPORT_RUN_STATUSES = array(
		'validating', 'importing', 'complete', 'failed', 'rolled_back',
	);

	/**
	 * Runs on plugin activation. Also called by maybe_upgrade() when the
	 * stored schema version is older than SCHEMA_VERSION (e.g. after a
	 * plugin update without a deactivate/reactivate cycle).
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$cc     = $wpdb->get_charset_collate();
		$prefix = $wpdb->prefix . 'supcomp_';

		$statements = array(
			self::merchants_sql( $prefix, $cc ),
			self::canonical_ingredients_sql( $prefix, $cc ),
			self::canonical_products_sql( $prefix, $cc ),
			self::import_runs_sql( $prefix, $cc ),
			self::raw_source_offers_sql( $prefix, $cc ),
			self::normalized_offers_sql( $prefix, $cc ),
			self::price_history_sql( $prefix, $cc ),
			self::click_log_sql( $prefix, $cc ),
		);

		foreach ( $statements as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * Called on every page load (via plugins_loaded). Runs install() if the
	 * recorded schema version is behind. Cheap when up to date.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::SCHEMA_OPTION ) !== self::SCHEMA_VERSION ) {
			self::install();
		}
	}

	/**
	 * Settings-page defaults. Uses add_option(), so re-running this never
	 * overwrites a value the operator has changed.
	 */
	public static function seed_default_options() {
		add_option( 'supcomp_default_currency', 'USD' );
		add_option( 'supcomp_staleness_warn_hours', 48 );
		add_option( 'supcomp_staleness_hide_hours', 168 );
		add_option(
			'supcomp_affiliate_disclosure',
			'This site contains affiliate links. When you click a "Buy Now" button and complete a purchase, we may earn a commission at no additional cost to you. Prices are sourced from each merchant\'s public listings and may not reflect current promotions. This site does not make therapeutic or health claims about any product listed.'
		);
	}

	// === Table definitions ===

	private static function merchants_sql( $prefix, $cc ) {
		return "CREATE TABLE {$prefix}merchants (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(64) NOT NULL,
			name VARCHAR(255) NOT NULL,
			site_url VARCHAR(255) NOT NULL,
			platform VARCHAR(32) NOT NULL DEFAULT 'generic',
			default_currency CHAR(3) NOT NULL DEFAULT 'USD',
			affiliate_url_template TEXT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			notes TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY site_url (site_url),
			KEY status (status)
		) {$cc};";
	}

	private static function canonical_ingredients_sql( $prefix, $cc ) {
		return "CREATE TABLE {$prefix}canonical_ingredients (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(64) NOT NULL,
			name VARCHAR(255) NOT NULL,
			aliases_json LONGTEXT NULL,
			category VARCHAR(32) NOT NULL DEFAULT 'other',
			default_unit VARCHAR(16) NOT NULL DEFAULT 'mg',
			elemental_percentage DECIMAL(5,2) NULL,
			standardization_compound VARCHAR(255) NULL,
			standardization_default_pct DECIMAL(5,2) NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'draft',
			notes TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY category (category),
			KEY status (status)
		) {$cc};";
	}

	private static function canonical_products_sql( $prefix, $cc ) {
		return "CREATE TABLE {$prefix}canonical_products (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(128) NOT NULL,
			ingredient_id BIGINT(20) UNSIGNED NOT NULL,
			ingredient_form VARCHAR(32) NOT NULL DEFAULT 'capsule',
			strength_per_serving DECIMAL(12,4) NOT NULL DEFAULT 0,
			servings_per_container INT NULL,
			total_strength DECIMAL(14,4) NULL,
			standardization_compound VARCHAR(255) NULL,
			standardization_percentage DECIMAL(5,2) NULL,
			active_compound_per_serving DECIMAL(12,4) NULL,
			display_name VARCHAR(255) NOT NULL DEFAULT '',
			seo_indexable TINYINT(1) NOT NULL DEFAULT 0,
			seo_content LONGTEXT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'draft',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY ingredient_id (ingredient_id, ingredient_form),
			KEY status_indexable (status, seo_indexable)
		) {$cc};";
	}

	private static function import_runs_sql( $prefix, $cc ) {
		return "CREATE TABLE {$prefix}import_runs (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			export_run_id VARCHAR(64) NOT NULL DEFAULT '',
			exported_at DATETIME NULL,
			imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			csv_filename VARCHAR(255) NOT NULL DEFAULT '',
			row_count INT NOT NULL DEFAULT 0,
			rows_inserted INT NOT NULL DEFAULT 0,
			rows_updated INT NOT NULL DEFAULT 0,
			rows_marked_stale INT NOT NULL DEFAULT 0,
			rows_errored INT NOT NULL DEFAULT 0,
			status VARCHAR(16) NOT NULL DEFAULT 'validating',
			error_log LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY imported_at (imported_at),
			KEY status (status),
			KEY export_run_id (export_run_id)
		) {$cc};";
	}

	private static function raw_source_offers_sql( $prefix, $cc ) {
		return "CREATE TABLE {$prefix}raw_source_offers (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			import_run_id BIGINT(20) UNSIGNED NOT NULL,
			merchant_id BIGINT(20) UNSIGNED NOT NULL,
			source_platform VARCHAR(32) NOT NULL DEFAULT '',
			source_product_id VARCHAR(255) NOT NULL DEFAULT '',
			source_variant_id VARCHAR(255) NOT NULL DEFAULT '',
			raw_csv_row_json LONGTEXT NULL,
			imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY import_run_id (import_run_id),
			KEY merchant_lookup (merchant_id, source_product_id, source_variant_id)
		) {$cc};";
	}

	private static function normalized_offers_sql( $prefix, $cc ) {
		return "CREATE TABLE {$prefix}normalized_offers (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			merchant_id BIGINT(20) UNSIGNED NOT NULL,
			canonical_product_id BIGINT(20) UNSIGNED NULL,
			source_platform VARCHAR(32) NOT NULL DEFAULT '',
			source_product_id VARCHAR(255) NOT NULL DEFAULT '',
			source_variant_id VARCHAR(255) NOT NULL DEFAULT '',
			product_title VARCHAR(512) NOT NULL DEFAULT '',
			variant_title VARCHAR(255) NOT NULL DEFAULT '',
			brand VARCHAR(255) NOT NULL DEFAULT '',
			sku VARCHAR(255) NOT NULL DEFAULT '',
			barcode VARCHAR(64) NOT NULL DEFAULT '',
			ingredient_id BIGINT(20) UNSIGNED NULL,
			ingredient_form VARCHAR(32) NULL,
			strength_per_serving DECIMAL(12,4) NULL,
			strength_unit VARCHAR(16) NULL,
			servings_per_container INT NULL,
			total_strength DECIMAL(14,4) NULL,
			standardization_percentage DECIMAL(5,2) NULL,
			active_compound_per_serving DECIMAL(12,4) NULL,
			active_compound_total DECIMAL(14,4) NULL,
			regular_price DECIMAL(10,4) NULL,
			sale_price DECIMAL(10,4) NULL,
			current_price DECIMAL(10,4) NULL,
			on_sale TINYINT(1) NOT NULL DEFAULT 0,
			currency CHAR(3) NOT NULL DEFAULT 'USD',
			cost_per_serving DECIMAL(10,4) NULL,
			cost_per_active_unit DECIMAL(12,6) NULL,
			stock_status VARCHAR(16) NOT NULL DEFAULT 'unknown',
			third_party_tested TINYINT(1) NOT NULL DEFAULT 0,
			coa_available TINYINT(1) NOT NULL DEFAULT 0,
			coa_url VARCHAR(512) NULL,
			certifications_json LONGTEXT NULL,
			source_product_url VARCHAR(512) NOT NULL DEFAULT '',
			source_variant_url VARCHAR(512) NULL,
			affiliate_url VARCHAR(512) NULL,
			visibility_status VARCHAR(32) NOT NULL DEFAULT 'pending',
			match_confidence DECIMAL(3,2) NULL,
			variation_retrieval_status VARCHAR(32) NOT NULL DEFAULT 'not_applicable',
			operator_notes TEXT NULL,
			last_seen_import_run_id BIGINT(20) UNSIGNED NULL,
			first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_synced_at DATETIME NULL,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY source_natural (merchant_id, source_product_id, source_variant_id),
			KEY canonical_product (canonical_product_id, visibility_status),
			KEY visibility_sync (visibility_status, last_synced_at),
			KEY ingredient_id (ingredient_id),
			KEY barcode (barcode),
			KEY cost_per_active_unit (canonical_product_id, cost_per_active_unit)
		) {$cc};";
	}

	private static function price_history_sql( $prefix, $cc ) {
		return "CREATE TABLE {$prefix}price_history (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			offer_id BIGINT(20) UNSIGNED NOT NULL,
			old_regular_price DECIMAL(10,4) NULL,
			new_regular_price DECIMAL(10,4) NULL,
			old_sale_price DECIMAL(10,4) NULL,
			new_sale_price DECIMAL(10,4) NULL,
			old_stock_status VARCHAR(32) NULL,
			new_stock_status VARCHAR(32) NULL,
			import_run_id BIGINT(20) UNSIGNED NULL,
			changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY offer_changed (offer_id, changed_at),
			KEY import_run_id (import_run_id),
			KEY changed_at (changed_at)
		) {$cc};";
	}

	private static function click_log_sql( $prefix, $cc ) {
		return "CREATE TABLE {$prefix}click_log (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			offer_id BIGINT(20) UNSIGNED NULL,
			canonical_product_id BIGINT(20) UNSIGNED NULL,
			merchant_id BIGINT(20) UNSIGNED NULL,
			clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			ip_hash CHAR(64) NOT NULL DEFAULT '',
			user_agent_hash CHAR(64) NOT NULL DEFAULT '',
			referrer VARCHAR(512) NULL,
			utm_source VARCHAR(128) NULL,
			utm_medium VARCHAR(128) NULL,
			utm_campaign VARCHAR(128) NULL,
			is_bot_suspected TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY offer_clicked (offer_id, clicked_at),
			KEY canonical_clicked (canonical_product_id, clicked_at),
			KEY merchant_clicked (merchant_id, clicked_at),
			KEY clicked_at (clicked_at),
			KEY ip_bot (ip_hash, clicked_at)
		) {$cc};";
	}
}
