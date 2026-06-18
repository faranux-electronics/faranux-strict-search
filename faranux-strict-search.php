<?php
/**
 * Plugin Name: Faranux Strict Search
 * Plugin URI:  https://faranux.com
 * Description: Enforces strict AND search logic using a custom inverted index for fast, accurate WooCommerce product search.
 * Version:     1.1.0
 * Author:      Faranux
 * Text Domain: faranux-strict-search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FARANUX_SEARCH_VERSION',     '1.1.0' );
define( 'FARANUX_SEARCH_PLUGIN_FILE', __FILE__ );
define( 'FARANUX_SEARCH_PATH',        plugin_dir_path( __FILE__ ) );
define( 'FARANUX_SEARCH_URL',         plugin_dir_url( __FILE__ ) );

require_once FARANUX_SEARCH_PATH . 'includes/class-faranux-search-db.php';
require_once FARANUX_SEARCH_PATH . 'includes/class-faranux-search-indexer.php';
require_once FARANUX_SEARCH_PATH . 'includes/class-faranux-search-engine.php';
require_once FARANUX_SEARCH_PATH . 'public/class-faranux-search-widget.php';

register_activation_hook( __FILE__,   array( 'Faranux_Search_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Faranux_Search_DB', 'deactivate' ) );

add_action( 'plugins_loaded', 'faranux_strict_search_bootstrap' );

function faranux_strict_search_bootstrap() {
	Faranux_Search_DB::instance();
	Faranux_Search_Indexer::instance();
	Faranux_Search_Engine::instance();

	// Register widget.
	add_action( 'widgets_init', function () {
		register_widget( 'Faranux_Search_Widget' );
	} );

	// Enqueue front-end assets.
	add_action( 'wp_enqueue_scripts', 'faranux_enqueue_assets' );

	// AJAX handlers (logged-in and guest).
	add_action( 'wp_ajax_faranux_strict_search_ajax',        'faranux_handle_ajax_search' );
	add_action( 'wp_ajax_nopriv_faranux_strict_search_ajax', 'faranux_handle_ajax_search' );
}

/**
 * Enqueue plugin stylesheet and script with localised config.
 */
function faranux_enqueue_assets() {
	wp_enqueue_style(
		'faranux-search',
		FARANUX_SEARCH_URL . 'public/css/faranux-search.css',
		array(),
		FARANUX_SEARCH_VERSION
	);

	wp_enqueue_script(
		'faranux-search-ajax',
		FARANUX_SEARCH_URL . 'public/js/faranux-search-ajax.js',
		array( 'jquery' ),
		FARANUX_SEARCH_VERSION,
		true // load in footer
	);

	wp_localize_script( 'faranux-search-ajax', 'faranuxSearch', array(
		'ajaxurl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'faranux_search_nonce' ),
		'i18n'    => array(
			'searching' => esc_html__( 'Searching…', 'faranux-strict-search' ),
		),
	) );
}

/**
 * AJAX search handler — returns an HTML list of matching products.
 */
function faranux_handle_ajax_search() {
	// Verify nonce before doing anything else.
	check_ajax_referer( 'faranux_search_nonce', 'nonce' );

	$raw = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';

	if ( '' === trim( $raw ) ) {
		wp_send_json_error( array( 'message' => 'Empty query.' ) );
	}

	$engine  = Faranux_Search_Engine::instance();
	$post_ids = $engine->search( $raw );

	if ( empty( $post_ids ) ) {
		wp_send_json_success( '<p class="faranux-no-results">' . esc_html__( 'No products found.', 'faranux-strict-search' ) . '</p>' );
	}

	$posts = get_posts( array(
		'post__in'       => $post_ids,
		'post_type'      => array( 'post', 'product' ),
		'post_status'    => 'publish',
		'orderby'        => 'post__in',
		'posts_per_page' => 10,
	) );

	ob_start();
	echo '<ul class="faranux-results-list">';
	foreach ( $posts as $post ) {
		$url   = get_permalink( $post->ID );
		$title = get_the_title( $post->ID );
		$thumb = has_post_thumbnail( $post->ID )
			? get_the_post_thumbnail( $post->ID, array( 40, 40 ) )
			: '';

		// Price (WooCommerce only).
		$price = '';
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			if ( $product ) {
				$price = $product->get_price_html();
			}
		}

		printf(
			'<li class="faranux-result-item"><a href="%s">%s<span class="faranux-result-title">%s</span>%s</a></li>',
			esc_url( $url ),
			$thumb,
			esc_html( $title ),
			$price ? '<span class="faranux-result-price">' . wp_kses_post( $price ) . '</span>' : ''
		);
	}
	echo '</ul>';
	$html = ob_get_clean();

	wp_send_json_success( $html );
}
