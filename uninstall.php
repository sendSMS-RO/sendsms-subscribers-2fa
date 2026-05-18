<?php
/**
 * Fires when the plugin is deleted from wp-admin.
 *
 * Drops plugin tables and options only when the admin opted in via the
 * "Clean uninstall" setting; otherwise leaves data intact so reinstalls
 * pick up where they left off.
 *
 * @package SendSMS\Dashboard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- local scope
$sendsms_settings = get_option( 'sendsms_dashboard_plugin_settings', array() );
if ( empty( $sendsms_settings['clean_uninstall'] ) ) {
	return;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- local scope
$sendsms_tables = array(
	$wpdb->prefix . 'sendsms_dashboard_history',
	$wpdb->prefix . 'sendsms_dashboard_subscribers',
	$wpdb->prefix . 'sendsms_dashboard_ip_address',
);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- loop scope
foreach ( $sendsms_tables as $sendsms_table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall cleanup uses literal table names for schema deletion
	$wpdb->query( "DROP TABLE IF EXISTS {$sendsms_table}" );
}

delete_option( 'sendsms_dashboard_plugin_settings' );
delete_option( 'sendsms_dashboard_db_version' );
delete_option( 'sendsms_dashboard_pending_notices' );
delete_option( 'sendsms-dashboard-sync-group' );
