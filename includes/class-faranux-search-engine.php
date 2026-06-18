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

	public function register() {
		add_filter( 'posts_search', array( $this, 'enforce_strict_and_search' ), 10, 2 );
	}

	public function enforce_strict_and_search( $search, $query ) {
		if ( is_admin() || ! $query->is_search() || ! $query->is_main_query() ) {
			return $search;
		}

		$raw_terms = trim( (string) $query->get( 's' ) );
		if ( '' === $raw_terms ) {
			return $search;
		}

		$terms = preg_split( '/\s+/', $raw_terms );
		$terms = array_values( array_filter( $terms, function( $term ) {
			return '' !== trim( $term );
		} ) );

		if ( count( $terms ) < 2 ) {
			return $search;
		}

		global $wpdb;
		$clauses = array();

		foreach ( $terms as $term ) {
			$term = $wpdb->esc_like( $term );
			$clauses[] = "({$wpdb->posts}.post_title LIKE '%{$term}%' OR {$wpdb->posts}.post_content LIKE '%{$term}%')";
		}

		return ' AND (' . implode( ' AND ', $clauses ) . ')';
	}
}
