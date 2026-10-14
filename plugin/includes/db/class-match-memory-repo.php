<?php
/**
 * Repository for `match_memory` — learned title → canonical mappings (v1.41.0).
 *
 * Every time the operator confirms a canonical for an offer (Approve, bulk
 * Approve, or setting the canonical in the offer editor), the offer's title
 * is reduced to a strength-agnostic key (Supcomp_Matcher::normalize_title)
 * and remembered against the canonical, scoped to the merchant it came from.
 * The matcher consults this table right after the barcode and brand+SKU
 * tiers, so one approval covers every strength and every future re-import of
 * that product name.
 *
 * The memory only ever produces a *suggestion*. Offers still land in the
 * pending queue and still need an approval (no auto-publish, CLAUDE.md
 * load-bearing #1).
 *
 * Keyed on (merchant_id, title_key). Recording the same key with a different
 * canonical overwrites the mapping — the operator's latest decision wins.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Match_Memory_Repo {

	const CONFIDENCE_SAME_MERCHANT  = 0.90;
	const CONFIDENCE_OTHER_MERCHANT = 0.80;

	// Indexed column — 191 chars keeps the unique key under the utf8mb4
	// 767-byte index limit on older MySQL.
	const KEY_MAX_LENGTH = 191;

	// Keys shorter than this are too generic to remember ("x", "kit").
	const KEY_MIN_LENGTH = 3;

	const SOURCES = array( 'approval', 'edit', 'backfill', 'manual' );

	const BACKFILL_OPTION = 'supcomp_match_memory_backfilled';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'supcomp_match_memory';
	}

	/**
	 * Strength-agnostic lookup key for a product title, or '' when the
	 * title is too generic to remember.
	 */
	public static function key_for_title( $title ) {
		$key = substr( Supcomp_Matcher::normalize_title( $title ), 0, self::KEY_MAX_LENGTH );
		$key = trim( $key );
		return strlen( $key ) >= self::KEY_MIN_LENGTH ? $key : '';
	}

	/**
	 * Find the learned canonical for a title key.
	 *
	 * A mapping learned at the same merchant wins (0.90). Otherwise mappings
	 * from other merchants are used only when they all agree on a single
	 * canonical (0.80) — a disagreement means the name is ambiguous, so no
	 * suggestion. Mappings pointing at retired canonicals are ignored.
	 *
	 * @return array{canonical_product_id:int, confidence:float}|null
	 */
	public static function lookup( $merchant_id, $key ) {
		global $wpdb;
		if ( $key === '' ) {
			return null;
		}
		$table = self::table();
		$cp    = $wpdb->prefix . 'supcomp_canonical_products';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.merchant_id, m.canonical_product_id FROM {$table} m
				 INNER JOIN {$cp} cp ON cp.id = m.canonical_product_id AND cp.status <> 'retired'
				 WHERE m.title_key = %s",
				$key
			)
		);
		if ( empty( $rows ) ) {
			return null;
		}

		$others = array();
		foreach ( $rows as $r ) {
			if ( (int) $merchant_id > 0 && (int) $r->merchant_id === (int) $merchant_id ) {
				return array(
					'canonical_product_id' => (int) $r->canonical_product_id,
					'confidence'           => self::CONFIDENCE_SAME_MERCHANT,
				);
			}
			$others[ (int) $r->canonical_product_id ] = true;
		}
		if ( count( $others ) === 1 ) {
			return array(
				'canonical_product_id' => (int) key( $others ),
				'confidence'           => self::CONFIDENCE_OTHER_MERCHANT,
			);
		}
		return null;
	}

	/**
	 * Record the operator's confirmed canonical for this offer. No-op when
	 * the offer has no canonical, no merchant, or a too-generic title.
	 *
	 * @param object $offer  Offer row (needs merchant_id, product_title,
	 *                       canonical_product_id).
	 * @param string $source One of self::SOURCES.
	 * @return string|null The title key recorded, or null when nothing was.
	 */
	public static function learn_from_offer( $offer, $source = 'approval' ) {
		if ( ! $offer || empty( $offer->canonical_product_id ) || empty( $offer->merchant_id ) ) {
			return null;
		}
		$key = self::key_for_title( $offer->product_title );
		if ( $key === '' ) {
			return null;
		}
		$canonical = Supcomp_Canonical_Products_Repo::get( (int) $offer->canonical_product_id );
		if ( ! $canonical || $canonical->status === 'retired' ) {
			return null;
		}
		self::record( (int) $offer->merchant_id, $key, (int) $canonical->id, (string) $offer->product_title, $source );
		return $key;
	}

	/**
	 * Insert or update one mapping. Same canonical → bump the confirmation
	 * count; different canonical → overwrite and restart the count.
	 */
	public static function record( $merchant_id, $key, $canonical_id, $sample_title, $source, $times = 1 ) {
		global $wpdb;
		$table  = self::table();
		$now    = current_time( 'mysql', true );
		$source = in_array( $source, self::SOURCES, true ) ? $source : 'approval';

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, canonical_product_id FROM {$table} WHERE merchant_id = %d AND title_key = %s",
				(int) $merchant_id,
				$key
			)
		);

		if ( $existing ) {
			$same = (int) $existing->canonical_product_id === (int) $canonical_id;
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					 SET canonical_product_id = %d,
					     times_confirmed = " . ( $same ? 'times_confirmed + 1' : '1' ) . ",
					     sample_title = %s, source = %s, last_confirmed_at = %s
					 WHERE id = %d",
					(int) $canonical_id,
					(string) $sample_title,
					$source,
					$now,
					(int) $existing->id
				)
			);
			return (int) $existing->id;
		}

		$wpdb->insert(
			$table,
			array(
				'merchant_id'          => (int) $merchant_id,
				'title_key'            => $key,
				'canonical_product_id' => (int) $canonical_id,
				'sample_title'         => (string) $sample_title,
				'source'               => $source,
				'times_confirmed'      => max( 1, (int) $times ),
				'created_at'           => $now,
				'last_confirmed_at'    => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Build mappings from offers the operator already approved (any status
	 * past the queue except rejected). Inserts only (merchant, key) pairs
	 * that don't exist yet, so it never overwrites a mapping the operator
	 * edited on the Learned Mappings screen. When one merchant's offers with
	 * the same key point at different canonicals, the most common one wins.
	 *
	 * @return string[] Title keys that were newly inserted.
	 */
	public static function backfill_from_approved() {
		global $wpdb;
		$offers = Supcomp_Offers_Repo::table();
		$cp     = $wpdb->prefix . 'supcomp_canonical_products';
		$rows   = $wpdb->get_results(
			"SELECT o.merchant_id, o.product_title, o.canonical_product_id, COUNT(*) AS n
			 FROM {$offers} o
			 INNER JOIN {$cp} cp ON cp.id = o.canonical_product_id AND cp.status <> 'retired'
			 WHERE o.canonical_product_id IS NOT NULL
			   AND o.visibility_status NOT IN ('pending', 'needs_review', 'rejected')
			 GROUP BY o.merchant_id, o.product_title, o.canonical_product_id"
		);

		// tally[merchant|key][canonical] = [count, sample_title]
		$tally = array();
		foreach ( (array) $rows as $r ) {
			$key = self::key_for_title( $r->product_title );
			if ( $key === '' ) {
				continue;
			}
			$slot = (int) $r->merchant_id . '|' . $key;
			$cid  = (int) $r->canonical_product_id;
			if ( ! isset( $tally[ $slot ][ $cid ] ) ) {
				$tally[ $slot ][ $cid ] = array( 0, (string) $r->product_title );
			}
			$tally[ $slot ][ $cid ][0] += (int) $r->n;
		}

		$table    = self::table();
		$inserted = array();
		foreach ( $tally as $slot => $by_canonical ) {
			list( $merchant_id, $key ) = explode( '|', $slot, 2 );
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE merchant_id = %d AND title_key = %s",
					(int) $merchant_id,
					$key
				)
			);
			if ( $exists ) {
				continue;
			}
			uasort(
				$by_canonical,
				function ( $a, $b ) {
					return $b[0] <=> $a[0];
				}
			);
			$winner = key( $by_canonical );
			list( $count, $sample ) = $by_canonical[ $winner ];
			self::record( (int) $merchant_id, $key, (int) $winner, $sample, 'backfill', $count );
			$inserted[ $key ] = true;
		}

		update_option( self::BACKFILL_OPTION, current_time( 'mysql', true ) );
		return array_keys( $inserted );
	}

	// ---------- admin screen ----------

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
	}

	public static function count_all( $search = '' ) {
		global $wpdb;
		$table = self::table();
		list( $where, $params ) = self::search_where( $search );
		$sql = "SELECT COUNT(*) FROM {$table} m {$where}";
		return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) );
	}

	public static function paginate( $page, $per_page, $search = '' ) {
		global $wpdb;
		$table = self::table();
		$cp    = $wpdb->prefix . 'supcomp_canonical_products';
		$mer   = $wpdb->prefix . 'supcomp_merchants';
		list( $where, $params ) = self::search_where( $search );
		$params[] = (int) $per_page;
		$params[] = max( 0, ( (int) $page - 1 ) * (int) $per_page );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.*, mer.name AS merchant_name, cp.display_name AS canonical_display_name, cp.status AS canonical_status
				 FROM {$table} m
				 LEFT JOIN {$mer} mer ON mer.id = m.merchant_id
				 LEFT JOIN {$cp} cp ON cp.id = m.canonical_product_id
				 {$where}
				 ORDER BY m.last_confirmed_at DESC, m.id DESC
				 LIMIT %d OFFSET %d",
				$params
			)
		);
	}

	private static function search_where( $search ) {
		global $wpdb;
		if ( $search === '' ) {
			return array( '', array() );
		}
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		return array( 'WHERE (m.title_key LIKE %s OR m.sample_title LIKE %s)', array( $like, $like ) );
	}

	/**
	 * Operator re-points a mapping from the Learned Mappings screen.
	 */
	public static function set_canonical( $id, $canonical_id ) {
		global $wpdb;
		$canonical = Supcomp_Canonical_Products_Repo::get( (int) $canonical_id );
		if ( ! $canonical || $canonical->status === 'retired' ) {
			return false;
		}
		return false !== $wpdb->update(
			self::table(),
			array(
				'canonical_product_id' => (int) $canonical_id,
				'source'               => 'manual',
				'times_confirmed'      => 1,
				'last_confirmed_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
	}

	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Drop every mapping pointing at a canonical that is being hard-deleted.
	 */
	public static function delete_for_canonical( $canonical_id ) {
		global $wpdb;
		return (int) $wpdb->delete( self::table(), array( 'canonical_product_id' => (int) $canonical_id ), array( '%d' ) );
	}
}
