<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package FlowSell_AI
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 1. Delete Options
delete_option( 'flowsell_settings' );
delete_option( 'flowsell_active_flow' );
delete_option( 'flowsell_db_version' );

// 2. Clear WP-Cron hooks
wp_clear_scheduled_hook( 'flowsell_purge_sessions_cron' );

// 3. Drop Custom Database Table
global $wpdb;
$table_name = $wpdb->prefix . 'flowsell_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
