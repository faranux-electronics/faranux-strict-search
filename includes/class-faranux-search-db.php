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

	public static function activate() {
		self::instance()->create_table();
	}

	public static function deactivate() {
		// Hooked for future cleanup if needed.
	}

	public function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'faranux_search_index';
	}

	public function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name = $this->table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			post_id BIGINT(20) NOT NULL,
			term VARCHAR(255) NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY term (term)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	public function clear_index_for_post( $post_id ) {
		global $wpdb;

		$wpdb->delete(
			$this->table_name(),
			array( 'post_id' => (int) $post_id ),
			array( '%d' )
		);
	}

	public function save_terms( $post_id, $terms ) {
		global $wpdb;

		$post_id = (int) $post_id;
		$this->clear_index_for_post( $post_id );

		$terms = array_values( array_unique( array_filter( $terms, function( $term ) {
			return is_string( $term ) && '' !== trim( $term );
		} ) ) );

		if ( empty( $terms ) ) {
			return;
		}

		foreach ( $terms as $term ) {
			$wpdb->insert(
				$this->table_name(),
				array(
					'post_id' => $post_id,
					'term'    => sanitize_text_field( strtolower( $term ) ),
				),
				array( '%d', '%s' )
			);
		}
	}
}
