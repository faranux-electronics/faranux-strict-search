<?php

if (!defined('ABSPATH')) {
	exit;
}

class Faranux_Search_Indexer
{

	protected static $instance = null;

	public static function instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
			self::$instance->register();
		}
		return self::$instance;
	}

	public function register()
	{
		add_action('save_post', array($this, 'handle_save_post'), 10, 3);
		add_action('deleted_post', array($this, 'handle_delete_post'));
		add_action('trashed_post', array($this, 'handle_delete_post'));
	}

	public function handle_save_post($post_id, $post, $update)
	{
		if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
			return;
		}
		if (!in_array($post->post_type, $this->get_supported_post_types(), true)) {
			return;
		}
		if (!in_array($post->post_status, array('publish', 'draft', 'pending'), true)) {
			return;
		}
		$this->reindex_post($post_id);
	}

	public function handle_delete_post($post_id)
	{
		Faranux_Search_DB::instance()->clear_index_for_post($post_id);
		delete_transient('faranux_search_dictionary');
		delete_transient('faranux_search_dictionary_data');
	}

	public function reindex_post($post_id)
	{
		$post = get_post($post_id);
		if (!$post) {
			return;
		}
		$terms = $this->extract_terms($post);
		Faranux_Search_DB::instance()->save_terms($post_id, $terms);
		delete_transient('faranux_search_dictionary');
		delete_transient('faranux_search_dictionary_data');

		if (get_option('faranux_enable_logging', 0) && function_exists('wc_get_logger')) {
			$logger = wc_get_logger();
			$logger->debug(sprintf('Indexed post %d with %d terms', $post_id, count($terms)), ['source' => 'faranux-search']);
		}
	}

	public static function bulk_reindex()
	{
		$indexer = self::instance();
		$post_types = $indexer->get_supported_post_types();

		$ids = get_posts(array(
			'post_type' => $post_types,
			'post_status' => array('publish', 'draft', 'pending'),
			'posts_per_page' => -1,
			'fields' => 'ids',
		));

		foreach ($ids as $post_id) {
			$indexer->reindex_post($post_id);
		}

		delete_transient('faranux_search_dictionary');
		delete_transient('faranux_search_dictionary_data');

		if (get_option('faranux_enable_logging', 0) && function_exists('wc_get_logger')) {
			$logger = wc_get_logger();
			$logger->info(sprintf('Bulk reindex completed: %d posts processed', count($ids)), ['source' => 'faranux-search']);
		}
	}

	public function extract_terms(WP_Post $post)
	{
		$fields = get_option('faranux_index_fields', ['title' => 1, 'content' => 1, 'excerpt' => 0, 'sku' => 1, 'attributes' => 1]);
		$source = array();

		if (!empty($fields['title'])) {
			$source[] = $post->post_title;
		}
		if (!empty($fields['content'])) {
			$source[] = $post->post_content;
		}
		if (!empty($fields['excerpt'])) {
			$source[] = $post->post_excerpt;
		}

		if ('product' === $post->post_type) {
			if (!empty($fields['sku'])) {
				$sku = get_post_meta($post->ID, '_sku', true);
				if (!empty($sku)) {
					$source[] = $sku;
				}
			}
			if (!empty($fields['attributes']) && function_exists('wc_get_product')) {
				$product = wc_get_product($post->ID);
				if ($product) {
					foreach ($product->get_attributes() as $attribute) {
						if ($attribute->is_taxonomy()) {
							$terms = wc_get_product_terms($post->ID, $attribute->get_name(), array('fields' => 'names'));
							if (!is_wp_error($terms)) {
								$source = array_merge($source, (array) $terms);
							}
						} else {
							$options = $attribute->get_options();
							if (is_array($options)) {
								$source = array_merge($source, $options);
							}
						}
					}
				}
			}
		}

		$combined = implode(' ', array_filter($source));
		$combined = wp_strip_all_tags($combined);
		$combined = preg_replace('/[^\p{L}\p{N}\-\s]/u', ' ', $combined);
		$combined = strtolower(trim($combined));

		$tokens = preg_split('/[\s\-]+/', $combined);

		$default_stops = 'a, also, am, an, and, are, as, at, be, but, by, call, can, co, con, de, do, due, eg, eight, etc, even, ever, every, for, from, full, go, had, has, hasnt, have, he, hence, her, here, his, how, ie, if, in, inc, into, is, it, its, ltd, me, my, no, none, nor, not, now, of, off, on, once, one, only, onto, or, our, ours, out, over, own, part, per, put, re, see, so, some, ten, than, that, the, their, there, these, they, this, three, thru, thus, to, too, top, un, up, us, very, via, was, we, well, were, what, when, where, who, why, will';
		$stop_words_raw = get_option('faranux_stop_words', $default_stops);
		$stop_words = array_filter(array_map('trim', explode(',', strtolower($stop_words_raw))));

		$final_terms = array();
		foreach ($tokens as $token) {
			if (strlen($token) < 2)
				continue;

			$split = preg_split('/(?<=[a-z])(?=[0-9])|(?<=[0-9])(?=[a-z])/', $token);
			foreach ($split as $part) {
				if (strlen($part) >= 2) {
					$final_terms[] = $part;
				}
			}
		}

		// Prevent MySQL Strict Mode crash by enforcing length limits (< 100 chars)
		$final_terms = array_filter($final_terms, function ($term) use ($stop_words) {
			return strlen($term) >= 2 && strlen($term) <= 100 && !in_array($term, $stop_words, true);
		});

		return array_values(array_unique($final_terms));
	}

	public function get_supported_post_types()
	{
		return apply_filters(
			'faranux_strict_search_supported_post_types',
			array('post', 'product')
		);
	}
}