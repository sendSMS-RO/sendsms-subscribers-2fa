<?php
/**
 * Fires when the plugin is deleted from wp-admin.
 *
 * Drops plugin tables and options only when the admin opted in via the
 * "Clean uninstall" setting; otherwise leaves data intact so reinstalls
 * pick up where they left off. Also removes any pre-2.0.1
 * `sendsms_dashboard_*` leftovers that an interrupted migration may have
 * left behind.
 *
 * @package Rosendsms\Dashboard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$rosendsms_dash_settings = get_option( 'rosendsms_dash_options', array() );
if ( empty( $rosendsms_dash_settings['clean_uninstall'] ) ) {
	return;
}

// Current tables plus any pre-2.0.1 `sendsms_dashboard_*` leftovers.
$rosendsms_dash_tables = array(
	$wpdb->prefix . 'rosendsms_dash_history',
	$wpdb->prefix . 'rosendsms_dash_subscribers',
	$wpdb->prefix . 'rosendsms_dash_ip_address',
	$wpdb->prefix . 'sendsms_dashboard_history',
	$wpdb->prefix . 'sendsms_dashboard_subscribers',
	$wpdb->prefix . 'sendsms_dashboard_ip_address',
);
foreach ( $rosendsms_dash_tables as $rosendsms_dash_table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall cleanup uses literal table names for schema deletion
	$wpdb->query( "DROP TABLE IF EXISTS {$rosendsms_dash_table}" );
}

// Current options + transient.
delete_option( 'rosendsms_dash_options' );
delete_option( 'rosendsms_dash_db_version' );
delete_option( 'rosendsms_dash_pending_notices' );
delete_option( 'rosendsms_dash_sync_group' );
delete_transient( 'rosendsms_dash_balance' );

// Pre-2.0.1 leftovers.
delete_option( 'sendsms_dashboard_plugin_settings' );
delete_option( 'sendsms_dashboard_db_version' );
delete_option( 'sendsms_dashboard_pending_notices' );
delete_option( 'sendsms-dashboard-sync-group' );
delete_transient( 'sendsms_dashboard_balance' );
