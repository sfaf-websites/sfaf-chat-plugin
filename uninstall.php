<?php
/**
 * Runs only when an administrator DELETES the plugin from the Plugins screen. It
 * does not run on deactivate or on update.
 *
 * Deleting means gone: the API key must not outlive the plugin in the database,
 * and neither must question logs, which could otherwise sit past the 60-day limit
 * with no job left to prune them.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$sfaf_chat_table = $wpdb->prefix . 'sfaf_chat_log';
$wpdb->query( "DROP TABLE IF EXISTS `$sfaf_chat_table`" );

foreach ( array(
    'sfaf_chat_credentials',
    'sfaf_chat_settings',
    'sfaf_chat_pinned',
    'sfaf_chat_appearance',
    'sfaf_chat_starters',
    'sfaf_chat_models',
    'sfaf_chat_index_state',
    'sfaf_chat_alerts',
    'sfaf_chat_installed_version',
) as $sfaf_chat_option ) {
    delete_option( $sfaf_chat_option );
}

delete_site_transient( 'sfaf_chat_updater_release' );
wp_clear_scheduled_hook( 'sfaf_chat_prune_logs' );

$sfaf_chat_uploads = wp_upload_dir( null, false );
$sfaf_chat_dir     = trailingslashit( $sfaf_chat_uploads['basedir'] ) . 'sfaf-chat';
if ( is_dir( $sfaf_chat_dir ) ) {
    foreach ( array( 'page-index.json', 'page-index.json.tmp', 'indexer.lock', '.htaccess', 'index.html' ) as $sfaf_chat_file ) {
        @unlink( $sfaf_chat_dir . '/' . $sfaf_chat_file );
    }
    @rmdir( $sfaf_chat_dir );
}
