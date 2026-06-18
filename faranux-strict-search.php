<?php
/**
 * Plugin Name: Faranux Strict Search
 * Plugin URI:  https://faranux.com
 * Description: Enforces strict AND search logic, NLP scrubbing, Title-First sorting, and Stop Words.
 * Version:     1.13.0
 * Author:      Faranux
 * Text Domain: faranux-strict-search
 */

if (!defined('ABSPATH')) {
	exit;
}

define('FARANUX_SEARCH_VERSION', '1.13.0');
define('FARANUX_SEARCH_PLUGIN_FILE', __FILE__);
define('FARANUX_SEARCH_PATH', plugin_dir_path(__FILE__));
define('FARANUX_SEARCH_URL', plugin_dir_url(__FILE__));

require_once FARANUX_SEARCH_PATH . 'includes/class-faranux-search-db.php';
require_once FARANUX_SEARCH_PATH . 'includes/class-faranux-search-indexer.php';
require_once FARANUX_SEARCH_PATH . 'includes/class-faranux-search-engine.php';
require_once FARANUX_SEARCH_PATH . 'includes/class-faranux-search-admin.php';
require_once FARANUX_SEARCH_PATH . 'public/class-faranux-search-widget.php';

register_activation_hook(__FILE__, array('Faranux_Search_DB', 'activate'));
register_deactivation_hook(__FILE__, array('Faranux_Search_DB', 'deactivate'));

add_action('plugins_loaded', 'faranux_strict_search_bootstrap');

function faranux_strict_search_bootstrap()
{
	add_action('admin_init', 'faranux_register_search_settings');

	Faranux_Search_DB::instance();
	Faranux_Search_Indexer::instance();
	Faranux_Search_Engine::instance();
	new Faranux_Search_Admin();

	add_filter('faranux_strict_search_supported_post_types', function () {
		return ['product'];
	});

	add_action('widgets_init', function () {
		register_widget('Faranux_Search_Widget');
	});

	add_action('wp_enqueue_scripts', 'faranux_enqueue_assets');

	add_action('wp_ajax_faranux_strict_search_ajax', 'faranux_handle_ajax_search');
	add_action('wp_ajax_nopriv_faranux_strict_search_ajax', 'faranux_handle_ajax_search');
}

function faranux_register_search_settings()
{
	register_setting('faranux_search_settings', 'faranux_index_fields', [
		'default' => ['title' => 1, 'content' => 1, 'excerpt' => 0, 'sku' => 1, 'attributes' => 1],
		'sanitize_callback' => 'faranux_sanitize_index_fields'
	]);
	register_setting('faranux_search_settings', 'faranux_enable_logging', ['default' => 0]);

	$default_stops = 'a, also, am, an, and, are, as, at, be, but, by, call, can, co, con, de, do, due, eg, eight, etc, even, ever, every, for, from, full, go, had, has, hasnt, have, he, hence, her, here, his, how, ie, if, in, inc, into, is, it, its, ltd, me, my, no, none, nor, not, now, of, off, on, once, one, only, onto, or, our, ours, out, over, own, part, per, put, re, see, so, some, ten, than, that, the, their, there, these, they, this, three, thru, thus, to, too, top, un, up, us, very, via, was, we, well, were, what, when, where, who, why, will';
	register_setting('faranux_search_settings', 'faranux_stop_words', [
		'default' => $default_stops,
		'sanitize_callback' => 'sanitize_textarea_field'
	]);

	register_setting('faranux_search_settings', 'faranux_max_results', [
		'default' => 12,
		'sanitize_callback' => 'absint'
	]);
}

function faranux_sanitize_index_fields($input)
{
	if (!is_array($input))
		return [];
	$allowed = ['title', 'content', 'excerpt', 'sku', 'attributes'];
	$sanitized = [];
	foreach ($allowed as $key) {
		$sanitized[$key] = isset($input[$key]) ? 1 : 0;
	}
	return $sanitized;
}

