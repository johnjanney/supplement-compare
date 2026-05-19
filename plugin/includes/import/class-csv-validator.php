<?php
/**
 * CSV validator — pre-import gate (PROJECTBRIEF.md §4 + §8 Phase 4).
 *
 * Returns a structured result rather than throwing. The importer treats a
 * non-empty `errors` set as fatal: either every row passes the basic shape
 * check or nothing is written. Per-row errors are 1-indexed against the
 * spreadsheet (header = row 1, data starts row 2) so the operator can find
 * the offending line by scrolling.
 *
 * What this validator does NOT do:
 *   - normalization (strength / form / standardization extraction) — Phase 5
 *   - canonical matching — Phase 5
 *   - merchant existence is checked here, but unknown merchants warn rather
 *     than fail the whole file: the validator returns the unknown-merchant
 *     list separately so the import screen can offer a "create them first"
 *     remediation flow.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_CSV_Validator {

	const REQUIRED_COLUMNS = array(
		'export_run_id',
		'exported_at',
		'source',
		'site',
		'source_product_id',
		'product_title',
		'on_sale',
		'stock_status',
		'source_product_url',
		'variation_retrieval_status',
	);

	const ALLOWED_SOURCES = array( 'shopify', 'woocommerce', 'generic' );

	/**
	 * @param string $filepath  Path to the uploaded CSV on disk.
	 * @return array{
	 *     ok: bool,
	 *     header: array<int,string>,
	 *     rows: array<int,array>,        // 1-indexed by spreadsheet row number; data rows start at 2
	 *     errors: array<int,string>,     // same indexing
	 *     missing_merchants: array<string,int>,   // site_url => count of rows
	 *     export_run_id: string,
	 *     exported_at: string,
	 *     fatal: string|null,            // top-level error that aborted parsing
	 * }
	 */
	public static function validate( $filepath ) {
		$result = array(
			'ok'                => false,
			'header'            => array(),
			'rows'              => array(),
			'errors'            => array(),
			'missing_merchants' => array(),
			'export_run_id'     => '',
			'exported_at'       => '',
			'fatal'             => null,
		);

		if ( ! is_string( $filepath ) || ! is_readable( $filepath ) ) {
			$result['fatal'] = __( 'Uploaded file could not be read.', 'supplement-compare' );
			return $result;
		}

		$fh = fopen( $filepath, 'r' );
		if ( ! $fh ) {
			$result['fatal'] = __( 'Could not open uploaded file.', 'supplement-compare' );
			return $result;
		}

		$header = fgetcsv( $fh );
		if ( $header === false ) {
			fclose( $fh );
			$result['fatal'] = __( 'CSV is empty or has no header row.', 'supplement-compare' );
			return $result;
		}

		$header = array_map(
			static function ( $h ) {
				return trim( (string) $h );
			},
			$header
		);
		// Strip UTF-8 BOM from the first cell if present.
		if ( isset( $header[0] ) ) {
			$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
		}
		$result['header'] = $header;

		$missing = array_diff( self::REQUIRED_COLUMNS, $header );
		if ( ! empty( $missing ) ) {
			fclose( $fh );
			$result['fatal'] = sprintf(
				/* translators: %s is a comma-separated list of column names */
				__( 'CSV is missing required column(s): %s', 'supplement-compare' ),
				implode( ', ', $missing )
			);
			return $result;
		}

		// First pass: read rows, run per-row validation, build the natural-key
		// duplicate map and the merchant cache.
		$row_num            = 1; // header is row 1
		$seen_natural_keys  = array(); // "merchant_id|product_id|variant_id" => earlier_row_num
		$merchant_cache     = array(); // site_url => stdClass|null
		$run_id_seen        = '';
		$exported_at_seen   = '';

		while ( ( $line = fgetcsv( $fh ) ) !== false ) {
			++$row_num;

			if ( count( $line ) === 1 && trim( (string) $line[0] ) === '' ) {
				continue;
			}

			$row = self::row_from_line( $header, $line );

			// Capture the export metadata from the first row that has it.
			if ( $run_id_seen === '' && isset( $row['export_run_id'] ) ) {
				$run_id_seen = trim( (string) $row['export_run_id'] );
			}
			if ( $exported_at_seen === '' && isset( $row['exported_at'] ) ) {
				$exported_at_seen = trim( (string) $row['exported_at'] );
			}

			$err = self::validate_row( $row );
			if ( $err !== null ) {
				$result['errors'][ $row_num ] = $err;
				continue;
			}

			// Resolve merchant (cached). Unknown site goes to missing_merchants,
			// not errors — operator likely just needs to create the merchant row.
			$site = (string) $row['site'];
			if ( ! array_key_exists( $site, $merchant_cache ) ) {
				$merchant_cache[ $site ] = Supcomp_Merchants_Repo::get_by_site_url( $site );
			}
			$merchant = $merchant_cache[ $site ];
			if ( ! $merchant ) {
				if ( ! isset( $result['missing_merchants'][ $site ] ) ) {
					$result['missing_merchants'][ $site ] = 0;
				}
				++$result['missing_merchants'][ $site ];
				$result['errors'][ $row_num ] = sprintf(
					/* translators: %s is a merchant site URL */
					__( 'No merchant exists with site_url matching "%s". Create the merchant first.', 'supplement-compare' ),
					$site
				);
				continue;
			}
			if ( $merchant->status !== 'active' ) {
				$result['errors'][ $row_num ] = sprintf(
					/* translators: 1: merchant slug, 2: status (paused/dead) */
					__( 'Merchant "%1$s" is %2$s; offers from it cannot be imported until it is reactivated.', 'supplement-compare' ),
					$merchant->slug,
					$merchant->status
				);
				continue;
			}

			// Duplicate-within-file check on the natural key.
			$nk = $merchant->id . '|' . (string) $row['source_product_id'] . '|' . (string) ( $row['source_variant_id'] ?? '' );
			if ( isset( $seen_natural_keys[ $nk ] ) ) {
				$result['errors'][ $row_num ] = sprintf(
					/* translators: %d is the earlier row number */
					__( 'Duplicate (merchant, product_id, variant_id) — first seen at row %d.', 'supplement-compare' ),
					$seen_natural_keys[ $nk ]
				);
				continue;
			}
			$seen_natural_keys[ $nk ] = $row_num;

			$row['_merchant_id'] = (int) $merchant->id;
			$result['rows'][ $row_num ] = $row;
		}
		fclose( $fh );

		$result['export_run_id'] = $run_id_seen;
		$result['exported_at']   = $exported_at_seen;
		$result['ok']            = empty( $result['errors'] );
		return $result;
	}

	private static function row_from_line( $header, $line ) {
		$row = array();
		foreach ( $header as $i => $col ) {
			if ( $col === '' ) {
				continue;
			}
			$row[ $col ] = isset( $line[ $i ] ) ? $line[ $i ] : '';
		}
		return $row;
	}

	/**
	 * Per-row shape check. Returns an error string or null when the row is OK.
	 * Order: required-non-empty → enum membership → decimal parseability.
	 */
	private static function validate_row( $row ) {
		foreach ( array( 'source', 'site', 'source_product_id', 'product_title', 'source_product_url' ) as $field ) {
			if ( ! isset( $row[ $field ] ) || trim( (string) $row[ $field ] ) === '' ) {
				return sprintf(
					/* translators: %s is a column name */
					__( 'Required column "%s" is empty.', 'supplement-compare' ),
					$field
				);
			}
		}

		if ( ! in_array( strtolower( trim( (string) $row['source'] ) ), self::ALLOWED_SOURCES, true ) ) {
			return sprintf(
				/* translators: %s is the offending source value */
				__( 'source must be one of shopify, woocommerce, generic (got "%s").', 'supplement-compare' ),
				$row['source']
			);
		}

		$on_sale = strtolower( trim( (string) $row['on_sale'] ) );
		if ( ! in_array( $on_sale, array( 'true', 'false', '1', '0' ), true ) ) {
			return sprintf(
				/* translators: %s is the offending on_sale value */
				__( 'on_sale must be true or false (got "%s").', 'supplement-compare' ),
				$row['on_sale']
			);
		}

		$stock = strtolower( trim( (string) $row['stock_status'] ) );
		if ( ! in_array( $stock, Supcomp_Installer::STOCK_STATUSES, true ) ) {
			return sprintf(
				/* translators: %s is the offending stock_status value */
				__( 'stock_status must be one of in_stock/out_of_stock/backorder/unavailable/unknown (got "%s").', 'supplement-compare' ),
				$row['stock_status']
			);
		}

		$vrs = strtolower( trim( (string) $row['variation_retrieval_status'] ) );
		if ( ! in_array( $vrs, Supcomp_Installer::VARIATION_RETRIEVAL_STATUSES, true ) ) {
			return sprintf(
				/* translators: %s is the offending variation_retrieval_status value */
				__( 'variation_retrieval_status must be one of not_applicable/retrieved/failed/fallback_parent_only (got "%s").', 'supplement-compare' ),
				$row['variation_retrieval_status']
			);
		}

		// Decimal parseability when present.
		foreach ( array( 'regular_price', 'sale_price', 'current_price' ) as $price_col ) {
			if ( isset( $row[ $price_col ] ) ) {
				$raw = trim( (string) $row[ $price_col ] );
				if ( $raw !== '' && ! is_numeric( $raw ) ) {
					return sprintf(
						/* translators: 1: column name, 2: bad value */
						__( '%1$s is not a parseable decimal (got "%2$s").', 'supplement-compare' ),
						$price_col,
						$raw
					);
				}
			}
		}

		// Currency: if present, must be 3 letters.
		if ( isset( $row['currency'] ) && trim( (string) $row['currency'] ) !== '' ) {
			$cur = trim( (string) $row['currency'] );
			if ( strlen( $cur ) !== 3 || ! ctype_alpha( $cur ) ) {
				return sprintf(
					/* translators: %s is the offending currency value */
					__( 'currency must be a 3-letter ISO 4217 code (got "%s").', 'supplement-compare' ),
					$cur
				);
			}
		}

		return null;
	}
}
