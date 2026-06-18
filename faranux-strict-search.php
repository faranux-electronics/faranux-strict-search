<?php
/**
 * Plugin Name: Faranux Strict Search
 * Plugin URI: https://example.com/faranux-strict-search
 * Description: Enforces strict AND search logic for WordPress content and adds a search widget.
 * Version: 1.0.0
 * Author: Faranux
 * Text Domain: faranux-strict-search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FARANUX_SEARCH_VERSION', '1.0.0' );
define( 'FARANUX_SEARCH_PLUGIN_FILE', __FILE__ );
define( 'FARANUX_SEARCH_PATH', plugin_dir_path( __FILE__ ) );
define( 'FARANUX_SEARCH_URL', plugin_dir_url( __FILE__ ) );

require_once FARANUX_SEARCH_PATH . 'includes/class-faranux-search-db.php';
require_once FARANUX_SEARCH_PATH . 'includes/class-faranux-search-indexer.php';
require_once FARANUX_SEARCH_PATH . 'includes/class-faranux-search-engine.php';
require_once FARANUX_SEARCH_PATH . 'public/class-faranux-search-widget.php';

register_activation_hook( __FILE__, array( 'Faranux_Search_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Faranux_Search_DB', 'deactivate' ) );

add_action( 'plugins_loaded', 'faranux_strict_search_bootstrap' );

function faranux_strict_search_bootstrap() {
	Faranux_Search_DB::instance();
	Faranux_Search_Indexer::instance();
	Faranux_Search_Engine::instance();

	add_action( 'widgets_init', function() {
		register_widget( 'Faranux_Search_Widget' );
	} );
}
