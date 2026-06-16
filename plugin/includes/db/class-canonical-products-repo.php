<?php
/**
 * Repository for `canonical_products`.
 *
 * On upsert, derived fields are computed per PROJECTBRIEF.md §6:
 *
 *   total_strength               = strength_per_serving × servings_per_container
 *                                  (null if servings_per_container is unknown)
 *
 *   active_compound_per_serving  = strength_per_serving × pct/100, where pct comes from
 *                                  product.standardization_percentage, OR
 *                                  ingredient.standardization_default_pct, OR
 *                                  ingredient.elemental_percentage, OR
 *                                  no scaling (active = strength).
 *
 * Standardization on the product overrides standardization on the ingredient.
 * Elemental percentage only applies when no standardization context is set
 * (e.g. magnesium glycinate's 14.10% elemental fraction).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Canonical_Products_Repo {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'supcomp_canonical_products';
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) );
	}

	/**
	 * Count of live canonical products (everything not retired), for the
	 * dashboard widget. Mirrors the for_picker() filter so the figure matches
	 * the products the operator actually works with.
	 */
	public static function count_active() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status <> 'retired'" );
	}

	public static function get_by_slug( $slug ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", (string) $slug ) );
	}

	/**
	 * List products joined to ingredient name/slug for display.
	 *
	 * @param array $args ingredient_id, status, form, search, orderby, order, limit, offset
	 */
	public static function query( $args = array() ) {
		global $wpdb;
		$defaults = array(
			'ingredient_id' => 0,
			'status'        => '',
			'form'          => '',
			'search'        => '',
			'orderby'       => 'display_name',
			'order'         => 'ASC',
			'limit'         => 200,
			'offset'        => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		$cp = self::table();
		$ci = $wpdb->prefix . 'supcomp_canonical_ingredients';

		$where  = array();
		$params = array();

		if ( $args['ingredient_id'] > 0 ) {
			$where[]  = 'cp.ingredient_id = %d';
			$params[] = (int) $args['ingredient_id'];
		}
		if ( $args['status'] !== '' ) {
			$where[]  = 'cp.status = %s';
			$params[] = $args['status'];
		}
		if ( $args['form'] !== '' ) {
			$where[]  = 'cp.ingredient_form = %s';
			$params[] = $args['form'];
		}
		if ( $args['search'] !== '' ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(cp.display_name LIKE %s OR cp.slug LIKE %s OR ci.name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$allowed_orderby = array(
			'display_name'        => 'cp.display_name',
			'slug'                => 'cp.slug',
			'ingredient'          => 'ci.name',
			'strength_per_serving'=> 'cp.strength_per_serving',
			'status'              => 'cp.status',
			'updated_at'          => 'cp.updated_at',
		);
		$orderby_sql = isset( $allowed_orderby[ $args['orderby'] ] ) ? $allowed_orderby[ $args['orderby'] ] : 'cp.display_name';
		$order       = strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC';

		$where_sql = empty( $where ) ? '1=1' : implode( ' AND ', $where );

		$sql = "SELECT cp.*, ci.name AS ingredient_name, ci.slug AS ingredient_slug, ci.default_unit AS ingredient_unit
				FROM {$cp} cp
				LEFT JOIN {$ci} ci ON ci.id = cp.ingredient_id
				WHERE {$where_sql}
				ORDER BY {$orderby_sql} {$order}
				LIMIT %d OFFSET %d";

		$params[] = (int) $args['limit'];
		$params[] = (int) $args['offset'];

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Lightweight list for the offer-form canonical picker. Returns active +
	 * draft products with ingredient name attached, ordered by ingredient
	 * then display name so options can be <optgroup>'d.
	 */
	public static function for_picker() {
		global $wpdb;
		$cp = self::table();
		$ci = $wpdb->prefix . 'supcomp_canonical_ingredients';
		return $wpdb->get_results(
			"SELECT cp.id, cp.display_name, cp.slug, cp.ingredient_form, cp.strength_per_serving,
					ci.id AS ingredient_id, ci.name AS ingredient_name, ci.default_unit AS ingredient_unit
			 FROM {$cp} cp
			 LEFT JOIN {$ci} ci ON ci.id = cp.ingredient_id
			 WHERE cp.status <> 'retired'
			 ORDER BY ci.name ASC, cp.display_name ASC"
		);
	}

	public static function upsert( $data ) {
		global $wpdb;
		// `id` is passed in by the admin edit handler; CSV imports omit it and
		// rely on slug-based upsert. Pull it out before sanitize so it doesn't
		// land in the column array as a stray field.
		$edit_id = isset( $data['id'] ) ? absint( $data['id'] ) : 0;
		unset( $data['id'] );

		$clean = self::sanitize( $data );

		if ( empty( $clean['slug'] ) ) {
			return new WP_Error( 'supcomp_missing_slug', __( 'Slug is required.', 'supplement-compare' ) );
		}
		if ( empty( $clean['ingredient_id'] ) ) {
			return new WP_Error( 'supcomp_missing_ingredient', __( 'Ingredient is required.', 'supplement-compare' ) );
		}

		$ingredient = Supcomp_Ingredients_Repo::get( $clean['ingredient_id'] );
		if ( ! $ingredient ) {
			return new WP_Error( 'supcomp_bad_ingredient', __( 'Ingredient not found.', 'supplement-compare' ) );
		}

		// Editing an existing row: locate by id, not slug, so a slug change
		// updates the row in place instead of orphaning the original.
		$existing = null;
		if ( $edit_id > 0 ) {
			$existing = self::get( $edit_id );
			if ( ! $existing ) {
				return new WP_Error( 'supcomp_missing_row', __( 'Canonical product not found.', 'supplement-compare' ) );
			}
			// Guard against renaming a slug onto another row's slug.
			if ( $clean['slug'] !== $existing->slug ) {
				$conflict = self::get_by_slug( $clean['slug'] );
				if ( $conflict && (int) $conflict->id !== (int) $existing->id ) {
					return new WP_Error( 'supcomp_slug_conflict', __( 'Another canonical product already uses that slug.', 'supplement-compare' ) );
				}
			}
		}

		// Build derivation inputs by falling back to stored values for any
		// fields the caller didn't submit. The admin form intentionally omits
		// strength_per_serving / servings_per_container as of v1.1.1, but
		// existing rows may still have those values — don't wipe their
		// derived columns just because the form didn't repeat them.
		$derivation_inputs = $clean;
		if ( $existing ) {
			foreach ( array( 'strength_per_serving', 'servings_per_container', 'standardization_percentage' ) as $field ) {
				if ( ! array_key_exists( $field, $derivation_inputs ) ) {
					$derivation_inputs[ $field ] = $existing->$field;
				}
			}
		}

		// Default display_name if blank.
		if ( empty( $clean['display_name'] ) ) {
			$clean['display_name'] = self::derive_display_name( $ingredient, $derivation_inputs );
		}

		// Compute total_strength and active_compound_per_serving from the
		// merged input set.
		$derived = self::compute_derived( $derivation_inputs, $ingredient );
		$clean   = array_merge( $clean, $derived );

		$now                 = current_time( 'mysql', true );
		$clean['updated_at'] = $now;

		if ( $existing ) {
			$wpdb->update( self::table(), $clean, array( 'id' => (int) $existing->id ) );
			return array(
				'id'      => (int) $existing->id,
				'created' => false,
			);
		}

		// New-row path (also the CSV-import path): upsert by slug.
		$existing = self::get_by_slug( $clean['slug'] );
		if ( $existing ) {
			$wpdb->update( self::table(), $clean, array( 'id' => (int) $existing->id ) );
			return array(
				'id'      => (int) $existing->id,
				'created' => false,
			);
		}

		$clean['created_at'] = $now;
		$wpdb->insert( self::table(), $clean );
		return array(
			'id'      => (int) $wpdb->insert_id,
			'created' => true,
		);
	}

	public static function set_status( $id, $status ) {
		global $wpdb;
		if ( ! in_array( $status, Supcomp_Installer::PRODUCT_STATUSES, true ) ) {
			return false;
		}
		return false !== $wpdb->update(
			self::table(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $id ) )
		);
	}

	/**
	 * Derived field computation per PROJECTBRIEF.md §6.
	 *
	 * @param array  $product    Sanitized product data (must include strength_per_serving).
	 * @param object $ingredient Row from canonical_ingredients table.
	 * @return array{total_strength: float|null, active_compound_per_serving: float|null}
	 */
	public static function compute_derived( $product, $ingredient ) {
		$has_strength = array_key_exists( 'strength_per_serving', $product )
			&& $product['strength_per_serving'] !== null
			&& $product['strength_per_serving'] !== '';
		$strength = $has_strength ? (float) $product['strength_per_serving'] : null;
		$servings = array_key_exists( 'servings_per_container', $product ) && $product['servings_per_container'] !== null && $product['servings_per_container'] !== ''
			? (int) $product['servings_per_container']
			: null;

		$derived                   = array();
		$derived['total_strength'] = ( $servings && $strength !== null && $strength > 0 ) ? round( $strength * $servings, 4 ) : null;

		// When the canonical has no strength, downstream per-serving math
		// belongs at the offer level — leave the derived column NULL.
		if ( $strength === null ) {
			$derived['active_compound_per_serving'] = null;
			return $derived;
		}

		// standardization_percentage: product override → ingredient default → none.
		$std_pct = null;
		if ( array_key_exists( 'standardization_percentage', $product ) && $product['standardization_percentage'] !== null ) {
			$std_pct = (float) $product['standardization_percentage'];
		} elseif ( $ingredient && $ingredient->standardization_default_pct !== null ) {
			$std_pct = (float) $ingredient->standardization_default_pct;
		}

		$el_pct = ( $ingredient && $ingredient->elemental_percentage !== null )
			? (float) $ingredient->elemental_percentage
			: null;

		if ( $std_pct !== null ) {
			$derived['active_compound_per_serving'] = round( $strength * ( $std_pct / 100 ), 4 );
		} elseif ( $el_pct !== null ) {
			$derived['active_compound_per_serving'] = round( $strength * ( $el_pct / 100 ), 4 );
		} else {
			$derived['active_compound_per_serving'] = $strength > 0 ? $strength : null;
		}

		return $derived;
	}

	public static function derive_display_name( $ingredient, $product ) {
		$has_strength = array_key_exists( 'strength_per_serving', $product )
			&& $product['strength_per_serving'] !== null
			&& $product['strength_per_serving'] !== '';
		$strength = $has_strength ? (float) $product['strength_per_serving'] : null;
		$unit     = $ingredient->default_unit;
		$form     = ! empty( $product['ingredient_form'] ) ? (string) $product['ingredient_form'] : '';

		$form_label = '';
		if ( $form !== '' ) {
			$form_label = ucfirst( $form );
			if ( in_array( $form, array( 'capsule', 'tablet', 'softgel', 'gummy' ), true ) ) {
				$form_label .= 's';
			}
		}

		// Without pinned strength or form the canonical is the ingredient
		// itself — display name is just the ingredient name.
		if ( $strength === null && $form === '' ) {
			return trim( $ingredient->name );
		}
		if ( $strength === null ) {
			return trim( $ingredient->name . ' ' . $form_label );
		}

		$strength_fmt = ( $strength == (int) $strength ) ? (string) (int) $strength : rtrim( rtrim( number_format( $strength, 4, '.', '' ), '0' ), '.' );
		$pieces       = array( $ingredient->name, $strength_fmt . $unit );
		if ( $form_label !== '' ) {
			$pieces[] = $form_label;
		}
		return trim( implode( ' ', $pieces ) );
	}

	public static function sanitize( $data ) {
		$clean = array();

		if ( isset( $data['slug'] ) ) {
			$clean['slug'] = sanitize_title( $data['slug'] );
		}
		if ( array_key_exists( 'ingredient_id', $data ) ) {
			$clean['ingredient_id'] = absint( $data['ingredient_id'] );
		}
		if ( array_key_exists( 'ingredient_form', $data ) ) {
			$form = sanitize_key( (string) $data['ingredient_form'] );
			// Blank → NULL — a canonical without a pinned form spans capsules,
			// powders, etc. for the same ingredient.
			if ( $form === '' ) {
				$clean['ingredient_form'] = null;
			} else {
				$clean['ingredient_form'] = in_array( $form, Supcomp_Installer::PRODUCT_FORMS, true ) ? $form : null;
			}
		}
		if ( array_key_exists( 'strength_per_serving', $data ) ) {
			$val                            = trim( (string) $data['strength_per_serving'] );
			// NULL when blank — strength is optional at the canonical level so a
			// single canonical can group offers with varying brand strengths.
			// Per-offer strength drives the cost-per-active-unit math.
			$clean['strength_per_serving'] = $val === '' ? null : (float) $val;
		}
		if ( array_key_exists( 'servings_per_container', $data ) ) {
			$val                              = trim( (string) $data['servings_per_container'] );
			$clean['servings_per_container'] = $val === '' ? null : (int) $val;
		}
		if ( array_key_exists( 'standardization_compound', $data ) ) {
			$val                                = trim( (string) $data['standardization_compound'] );
			$clean['standardization_compound'] = $val === '' ? null : sanitize_text_field( $val );
		}
		if ( array_key_exists( 'standardization_percentage', $data ) ) {
			$val                                  = trim( (string) $data['standardization_percentage'] );
			$clean['standardization_percentage'] = $val === '' ? null : (float) $val;
		}
		if ( isset( $data['display_name'] ) ) {
			$clean['display_name'] = sanitize_text_field( $data['display_name'] );
		}
		if ( array_key_exists( 'seo_indexable', $data ) ) {
			$clean['seo_indexable'] = self::truthy( $data['seo_indexable'] ) ? 1 : 0;
		}
		if ( array_key_exists( 'seo_content', $data ) ) {
			$raw                    = (string) $data['seo_content'];
			// wp_kses_post strips JS / tracking / unsafe tags but keeps basic
			// HTML so the operator can write paragraphs, lists, links.
			$clean['seo_content']  = $raw === '' ? null : wp_kses_post( $raw );
		}
		if ( isset( $data['status'] ) ) {
			$s              = sanitize_key( $data['status'] );
			$clean['status'] = in_array( $s, Supcomp_Installer::PRODUCT_STATUSES, true ) ? $s : 'draft';
		}

		return $clean;
	}

	private static function truthy( $val ) {
		if ( is_bool( $val ) ) {
			return $val;
		}
		$v = strtolower( trim( (string) $val ) );
		return in_array( $v, array( '1', 'true', 'yes', 'y', 't', 'on' ), true );
	}
}
