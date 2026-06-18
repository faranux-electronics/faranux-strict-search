<?php
/**
 * Faranux Strict Search — uninstall routine.
 *
 * Runs when the plugin is deleted from the WordPress admin.
 * Removes the inverted-index table and all plugin options.
 *
 * @package Faranux_Strict_Search
 */

// Bail if this file is accessed directly or not via WordPress uninstall flow.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the inverted-index table.
$table_name = $wpdb->prefix . 'faranux_search_index';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

// Remove stored plugin options.
delete_option( 'faranux_search_db_version' );