function faranux_enqueue_assets()
{
	wp_enqueue_style('faranux-search', FARANUX_SEARCH_URL . 'public/css/faranux-search.css', array(), FARANUX_SEARCH_VERSION);
	wp_enqueue_script('faranux-search-ajax', FARANUX_SEARCH_URL . 'public/js/faranux-search-ajax.js', array('jquery'), FARANUX_SEARCH_VERSION, true);
	wp_localize_script('faranux-search-ajax', 'faranuxSearch', array(
		'ajaxurl' => admin_url('admin-ajax.php'),
		'nonce' => wp_create_nonce('faranux_search_nonce'),
		'i18n' => array('searching' => esc_html__('Searching…', 'faranux-strict-search')),
	));
}

function faranux_handle_ajax_search()
{
	check_ajax_referer('faranux_search_nonce', 'nonce');

	$raw = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
	$type = isset($_POST['search_type']) && $_POST['search_type'] === 'post' ? 'post' : 'product';

	if (get_option('faranux_enable_logging', 0) && function_exists('wc_get_logger')) {
		$logger = wc_get_logger();
		$logger->debug(sprintf('AJAX search: query="%s", type="%s"', $raw, $type), ['source' => 'faranux-search']);
	}

	if ('' === trim($raw)) {
		wp_send_json_error(array('message' => 'Empty query.'));
	}

	$engine = Faranux_Search_Engine::instance();
	$post_ids = $engine->search($raw);

	if (get_option('faranux_enable_logging', 0) && function_exists('wc_get_logger')) {
		$logger = wc_get_logger();
		$logger->debug(sprintf('AJAX search results: found %d post IDs', count($post_ids)), ['source' => 'faranux-search']);
	}

	if (empty($post_ids)) {
		wp_send_json_success('<p class="faranux-no-results">' . esc_html__('No matches found.', 'faranux-strict-search') . '</p>');
	}

	$max_results = absint(get_option('faranux_max_results', 12));

	$posts = get_posts(array(
		'post__in' => $post_ids,
		'post_type' => $type,
		'post_status' => 'publish',
		'posts_per_page' => 150, // Fetch a wide net so the true Title match isn't buried
	));

	// THE TITLE-FIRST TRICK: Forces products matching the search in their Title to the top
	$search_query_lower = strtolower($raw);
	usort($posts, function ($a, $b) use ($search_query_lower) {
		$a_in_title = strpos(strtolower($a->post_title), $search_query_lower) !== false ? 1 : 0;
		$b_in_title = strpos(strtolower($b->post_title), $search_query_lower) !== false ? 1 : 0;

		if ($a_in_title !== $b_in_title) {
			return $b_in_title - $a_in_title;
		}
		return strcmp($a->post_title, $b->post_title);
	});

	// Slice down to the user's max results setting
	$posts = array_slice($posts, 0, $max_results);

	if (empty($posts)) {
		wp_send_json_success('<p class="faranux-no-results">' . esc_html__('No matches in this category.', 'faranux-strict-search') . '</p>');
	}

	ob_start();
	echo '<ul class="faranux-results-list layout-' . esc_attr($type) . '">';
	foreach ($posts as $post) {
		$url = get_permalink($post->ID);
		$title = get_the_title($post->ID);

		if ($type === 'product') {
			$thumb = has_post_thumbnail($post->ID) ? get_the_post_thumbnail($post->ID, array(40, 40)) : '';
			$price = '';
			if (function_exists('wc_get_product')) {
				$product = wc_get_product($post->ID);
				if ($product)
					$price = $product->get_price_html();
			}
			printf(
				'<li class="faranux-result-item"><a href="%s">%s<div class="faranux-meta"><span class="faranux-result-title">%s</span>%s</div></a></li>',
				esc_url($url),
				$thumb,
				esc_html($title),
				$price ? '<span class="faranux-result-price">' . wp_kses_post($price) . '</span>' : ''
			);
		} else {
			$date = get_the_date('M j, Y', $post->ID);
			printf(
				'<li class="faranux-result-item"><a href="%s"><div class="faranux-meta"><span class="faranux-result-title">%s</span><span class="faranux-result-excerpt" style="font-size:12px;color:#666;">%s</span></div></a></li>',
				esc_url($url),
				esc_html($title),
				esc_html($date)
			);
		}
	}
	echo '</ul>';
	wp_send_json_success(ob_get_clean());
}