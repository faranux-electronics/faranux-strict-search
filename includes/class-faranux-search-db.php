<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Faranux_Search_DB {

	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Runs on plugin activation.
	 * Creates the index table and fires a full reindex of existing content.
	 */
	public static function activate() {
		$instance = self::instance();
		$instance->create_table();

		// Store DB version so future upgrades can run dbDelta again if schema changes.
		update_option( 'faranux_search_db_version', FARANUX_SEARCH_VERSION );

		// Index all existing published products and posts.
		Faranux_Search_Indexer::bulk_reindex();
	}

	/**
	 * Runs on plugin deactivation.
	 * We intentionally keep the index table intact so re-activation is instant.
	 */
	public static function deactivate() {
		// Intentional no-op: index data is preserved across deactivation.
	}

	/**
	 * Returns the fully-qualified index table name.
	 *
	 * @return string
	 */
	public function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'faranux_search_index';
	}

	/**
	 * Creates (or upgrades) the inverted-index table using dbDelta.
	 * Safe to call multiple times.
	 */
	public function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $this->table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// `term` uses a prefix index (191 chars) to stay within the InnoDB key-length
		// limit when the table uses utf8mb4 (4 bytes × 191 = 764 bytes < 767 byte limit).
		$sql = "CREATE TABLE {$table_name} (
			id      BIGINT(20)   NOT NULL AUTO_INCREMENT,
			post_id BIGINT(20)   NOT NULL,
			term    VARCHAR(191) NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_post_id (post_id),
			KEY idx_term    (term)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Removes all indexed terms for a single post.
	 *
	 * @param int $post_id
	 */
	public function clear_index_for_post( $post_id ) {
		global $wpdb;

		$wpdb->delete(
			$this->table_name(),
			array( 'post_id' => (int) $post_id ),
			array( '%d' )
		);
	}

	/**
	 * Saves a deduplicated set of terms for a post.
	 * Clears any existing index entries for the post first.
	 *
	 * @param int      $post_id
	 * @param string[] $terms
	 */
	public function save_terms( $post_id, array $terms ) {
		global $wpdb;

		$post_id = (int) $post_id;
		$this->clear_index_for_post( $post_id );

		// Deduplicate and reject blank / non-string entries.
		$terms = array_values( array_unique( array_filter( $terms, function ( $term ) {
			return is_string( $term ) && '' !== trim( $term );
		} ) ) );

		if ( empty( $terms ) ) {
			return;
		}

		// Batch insert: build a single multi-row INSERT for performance.
		$placeholders = array();
		$values       = array();

		foreach ( $terms as $term ) {
			$placeholders[] = '(%d, %s)';
			$values[]       = $post_id;
			$values[]       = sanitize_text_field( strtolower( $term ) );
		}

		$sql = "INSERT INTO {$this->table_name()} (post_id, term) VALUES "
			   . implode( ', ', $placeholders );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built above.
		$wpdb->query( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Returns post IDs that contain ALL of the supplied terms in the index.
	 *
	 * @param  string[] $terms  Already lowercased, sanitized terms.
	 * @return int[]
	 */
	public function find_post_ids_for_terms( array $terms ) {
		global $wpdb;

		if ( empty( $terms ) ) {
			return array();
		}

		$table       = $this->table_name();
		$term_count  = count( $terms );
		$format      = implode( ', ', array_fill( 0, $term_count, '%s' ) );

		// Fetch post IDs that have at least one row for each supplied term.
		// GROUP BY + HAVING COUNT(DISTINCT term) ensures every term is present.
		$query = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT post_id
			   FROM {$table}
			  WHERE term IN ({$format})
			  GROUP BY post_id
			 HAVING COUNT(DISTINCT term) >= %d",
			array_merge( $terms, array( $term_count ) )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col( $query );

		return array_map( 'intval', (array) $rows );
	}
}
