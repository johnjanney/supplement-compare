<?php
/**
 * CSV bulk-import for the canonical tables (ingredients + products).
 *
 * Both formats use the column names from PROJECTBRIEF.md §3 directly. Empty
 * cells mean "leave NULL"; the importer never overwrites a populated value
 * with a blank one when updating.
 *
 * Idempotency: rows are upserted by `slug`. Re-importing the same CSV is
 * safe. Removing a row from the CSV does NOT retire that ingredient/product
 * — retirement is an explicit operator action.
 *
 * Row numbers in the error map are 1-indexed and count the header as row 1,
 * matching what the operator sees in their spreadsheet.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Canonical_CSV_Importer {

	const INGREDIENT_COLUMNS = array(
		'slug',
		'name',
		'aliases',
		'category',
		'default_unit',
		'elemental_percentage',
		'standardization_compound',
		'standardization_default_pct',
		'status',
		'notes',
	);

	const PRODUCT_COLUMNS = array(
		'slug',
		'ingredient_slug',
		'ingredient_form',
		'strength_per_serving',
		'servings_per_container',
		'standardization_compound',
		'standardization_percentage',
		'display_name',
		'seo_indexable',
		'status',
	);

	/**
	 * Import canonical_ingredients from a CSV file on disk.
	 *
	 * @return array|WP_Error array{inserted:int, updated:int, errors:array<string,string>}
	 *                        on success, WP_Error on parse failure.
	 */
	public static function import_ingredients( $filepath ) {
		$rows = self::parse_csv( $filepath, 'slug' );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$result = array(
			'inserted' => 0,
			'updated'  => 0,
			'errors'   => array(),
		);

		foreach ( $rows as $idx => $row ) {
			$row_label = sprintf( 'row %d (slug=%s)', $idx + 2, isset( $row['slug'] ) ? $row['slug'] : '?' );
			$out       = Supcomp_Ingredients_Repo::upsert( $row );
			if ( is_wp_error( $out ) ) {
				$result['errors'][ $row_label ] = $out->get_error_message();
				continue;
			}
			if ( $out['created'] ) {
				++$result['inserted'];
			} else {
				++$result['updated'];
			}
		}

		return $result;
	}

	/**
	 * Import canonical_products from a CSV file on disk.
	 *
	 * Resolves `ingredient_slug` → `ingredient_id` by looking up the
	 * ingredients table. Unknown slugs produce a per-row error.
	 */
	public static function import_canonical_products( $filepath ) {
		$rows = self::parse_csv( $filepath, 'slug' );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$result = array(
			'inserted' => 0,
			'updated'  => 0,
			'errors'   => array(),
		);

		foreach ( $rows as $idx => $row ) {
			$row_label = sprintf( 'row %d (slug=%s)', $idx + 2, isset( $row['slug'] ) ? $row['slug'] : '?' );

			if ( empty( $row['ingredient_slug'] ) ) {
				$result['errors'][ $row_label ] = __( 'ingredient_slug is required.', 'supplement-compare' );
				continue;
			}
			$ing = Supcomp_Ingredients_Repo::get_by_slug( $row['ingredient_slug'] );
			if ( ! $ing ) {
				$result['errors'][ $row_label ] = sprintf(
					/* translators: %s is the unknown slug */
					__( 'Ingredient slug "%s" not found. Import the ingredient first.', 'supplement-compare' ),
					$row['ingredient_slug']
				);
				continue;
			}
			$row['ingredient_id'] = (int) $ing->id;
			unset( $row['ingredient_slug'] );

			$out = Supcomp_Canonical_Products_Repo::upsert( $row );
			if ( is_wp_error( $out ) ) {
				$result['errors'][ $row_label ] = $out->get_error_message();
				continue;
			}
			if ( $out['created'] ) {
				++$result['inserted'];
			} else {
				++$result['updated'];
			}
		}

		return $result;
	}

	/**
	 * Read and parse a CSV, returning rows as associative arrays keyed by
	 * header. Rejects files without the required key column.
	 */
	private static function parse_csv( $filepath, $required_column ) {
		if ( ! file_exists( $filepath ) || ! is_readable( $filepath ) ) {
			return new WP_Error( 'supcomp_csv_unreadable', __( 'Uploaded file could not be read.', 'supplement-compare' ) );
		}

		$fh = fopen( $filepath, 'r' );
		if ( ! $fh ) {
			return new WP_Error( 'supcomp_csv_unreadable', __( 'Could not open uploaded file.', 'supplement-compare' ) );
		}

		$header = fgetcsv( $fh );
		if ( $header === false ) {
			fclose( $fh );
			return new WP_Error( 'supcomp_csv_empty', __( 'CSV is empty or has no header row.', 'supplement-compare' ) );
		}
		$header = array_map(
			static function ( $h ) {
				return trim( (string) $h );
			},
			$header
		);

		// Strip a UTF-8 BOM from the first header if present (Excel exports often have one).
		if ( isset( $header[0] ) ) {
			$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
		}

		if ( ! in_array( $required_column, $header, true ) ) {
			fclose( $fh );
			return new WP_Error(
				'supcomp_csv_missing_column',
				sprintf(
					/* translators: %s is a column name */
					__( 'Required column "%s" is missing from the CSV header.', 'supplement-compare' ),
					$required_column
				)
			);
		}

		$rows = array();
		while ( ( $line = fgetcsv( $fh ) ) !== false ) {
			if ( count( $line ) === 1 && trim( (string) $line[0] ) === '' ) {
				continue; // blank line
			}
			$row = array();
			foreach ( $header as $i => $col ) {
				if ( $col === '' ) {
					continue;
				}
				$row[ $col ] = isset( $line[ $i ] ) ? $line[ $i ] : '';
			}
			$rows[] = $row;
		}
		fclose( $fh );

		return $rows;
	}
}
