<?php
/**
 * Value object mirroring extractor/aggregate_products.py's Offer dataclass
 * (lines 62-112). One row of extractor output. All values are stored as
 * strings to match the CSV-on-the-wire shape that the importer's validator
 * already accepts — coercion to typed columns happens in
 * Supcomp_Offers_Repo::insert_from_csv / update_csv_columns.
 *
 * Field order is the PROJECTBRIEF.md §4 canonical order. Keep this list and
 * the Python dataclass in lockstep — if you add a column here, add it there
 * (or remove it on the Python side once the legacy path is retired).
 *
 * Phase A: scaffolding only — instances aren't produced yet.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Extractor_Offer {

	const SCHEMA_VERSION = '1.0';

	public $export_run_id              = '';
	public $exported_at                = '';

	public $source                     = '';
	public $site                       = '';
	public $source_product_id          = '';
	public $source_variant_id          = '';

	public $product_title              = '';
	public $variant_title              = '';
	public $handle                     = '';
	public $brand                      = '';
	public $product_type               = '';

	public $sku                        = '';
	public $barcode                    = '';

	public $regular_price              = '';
	public $sale_price                 = '';
	public $current_price              = '';
	public $on_sale                    = '';
	public $currency                   = '';
	public $currency_minor_unit        = '';
	public $price_source               = '';

	public $stock_status               = '';
	public $purchasable                = '';

	public $source_product_url         = '';
	public $source_variant_url         = '';

	public $source_created_at          = '';
	public $source_updated_at          = '';

	public $is_variable_parent         = '';
	public $variation_retrieval_status = '';

	public $description                = '';
	public $raw_attributes_json        = '';

	// Script-only debug field, not in the §4 contract.
	public $store_name                 = '';

	/**
	 * Canonical CSV column order. Same as the keys of to_row_dict().
	 */
	public static function fieldnames() {
		return array(
			'export_run_id',
			'exported_at',
			'source',
			'site',
			'source_product_id',
			'source_variant_id',
			'product_title',
			'variant_title',
			'handle',
			'brand',
			'product_type',
			'sku',
			'barcode',
			'regular_price',
			'sale_price',
			'current_price',
			'on_sale',
			'currency',
			'currency_minor_unit',
			'price_source',
			'stock_status',
			'purchasable',
			'source_product_url',
			'source_variant_url',
			'source_created_at',
			'source_updated_at',
			'is_variable_parent',
			'variation_retrieval_status',
			'description',
			'raw_attributes_json',
			'store_name',
		);
	}

	/**
	 * Return the same dict shape that fgetcsv produces for a CSV with this
	 * column set, so this object can be fed directly into
	 * Supcomp_CSV_Importer::ingest_rows() without translation.
	 */
	public function to_row_dict() {
		$out = array();
		foreach ( self::fieldnames() as $field ) {
			$out[ $field ] = (string) $this->{$field};
		}
		return $out;
	}

	/**
	 * Build an Offer from an associative array (e.g. when reconstituting a
	 * row from upstream code). Unknown keys are ignored.
	 */
	public static function from_array( array $data ) {
		$offer = new self();
		foreach ( self::fieldnames() as $field ) {
			if ( array_key_exists( $field, $data ) && $data[ $field ] !== null ) {
				$offer->{$field} = (string) $data[ $field ];
			}
		}
		return $offer;
	}
}
