<?php
/**
 * Activation hook + idempotent upgrade routine.
 *
 * @package Rosendsms\Dashboard
 */

namespace Rosendsms\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and migrates the plugin's custom tables, and renames pre-2.0.1
 * `sendsms_dashboard_*` data to the current `rosendsms_dash_*` names.
 *
 * Schema changes require bumping ROSENDSMS_DASH_DB_VERSION so existing
 * installs re-run dbDelta on next plugin load.
 */
final class Install {

	/**
	 * Activation hook. Fires once when the plugin is activated.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::maybe_upgrade( true );
	}

	/**
	 * Deactivation hook. Intentionally a no-op — data is preserved on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Intentionally a no-op.
	}

	/**
	 * Run the upgrade routine if the stored schema version is outdated.
	 *
	 * @param bool $force Skip the version check and always run.
	 * @return void
	 */
	public static function maybe_upgrade( bool $force = false ): void {
		$stored = get_option( 'rosendsms_dash_db_version' );
		if ( ! $force && ROSENDSMS_DASH_DB_VERSION === $stored ) {
			return;
		}

		self::migrate_legacy();
		self::run_dbdelta();
		self::seed_defaults();

		update_option( 'rosendsms_dash_db_version', ROSENDSMS_DASH_DB_VERSION, false );
	}

	/**
	 * One-shot migration of pre-2.0.1 `sendsms_dashboard_*` data (the names used
	 * by v1.x and v2.0.0) to the current `rosendsms_dash_*` names.
	 *
	 * Migrates option keys and renames the three custom tables. Idempotent:
	 * each step writes only when the new name is still absent / the new table
	 * does not yet exist, so it is safe to re-run on every upgrade.
	 *
	 * @return void
	 */
	private static function migrate_legacy(): void {
		global $wpdb;

		// Option keys: legacy => current.
		$options = array(
			'sendsms_dashboard_plugin_settings' => 'rosendsms_dash_options',
			'sendsms-dashboard-sync-group'      => 'rosendsms_dash_sync_group',
			'sendsms_dashboard_pending_notices' => 'rosendsms_dash_pending_notices',
		);
		foreach ( $options as $old => $new ) {
			$legacy = get_option( $old );
			if ( false !== $legacy && false === get_option( $new, false ) ) {
				update_option( $new, $legacy, false );
			}
		}

		// Custom tables: legacy suffix => current suffix.
		$tables = array(
			'sendsms_dashboard_history'     => 'rosendsms_dash_history',
			'sendsms_dashboard_subscribers' => 'rosendsms_dash_subscribers',
			'sendsms_dashboard_ip_address'  => 'rosendsms_dash_ip_address',
		);
		foreach ( $tables as $old_suffix => $new_suffix ) {
			$old_table = $wpdb->prefix . $old_suffix;
			$new_table = $wpdb->prefix . $new_suffix;
			if ( $old_table === $new_table ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a literal.
			$old_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) === $old_table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a literal.
			$new_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) ) === $new_table;
			if ( $old_exists && ! $new_exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table identifiers cannot be parameterised; both names are $wpdb->prefix plus class literals verified above.
				$wpdb->query( "RENAME TABLE `$old_table` TO `$new_table`" );
			}
		}
	}

	/**
	 * Create / upgrade all plugin tables via dbDelta.
	 *
	 * @return void
	 */
	private static function run_dbdelta(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( \Rosendsms\Dashboard\Storage\HistoryRepository::dbdelta_sql() );
		dbDelta( \Rosendsms\Dashboard\Storage\SubscriberRepository::dbdelta_sql() );
		dbDelta( \Rosendsms\Dashboard\Storage\IpRepository::dbdelta_sql() );
	}

	/**
	 * Seed default plugin settings on a fresh install.
	 *
	 * @return void
	 */
	private static function seed_defaults(): void {
		if ( false === get_option( 'rosendsms_dash_options' ) ) {
			add_option(
				'rosendsms_dash_options',
				array(
					'cc' => 'INT',
				),
				'',
				false
			);
		}
	}
}
