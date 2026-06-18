<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Faranux_Search_Engine {

	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}

		return self::$instance;
	}

	/**
	 * Registers WordPress hooks.
	 */
	public function register() {
		// Hook into posts_where rather than posts_search so we append our
		// constraint rather than replace core's clause — this keeps WooCommerce
		// SKU search and any other posts_search hooks intact.
		add_filter( 'posts_where', array( $this, 'enforce_strict_and_search' ), 10, 2 );

		// Also hook WooCommerce's own product query so filtering on the shop
		// page respects AND logic even when it doesn't go through the main query.
		add_action( 'woocommerce_product_query', array( $this, 'apply_to_woo_product_query' ) );
	}

	// -------------------------------------------------------------------------
	// Public search API
	// -------------------------------------------------------------------------

	/**
	 * Executes a strict AND search and returns matching post IDs.
	 * Used directly by the AJAX handler.
	 *
	 * @param  string $raw_query  Unsanitized search string from the user.
	 * @return int[]
	 */
	public function search( $raw_query ) {
		$terms = $this->parse_terms( $raw_query );

		if ( empty( $terms ) ) {
			return array();
		}

		return Faranux_Search_DB::instance()->find_post_ids_for_terms( $terms );
	}

	// -------------------------------------------------------------------------
	// WordPress query hooks
	// -------------------------------------------------------------------------

	/**
	 * Appends a strict-AND subquery clause to the posts_where SQL fragment.
	 * Only fires on front-end main search queries.
	 *
	 * @param  string   $where
	 * @param  WP_Query $query
	 * @return string
	 */
	public function enforce_strict_and_search( $where, $query ) {
		if ( is_admin() ) {
			return $where;
		}

		if ( ! $query->is_search() || ! $query->is_main_query() ) {
			return $where;
		}

		$raw_terms = trim( (string) $query->get( 's' ) );
		if ( '' === $raw_terms ) {
			return $where;
		}

		$terms = $this->parse_terms( $raw_terms );

		// Single-term searches: let WordPress handle it natively (LIKE is fine for one word).
		if ( count( $terms ) < 2 ) {
			return $where;
		}

		$post_ids = Faranux_Search_DB::instance()->find_post_ids_for_terms( $terms );

		if ( empty( $post_ids ) ) {
			// No matches — force zero results cleanly.
			global $wpdb;
			return $where . " AND {$wpdb->posts}.ID = 0";
		}

		global $wpdb;
		$id_list = implode( ',', array_map( 'intval', $post_ids ) );

		return $where . " AND {$wpdb->posts}.ID IN ({$id_list})";
	}

	/**
	 * Applies strict AND logic to WooCommerce's own product query (layered nav,
	 * shop-page AJAX filtering) which bypasses is_main_query().
	 *
	 * @param WP_Query $query
	 */
	public function apply_to_woo_product_query( $query ) {
		$raw_terms = trim( (string) $query->get( 's' ) );
		if ( '' === $raw_terms ) {
			return;
		}

		$terms = $this->parse_terms( $raw_terms );
		if ( count( $terms ) < 2 ) {
			return;
		}

		$post_ids = Faranux_Search_DB::instance()->find_post_ids_for_terms( $terms );

		if ( empty( $post_ids ) ) {
			// Force empty result set.
			$query->set( 'post__in', array( 0 ) );
			return;
		}

		// Intersect with any existing post__in constraint.
		$existing = $query->get( 'post__in' );
		if ( ! empty( $existing ) ) {
			$post_ids = array_intersect( $post_ids, (array) $existing );
			if ( empty( $post_ids ) ) {
				$post_ids = array( 0 );
			}
		}

		$query->set( 'post__in', $post_ids );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Splits a raw query string into sanitized, lowercased tokens.
	 *
	 * @param  string $raw
	 * @return string[]
	 */
	private function parse_terms( $raw ) {
		$raw = sanitize_text_field( wp_unslash( $raw ) );
		$raw = strtolower( trim( $raw ) );

		if ( '' === $raw ) {
			return array();
		}

		$terms = preg_split( '/\s+/', $raw );

		return array_values( array_unique( array_filter( $terms, function ( $term ) {
			// Match the 2-char minimum used in the indexer.
			return strlen( $term ) >= 2;
		} ) ) );
	}
}
