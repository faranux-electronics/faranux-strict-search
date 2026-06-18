<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Faranux_Search_Indexer {

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
		add_action( 'save_post',    array( $this, 'handle_save_post' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'handle_delete_post' ) );
		add_action( 'trashed_post', array( $this, 'handle_delete_post' ) );
	}

	// -------------------------------------------------------------------------
	// Hook callbacks
	// -------------------------------------------------------------------------

	/**
	 * Triggered when a post is saved. Skips revisions, autosaves, and
	 * unsupported post types / statuses.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 * @param bool    $update
	 */
	public function handle_save_post( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->get_supported_post_types(), true ) ) {
			return;
		}

		if ( ! in_array( $post->post_status, array( 'publish', 'draft', 'pending' ), true ) ) {
			return;
		}

		$this->reindex_post( $post_id );
	}

	/**
	 * Removes a post's index entries when it is deleted or trashed.
	 *
	 * @param int $post_id
	 */
	public function handle_delete_post( $post_id ) {
		Faranux_Search_DB::instance()->clear_index_for_post( $post_id );
	}

	// -------------------------------------------------------------------------
	// Indexing
	// -------------------------------------------------------------------------

	/**
	 * (Re)indexes a single post.
	 *
	 * @param int $post_id
	 */
	public function reindex_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$terms = $this->extract_terms( $post );
		Faranux_Search_DB::instance()->save_terms( $post_id, $terms );
	}

	/**
	 * Bulk reindex all supported, published/draft/pending posts.
	 * Called on plugin activation so existing content is immediately searchable.
	 * For very large catalogues consider scheduling this via WP-Cron instead.
	 */
	public static function bulk_reindex() {
		$indexer    = self::instance();
		$post_types = $indexer->get_supported_post_types();

		$ids = get_posts( array(
			'post_type'      => $post_types,
			'post_status'    => array( 'publish', 'draft', 'pending' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		foreach ( $ids as $post_id ) {
			$indexer->reindex_post( $post_id );
		}
	}

	// -------------------------------------------------------------------------
	// Term extraction
	// -------------------------------------------------------------------------

	/**
	 * Extracts a deduplicated list of lowercased tokens from a post.
	 * Includes: title, content, excerpt, WooCommerce SKU, and attribute values.
	 *
	 * @param  WP_Post $post
	 * @return string[]
	 */
	public function extract_terms( WP_Post $post ) {
		$source = array();

		// Core text fields.
		$source[] = $post->post_title;
		$source[] = $post->post_content;
		$source[] = $post->post_excerpt;

		// WooCommerce-specific fields.
		if ( 'product' === $post->post_type ) {
			$source = array_merge( $source, $this->extract_woo_terms( $post->ID ) );
		}

		// Merge, strip HTML, strip non-alphanumeric, lowercase.
		$combined = implode( ' ', array_filter( $source ) );
		$combined = wp_strip_all_tags( $combined );

		// Keep letters, numbers, hyphens (for part numbers like SIM7080G, MPU-6500).
		$combined = preg_replace( '/[^\p{L}\p{N}\-\s]/u', ' ', $combined );
		$combined = strtolower( trim( $combined ) );

		$terms = preg_split( '/\s+/', $combined );

		// Minimum 2 chars to capture part suffixes like "5V", "I2C", "TX".
		$terms = array_values( array_filter( $terms, function ( $term ) {
			return strlen( $term ) >= 2;
		} ) );

		return array_unique( $terms );
	}

	/**
	 * Extracts WooCommerce-specific search tokens: SKU and attribute option values.
	 *
	 * @param  int $post_id
	 * @return string[]
	 */
	private function extract_woo_terms( $post_id ) {
		$extra = array();

		// SKU — the primary identifier for hardware parts.
		$sku = get_post_meta( $post_id, '_sku', true );
		if ( ! empty( $sku ) ) {
			$extra[] = $sku;
		}

		// Product attributes (e.g. "5V", "I2C", "NPN", "SMD").
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );
			if ( $product ) {
				foreach ( $product->get_attributes() as $attribute ) {
					if ( $attribute->is_taxonomy() ) {
						// Taxonomy-based attribute: get term names.
						$terms = wc_get_product_terms( $post_id, $attribute->get_name(), array( 'fields' => 'names' ) );
						if ( ! is_wp_error( $terms ) ) {
							$extra = array_merge( $extra, (array) $terms );
						}
					} else {
						// Custom attribute: pipe-separated string.
						$options = $attribute->get_options();
						if ( is_array( $options ) ) {
							$extra = array_merge( $extra, $options );
						}
					}
				}
			}
		}

		return $extra;
	}

	// -------------------------------------------------------------------------
	// Configuration
	// -------------------------------------------------------------------------

	/**
	 * Returns the post types that should be indexed.
	 * Filterable so third-party plugins can add their own types.
	 *
	 * @return string[]
	 */
	public function get_supported_post_types() {
		return apply_filters(
			'faranux_strict_search_supported_post_types',
			array( 'post', 'product' )
		);
	}
}
