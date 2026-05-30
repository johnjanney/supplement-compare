<?php
/**
 * Centralized hard-delete + cascade logic for v1.5.0 cleanup feature.
 *
 * Cascade model (per operator decision, recorded in CHANGELOG 1.5.0):
 *
 *   Hybrid — nuke per-row audit, preserve cross-cutting analytics:
 *     - price_history: delete with the offer (audit trail is per-offer).
 *     - raw_source_offers: delete with the offer (CSV snapshot, per-offer).
 *     - click_log: PRESERVE — set the relevant FK to NULL so historical
 *       click totals remain in the dashboard.
 *
 * State gates — hard-delete refuses unless the row is in soft-trash state.
 * Forces the operator workflow: reject/retire/dead first → review → purge.
 *
 *   - Offers: visibility_status IN ('rejected', 'dead')
 *   - Merchants: status = 'dead'
 *   - Ingredients: status = 'retired' AND no canonical_products reference it
 *   - Canonical products: status = 'retired'
 *
 * Refusal returns a structured error so the calling screen can render a
 * useful operator message instead of dying.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Deletion_Service {

	// State-gate helpers — also used by admin screens to decide whether
	// to show a "Delete permanently" button on a row.

	public static function offer_is_deletable( $offer ) {
		return $offer && in_array( $offer->visibility_status, array( 'rejected', 'dead' ), true );
	}

	public static function merchant_is_deletable( $merchant ) {
		return $merchant && $merchant->status === 'dead';
	}

	public static function ingredient_is_deletable( $ingredient ) {
		if ( ! $ingredient || $ingredient->status !== 'retired' ) {
			return false;
		}
		return self::canonical_count_for_ingredient( (int) $ingredient->id ) === 0;
	}

	public static function canonical_is_deletable( $canonical ) {
		return $canonical && $canonical->status === 'retired';
	}

	// ---------- previews ----------

	/**
	 * Return counts of downstream rows that will be affected by deleting an
	 * offer. Called by the confirmation dialog so the operator sees the
	 * blast radius before pressing Delete.
	 *
	 * @return array{
	 *     price_history_rows:int,    // will be deleted
	 *     raw_csv_rows:int,          // will be deleted
	 *     click_log_rows:int,        // will be preserved (FK nulled)
	 * }
	 */
	public static function preview_offer_deletion( $offer ) {
		global $wpdb;
		if ( ! $offer ) {
			return array( 'price_history_rows' => 0, 'raw_csv_rows' => 0, 'click_log_rows' => 0 );
		}
		$prefix = $wpdb->prefix . 'supcomp_';
		return array(
			'price_history_rows' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}price_history WHERE offer_id = %d", (int) $offer->id ) ),
			'raw_csv_rows'       => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$prefix}raw_source_offers WHERE merchant_id = %d AND source_product_id = %s AND source_variant_id = %s",
				(int) $offer->merchant_id,
				(string) $offer->source_product_id,
				(string) $offer->source_variant_id
			) ),
			'click_log_rows'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}click_log WHERE offer_id = %d", (int) $offer->id ) ),
		);
	}

	/**
	 * @return array{
	 *     offer_count:int,            // will be deleted (cascade)
	 *     price_history_rows:int,     // will be deleted (cascade)
	 *     raw_csv_rows:int,           // will be deleted (cascade)
	 *     click_log_rows:int,         // will be preserved (FK nulled)
	 * }
	 */
	public static function preview_merchant_deletion( $merchant ) {
		global $wpdb;
		if ( ! $merchant ) {
			return array( 'offer_count' => 0, 'price_history_rows' => 0, 'raw_csv_rows' => 0, 'click_log_rows' => 0 );
		}
		$prefix = $wpdb->prefix . 'supcomp_';
		$mid    = (int) $merchant->id;
		return array(
			'offer_count'        => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}normalized_offers WHERE merchant_id = %d", $mid ) ),
			'price_history_rows' => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$prefix}price_history ph
				INNER JOIN {$prefix}normalized_offers o ON o.id = ph.offer_id
				WHERE o.merchant_id = %d",
				$mid
			) ),
			'raw_csv_rows'       => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}raw_source_offers WHERE merchant_id = %d", $mid ) ),
			'click_log_rows'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}click_log WHERE merchant_id = %d", $mid ) ),
		);
	}

	/**
	 * @return array{
	 *     canonical_count:int,       // BLOCKS delete if > 0
	 *     offer_count:int,           // will be orphaned (ingredient_id → NULL)
	 * }
	 */
	public static function preview_ingredient_deletion( $ingredient ) {
		global $wpdb;
		if ( ! $ingredient ) {
			return array( 'canonical_count' => 0, 'offer_count' => 0 );
		}
		$prefix = $wpdb->prefix . 'supcomp_';
		return array(
			'canonical_count' => self::canonical_count_for_ingredient( (int) $ingredient->id ),
			'offer_count'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}normalized_offers WHERE ingredient_id = %d", (int) $ingredient->id ) ),
		);
	}

	/**
	 * @return array{
	 *     offer_count:int,           // will be orphaned (canonical_product_id → NULL)
	 *     click_log_rows:int,        // will be preserved (canonical_product_id → NULL)
	 * }
	 */
	public static function preview_canonical_deletion( $canonical ) {
		global $wpdb;
		if ( ! $canonical ) {
			return array( 'offer_count' => 0, 'click_log_rows' => 0 );
		}
		$prefix = $wpdb->prefix . 'supcomp_';
		return array(
			'offer_count'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}normalized_offers WHERE canonical_product_id = %d", (int) $canonical->id ) ),
			'click_log_rows' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}click_log WHERE canonical_product_id = %d", (int) $canonical->id ) ),
		);
	}

	// ---------- single-row hard deletes ----------

	/**
	 * @return array{ok:bool, error?:string, deleted?:array}
	 */
	public static function hard_delete_offer( $offer_id ) {
		$offer = Supcomp_Offers_Repo::get( (int) $offer_id );
		if ( ! $offer ) {
			return array( 'ok' => false, 'error' => 'Offer not found.' );
		}
		if ( ! self::offer_is_deletable( $offer ) ) {
			return array(
				'ok'    => false,
				'error' => sprintf(
					'Offer must be in visibility "rejected" or "dead" before it can be hard-deleted. Current state: %s.',
					$offer->visibility_status
				),
			);
		}

		global $wpdb;
		$prefix = $wpdb->prefix . 'supcomp_';
		$id     = (int) $offer->id;

		$preview = self::preview_offer_deletion( $offer );

		// Suppression list (v1.23.0): an operator-REJECTED offer that's now being
		// purged must not silently revive as 'pending' the next time the extractor
		// re-sees the product. Record the natural key before the row is gone.
		// Scope is deliberately 'rejected' only — 'dead' offers disappeared from
		// the merchant (stale detector), not an operator judgment, so they may
		// legitimately return.
		if ( $offer->visibility_status === 'rejected' ) {
			Supcomp_Suppressions_Repo::record(
				(int) $offer->merchant_id,
				(string) $offer->source_product_id,
				(string) $offer->source_variant_id,
				array(
					'product_title' => $offer->product_title,
					'brand'         => $offer->brand,
				),
				'rejected_cleanup',
				$id
			);
		}

		$wpdb->delete( $prefix . 'price_history', array( 'offer_id' => $id ), array( '%d' ) );

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$prefix}click_log SET offer_id = NULL WHERE offer_id = %d",
			$id
		) );

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$prefix}raw_source_offers WHERE merchant_id = %d AND source_product_id = %s AND source_variant_id = %s",
			(int) $offer->merchant_id,
			(string) $offer->source_product_id,
			(string) $offer->source_variant_id
		) );

		$deleted = $wpdb->delete( $prefix . 'normalized_offers', array( 'id' => $id ), array( '%d' ) );

		do_action( 'supcomp_data_changed', array( 'source' => 'deletion', 'offer_deleted' => $id ) );

		return array(
			'ok'      => $deleted !== false,
			'deleted' => array(
				'offer'              => $id,
				'price_history_rows' => $preview['price_history_rows'],
				'raw_csv_rows'       => $preview['raw_csv_rows'],
				'click_log_nulled'   => $preview['click_log_rows'],
			),
		);
	}

	public static function hard_delete_merchant( $merchant_id ) {
		$merchant = Supcomp_Merchants_Repo::get( (int) $merchant_id );
		if ( ! $merchant ) {
			return array( 'ok' => false, 'error' => 'Merchant not found.' );
		}
		if ( ! self::merchant_is_deletable( $merchant ) ) {
			return array(
				'ok'    => false,
				'error' => sprintf(
					'Merchant must be in status "dead" before it can be hard-deleted. Current state: %s.',
					$merchant->status
				),
			);
		}

		global $wpdb;
		$prefix  = $wpdb->prefix . 'supcomp_';
		$mid     = (int) $merchant->id;
		$preview = self::preview_merchant_deletion( $merchant );

		// Cascade-delete the merchant's offers (each via the offer cascade) so
		// price_history rows go and click_log offer_ids get nulled per offer.
		$offer_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$prefix}normalized_offers WHERE merchant_id = %d", $mid ) );
		foreach ( $offer_ids as $oid ) {
			// Direct cascade bypasses the state gate (the merchant itself is the
			// gate). We can't call hard_delete_offer because those offers may
			// still be visibility=active.
			self::cascade_delete_offer_rows( (int) $oid );
		}

		$wpdb->query( $wpdb->prepare( "UPDATE {$prefix}click_log SET merchant_id = NULL WHERE merchant_id = %d", $mid ) );
		$wpdb->delete( $prefix . 'raw_source_offers', array( 'merchant_id' => $mid ), array( '%d' ) );
		$deleted = $wpdb->delete( $prefix . 'merchants', array( 'id' => $mid ), array( '%d' ) );

		do_action( 'supcomp_data_changed', array( 'source' => 'deletion', 'merchant_deleted' => $mid ) );

		return array(
			'ok'      => $deleted !== false,
			'deleted' => array(
				'merchant'           => $mid,
				'offer_count'        => $preview['offer_count'],
				'price_history_rows' => $preview['price_history_rows'],
				'raw_csv_rows'       => $preview['raw_csv_rows'],
				'click_log_nulled'   => $preview['click_log_rows'],
			),
		);
	}

	public static function hard_delete_ingredient( $ingredient_id ) {
		$ingredient = Supcomp_Ingredients_Repo::get( (int) $ingredient_id );
		if ( ! $ingredient ) {
			return array( 'ok' => false, 'error' => 'Ingredient not found.' );
		}
		if ( $ingredient->status !== 'retired' ) {
			return array(
				'ok'    => false,
				'error' => sprintf(
					'Ingredient must be in status "retired" before it can be hard-deleted. Current state: %s.',
					$ingredient->status
				),
			);
		}
		$canonical_count = self::canonical_count_for_ingredient( (int) $ingredient->id );
		if ( $canonical_count > 0 ) {
			return array(
				'ok'    => false,
				'error' => sprintf(
					'Cannot delete this ingredient — %d canonical product(s) still reference it. Retire and delete those first.',
					$canonical_count
				),
			);
		}

		global $wpdb;
		$prefix  = $wpdb->prefix . 'supcomp_';
		$iid     = (int) $ingredient->id;
		$preview = self::preview_ingredient_deletion( $ingredient );

		$wpdb->query( $wpdb->prepare( "UPDATE {$prefix}normalized_offers SET ingredient_id = NULL WHERE ingredient_id = %d", $iid ) );
		$deleted = $wpdb->delete( $prefix . 'canonical_ingredients', array( 'id' => $iid ), array( '%d' ) );

		do_action( 'supcomp_data_changed', array( 'source' => 'deletion', 'ingredient_deleted' => $iid ) );

		return array(
			'ok'      => $deleted !== false,
			'deleted' => array(
				'ingredient'           => $iid,
				'offers_orphaned' => $preview['offer_count'],
			),
		);
	}

	public static function hard_delete_canonical_product( $canonical_id ) {
		$canonical = Supcomp_Canonical_Products_Repo::get( (int) $canonical_id );
		if ( ! $canonical ) {
			return array( 'ok' => false, 'error' => 'Canonical product not found.' );
		}
		if ( ! self::canonical_is_deletable( $canonical ) ) {
			return array(
				'ok'    => false,
				'error' => sprintf(
					'Canonical product must be in status "retired" before it can be hard-deleted. Current state: %s.',
					$canonical->status
				),
			);
		}

		global $wpdb;
		$prefix  = $wpdb->prefix . 'supcomp_';
		$cid     = (int) $canonical->id;
		$preview = self::preview_canonical_deletion( $canonical );

		$wpdb->query( $wpdb->prepare( "UPDATE {$prefix}normalized_offers SET canonical_product_id = NULL WHERE canonical_product_id = %d", $cid ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$prefix}click_log SET canonical_product_id = NULL WHERE canonical_product_id = %d", $cid ) );
		$deleted = $wpdb->delete( $prefix . 'canonical_products', array( 'id' => $cid ), array( '%d' ) );

		do_action( 'supcomp_data_changed', array( 'source' => 'deletion', 'canonical_deleted' => $cid ) );

		return array(
			'ok'      => $deleted !== false,
			'deleted' => array(
				'canonical'         => $cid,
				'offers_orphaned'   => $preview['offer_count'],
				'click_log_nulled'  => $preview['click_log_rows'],
			),
		);
	}

	// ---------- bulk operations ----------

	/**
	 * @return array{deleted:int, details:array}
	 */
	public static function bulk_delete_rejected_offers( $older_than_days = 0 ) {
		return self::bulk_delete_offers_by_visibility( 'rejected', (int) $older_than_days );
	}

	public static function bulk_delete_dead_offers( $older_than_days = 0 ) {
		return self::bulk_delete_offers_by_visibility( 'dead', (int) $older_than_days );
	}

	private static function bulk_delete_offers_by_visibility( $visibility, $older_than_days ) {
		global $wpdb;
		$prefix = $wpdb->prefix . 'supcomp_';
		$sql    = "SELECT id FROM {$prefix}normalized_offers WHERE visibility_status = %s";
		$params = array( $visibility );
		if ( $older_than_days > 0 ) {
			$sql       .= ' AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)';
			$params[]   = (int) $older_than_days;
		}
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
		$count = 0;
		foreach ( $ids as $oid ) {
			$result = self::hard_delete_offer( (int) $oid );
			if ( ! empty( $result['ok'] ) ) {
				$count++;
			}
		}
		return array( 'deleted' => $count, 'considered' => count( $ids ) );
	}

	/**
	 * Delete dead merchants that have no offers. Useful for cleaning up
	 * test merchants that never ended up with anything live.
	 */
	public static function bulk_delete_empty_dead_merchants() {
		global $wpdb;
		$prefix = $wpdb->prefix . 'supcomp_';
		$ids = $wpdb->get_col(
			"SELECT m.id FROM {$prefix}merchants m
			 LEFT JOIN {$prefix}normalized_offers o ON o.merchant_id = m.id
			 WHERE m.status = 'dead' AND o.id IS NULL
			 GROUP BY m.id"
		);
		$count = 0;
		foreach ( $ids as $mid ) {
			$result = self::hard_delete_merchant( (int) $mid );
			if ( ! empty( $result['ok'] ) ) {
				$count++;
			}
		}
		return array( 'deleted' => $count, 'considered' => count( $ids ) );
	}

	public static function bulk_delete_empty_retired_canonicals() {
		global $wpdb;
		$prefix = $wpdb->prefix . 'supcomp_';
		$ids = $wpdb->get_col(
			"SELECT c.id FROM {$prefix}canonical_products c
			 LEFT JOIN {$prefix}normalized_offers o ON o.canonical_product_id = c.id
			 WHERE c.status = 'retired' AND o.id IS NULL
			 GROUP BY c.id"
		);
		$count = 0;
		foreach ( $ids as $cid ) {
			$result = self::hard_delete_canonical_product( (int) $cid );
			if ( ! empty( $result['ok'] ) ) {
				$count++;
			}
		}
		return array( 'deleted' => $count, 'considered' => count( $ids ) );
	}

	public static function bulk_delete_empty_retired_ingredients() {
		global $wpdb;
		$prefix = $wpdb->prefix . 'supcomp_';
		// Empty = status=retired AND no canonicals AND no offers.
		$ids = $wpdb->get_col(
			"SELECT i.id FROM {$prefix}canonical_ingredients i
			 LEFT JOIN {$prefix}canonical_products c ON c.ingredient_id = i.id
			 LEFT JOIN {$prefix}normalized_offers o ON o.ingredient_id = i.id
			 WHERE i.status = 'retired' AND c.id IS NULL AND o.id IS NULL
			 GROUP BY i.id"
		);
		$count = 0;
		foreach ( $ids as $iid ) {
			$result = self::hard_delete_ingredient( (int) $iid );
			if ( ! empty( $result['ok'] ) ) {
				$count++;
			}
		}
		return array( 'deleted' => $count, 'considered' => count( $ids ) );
	}

	// ---------- bulk count previews (for the Cleanup UI) ----------

	public static function cleanup_counts() {
		global $wpdb;
		$prefix = $wpdb->prefix . 'supcomp_';
		return array(
			'rejected_offers'           => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}normalized_offers WHERE visibility_status = 'rejected'" ),
			'dead_offers'               => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}normalized_offers WHERE visibility_status = 'dead'" ),
			'empty_dead_merchants'      => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM (
					SELECT m.id FROM {$prefix}merchants m
					LEFT JOIN {$prefix}normalized_offers o ON o.merchant_id = m.id
					WHERE m.status = 'dead' AND o.id IS NULL
					GROUP BY m.id
				) x"
			),
			'empty_retired_canonicals'  => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM (
					SELECT c.id FROM {$prefix}canonical_products c
					LEFT JOIN {$prefix}normalized_offers o ON o.canonical_product_id = c.id
					WHERE c.status = 'retired' AND o.id IS NULL
					GROUP BY c.id
				) x"
			),
			'empty_retired_ingredients' => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM (
					SELECT i.id FROM {$prefix}canonical_ingredients i
					LEFT JOIN {$prefix}canonical_products c ON c.ingredient_id = i.id
					LEFT JOIN {$prefix}normalized_offers o ON o.ingredient_id = i.id
					WHERE i.status = 'retired' AND c.id IS NULL AND o.id IS NULL
					GROUP BY i.id
				) x"
			),
		);
	}

	// ---------- internals ----------

	private static function canonical_count_for_ingredient( $ingredient_id ) {
		global $wpdb;
		$prefix = $wpdb->prefix . 'supcomp_';
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$prefix}canonical_products WHERE ingredient_id = %d",
			(int) $ingredient_id
		) );
	}

	/**
	 * Cascade-only helper. Drops everything downstream of an offer and the
	 * offer row itself, no state gate. Used by hard_delete_merchant so the
	 * merchant's active offers don't block deletion.
	 */
	private static function cascade_delete_offer_rows( $offer_id ) {
		global $wpdb;
		$prefix = $wpdb->prefix . 'supcomp_';
		$offer  = Supcomp_Offers_Repo::get( (int) $offer_id );
		if ( ! $offer ) {
			return;
		}
		$id = (int) $offer->id;
		$wpdb->delete( $prefix . 'price_history', array( 'offer_id' => $id ), array( '%d' ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$prefix}click_log SET offer_id = NULL WHERE offer_id = %d", $id ) );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$prefix}raw_source_offers WHERE merchant_id = %d AND source_product_id = %s AND source_variant_id = %s",
			(int) $offer->merchant_id,
			(string) $offer->source_product_id,
			(string) $offer->source_variant_id
		) );
		$wpdb->delete( $prefix . 'normalized_offers', array( 'id' => $id ), array( '%d' ) );
	}
}
