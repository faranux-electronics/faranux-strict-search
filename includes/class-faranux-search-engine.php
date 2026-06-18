<?php

if (!defined('ABSPATH')) {
	exit;
}

class Faranux_Search_Engine
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
		add_filter('posts_search', array($this, 'remove_native_search_sql'), 999, 2);
		add_filter('posts_where', array($this, 'enforce_strict_and_search'), 10, 2);
		add_action('woocommerce_product_query', array($this, 'apply_to_woo_product_query'));
	}

	public function remove_native_search_sql($search, $query)
	{
		if (is_admin()) {
			return $search;
		}

		if (!$query->is_main_query() || !$query->is_search()) {
			return $search;
		}

		$queried_types = (array) $query->get('post_type');
		if (empty($queried_types) || $queried_types === [''] || in_array('any', $queried_types, true)) {
			$queried_types = $this->get_indexer_post_types();
		}

		if (!array_intersect($queried_types, $this->get_indexer_post_types())) {
			return $search;
		}

		$this->log('remove_native_search_sql – stripping native LIKE clauses to prevent typo-correction conflicts');
		return '';
	}

	private function log($message, $level = 'debug')
	{
		if (!get_option('faranux_enable_logging', 0) || !function_exists('wc_get_logger')) {
			return;
		}
		$logger = wc_get_logger();
		$logger->log($level, $message, ['source' => 'faranux-search']);
	}

	private function get_dictionary_and_frequencies()
	{
		$data = get_transient('faranux_search_dictionary_data');

		if (false === $data) {
			$this->log('Dictionary frequency cache expired – rebuilding');
			global $wpdb;
			$table_name = Faranux_Search_DB::instance()->table_name();

			$results = $wpdb->get_results(
				"SELECT term, COUNT(DISTINCT post_id) as freq 
                 FROM {$table_name} 
                 GROUP BY term",
				OBJECT_K
			);

			$dictionary = array();
			$max_freq = 0;
			foreach ($results as $term => $row) {
				$freq = (int) $row->freq;
				$dictionary[$term] = $freq;
				if ($freq > $max_freq) {
					$max_freq = $freq;
				}
			}

			$data = array(
				'terms' => $dictionary,
				'max_freq' => $max_freq
			);
			set_transient('faranux_search_dictionary_data', $data, 12 * HOUR_IN_SECONDS);
			$this->log(sprintf('Dictionary rebuilt with %d terms, max freq %d', count($dictionary), $max_freq));
		}

		return $data;
	}

	private function auto_correct_terms(array $terms)
	{
		$this->log(sprintf('Auto-correct: input terms = [%s]', implode(', ', $terms)));

		$data = $this->get_dictionary_and_frequencies();
		$dictionary = $data['terms'];
		$max_freq = $data['max_freq'];

		if (empty($dictionary)) {
			$this->log('Dictionary is empty – no corrections applied');
			return $terms;
		}

		$corrected_terms = array();

		foreach ($terms as $term) {
			if (isset($dictionary[$term])) {
				$corrected_terms[] = $term;
				$this->log(sprintf('Term "%s" – exact match (freq %d)', $term, $dictionary[$term]));
				continue;
			}

			$candidates = array();

			foreach ($dictionary as $dict_word => $freq) {
				if (strpos($dict_word, $term) === 0) {
					$diff = strlen($dict_word) - strlen($term);
					if ($diff >= 0 && $diff <= 2) {
						$candidates[] = array(
							'word' => $dict_word,
							'freq' => $freq,
							'distance' => $diff,
							'type' => 'prefix'
						);
					}
				}
			}

			$max_distance = (strlen($term) <= 4) ? 1 : 2;
			foreach ($dictionary as $dict_word => $freq) {
				if (abs(strlen($term) - strlen($dict_word)) > $max_distance) {
					continue;
				}
				$lev = levenshtein($term, $dict_word);
				if ($lev <= $max_distance) {
					$candidates[] = array(
						'word' => $dict_word,
						'freq' => $freq,
						'distance' => $lev,
						'type' => 'levenshtein'
					);
				}
			}

			if (empty($candidates)) {
				$corrected_terms[] = $term;
				$this->log(sprintf('Term "%s" – no correction found', $term));
				continue;
			}

			$best = null;
			$best_score = -1;
			foreach ($candidates as $cand) {
				$dist_score = 1 / ($cand['distance'] + 1);
				$freq_score = $max_freq > 0 ? $cand['freq'] / $max_freq : 0;
				$score = 0.7 * $dist_score + 0.3 * $freq_score;
				if ($score > $best_score || ($score == $best_score && strlen($cand['word']) < strlen($best['word']))) {
					$best = $cand;
					$best_score = $score;
				}
			}

			$corrected_terms[] = $best['word'];
			$this->log(sprintf(
				'Term "%s" – corrected to "%s" (%s, dist %d, freq %d, score %.2f)',
				$term,
				$best['word'],
				$best['type'],
				$best['distance'],
				$best['freq'],
				$best_score
			));
		}

		$unique = array_values(array_unique($corrected_terms));
		$this->log(sprintf('Auto-correct: output terms = [%s]', implode(', ', $unique)));
		return $unique;
	}

	public function search($raw_query)
	{
		$this->log(sprintf('Search called with raw query: "%s"', $raw_query));

		$terms = $this->parse_terms($raw_query);
		$this->log(sprintf('Parsed terms: [%s]', implode(', ', $terms)));

		$terms = $this->auto_correct_terms($terms);

		if (empty($terms)) {
			$this->log('Search – no terms after correction');
			return array();
		}

		$post_ids = Faranux_Search_DB::instance()->find_post_ids_for_terms($terms);
		$this->log(sprintf('Search – found %d post IDs', count($post_ids)));

		return $post_ids;
	}

	public function enforce_strict_and_search($where, $query)
	{
		if (is_admin()) {
			return $where;
		}

		if (!$query->is_search() || !$query->is_main_query()) {
			return $where;
		}

		$raw_terms = trim((string) $query->get('s'));
		if ('' === $raw_terms) {
			return $where;
		}

		$queried_types = (array) $query->get('post_type');
		if (empty($queried_types) || $queried_types === [''] || in_array('any', $queried_types, true)) {
			$queried_types = $this->get_indexer_post_types();
		}

		if (!array_intersect($queried_types, $this->get_indexer_post_types())) {
			return $where;
		}

		$this->log(sprintf('enforce_strict_and_search – raw query: "%s"', $raw_terms));

		$terms = $this->parse_terms($raw_terms);
		$terms = $this->auto_correct_terms($terms);

		if (empty($terms)) {
			return $where;
		}

		$post_ids = Faranux_Search_DB::instance()->find_post_ids_for_terms($terms);

		if (empty($post_ids)) {
			global $wpdb;
			$this->log('enforce_strict_and_search – no matches, forcing empty result');
			return $where . " AND {$wpdb->posts}.ID = 0";
		}

		global $wpdb;
		$id_list = implode(',', array_map('intval', $post_ids));
		$this->log(sprintf('enforce_strict_and_search – filtering to %d post IDs', count($post_ids)));

		return $where . " AND {$wpdb->posts}.ID IN ({$id_list})";
	}

	public function apply_to_woo_product_query($query)
	{
		if (!$query->is_main_query()) {
			return;
		}

		$raw_terms = trim((string) $query->get('s'));
		if ('' === $raw_terms) {
			return;
		}

		$this->log(sprintf('apply_to_woo_product_query – raw query: "%s"', $raw_terms));

		$terms = $this->parse_terms($raw_terms);
		$terms = $this->auto_correct_terms($terms);

		if (empty($terms)) {
			return;
		}

		$post_ids = Faranux_Search_DB::instance()->find_post_ids_for_terms($terms);

		if (empty($post_ids)) {
			$query->set('post__in', array(0));
			$this->log('apply_to_woo_product_query – no matches, setting post__in=[0]');
			return;
		}

		$existing = $query->get('post__in');
		if (!empty($existing)) {
			$post_ids = array_intersect($post_ids, (array) $existing);
			if (empty($post_ids)) {
				$post_ids = array(0);
			}
		}

		$query->set('post__in', $post_ids);

		$orderby = isset($_GET['orderby']) ? wc_clean(wp_unslash($_GET['orderby'])) : 'relevance';
		if ('relevance' === $orderby) {
			add_filter('posts_orderby', function ($orderby_sql, $wp_query) use ($raw_terms) {
				global $wpdb;
				remove_filter('posts_orderby', __FUNCTION__);

				$clean_term = esc_sql($wpdb->esc_like($raw_terms));
				return "( {$wpdb->posts}.post_title LIKE '%{$clean_term}%' ) DESC, {$wpdb->posts}.post_title ASC";
			}, 10, 2);
		}

		$this->log(sprintf('apply_to_woo_product_query – set post__in to %d IDs', count($post_ids)));
	}

	private function get_indexer_post_types()
	{
		return Faranux_Search_Indexer::instance()->get_supported_post_types();
	}

	private function parse_terms($raw)
	{
		$raw = sanitize_text_field(wp_unslash($raw));
		$raw = strtolower(trim($raw));

		if ('' === $raw) {
			return array();
		}

		// Fetch and build active Stop Words list
		$default_stops = 'a, also, am, an, and, are, as, at, be, but, by, call, can, co, con, de, do, due, eg, eight, etc, even, ever, every, for, from, full, go, had, has, hasnt, have, he, hence, her, here, his, how, ie, if, in, inc, into, is, it, its, ltd, me, my, no, none, nor, not, now, of, off, on, once, one, only, onto, or, our, ours, out, over, own, part, per, put, re, see, so, some, ten, than, that, the, their, there, these, they, this, three, thru, thus, to, too, top, un, up, us, very, via, was, we, well, were, what, when, where, who, why, will';
		$stop_words_raw = get_option('faranux_stop_words', $default_stops);
		$stop_words = array_filter(array_map('trim', explode(',', strtolower($stop_words_raw))));

		$terms = preg_split('/\s+/', $raw);

		// Filter out stop words from the active query
		$terms = array_values(array_unique(array_filter($terms, function ($term) use ($stop_words) {
			return strlen($term) >= 2 && !in_array($term, $stop_words, true);
		})));

		$this->log(sprintf('parse_terms – raw="%s" → terms=[%s]', $raw, implode(', ', $terms)));

		return $terms;
	}
}