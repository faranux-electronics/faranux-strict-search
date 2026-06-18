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

	public function register() {
		add_action( 'save_post', array( $this, 'handle_save_post' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'handle_delete_post' ) );
		add_action( 'trashed_post', array( $this, 'handle_delete_post' ) );
	}

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

	public function handle_delete_post( $post_id ) {
		Faranux_Search_DB::instance()->clear_index_for_post( $post_id );
	}

	public function reindex_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$terms = $this->extract_terms( $post );
		Faranux_Search_DB::instance()->save_terms( $post_id, $terms );
	}

	public function extract_terms( $post ) {
		$source = array();
		$source[] = $post->post_title;
		$source[] = $post->post_content;
		$source[] = $post->post_excerpt;

		$combined = implode( ' ', $source );
		$combined = wp_strip_all_tags( $combined );
		$combined = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $combined );
		$combined = strtolower( trim( $combined ) );

		$terms = preg_split( '/\s+/', $combined );
		$terms = array_values( array_filter( $terms, function( $term ) {
			return strlen( $term ) >= 3;
		} ) );

		return $terms;
	}

	public function get_supported_post_types() {
		return apply_filters( 'faranux_strict_search_supported_post_types', array( 'post', 'product' ) );
	}
}
