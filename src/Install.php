<?php
/**
 * Activation hook + idempotent upgrade routine.
 *
 * @package SendSMS\Dashboard
 */

namespace SendSMS\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and migrates the plugin's custom tables.
 *
 * Schema changes require bumping SENDSMS_DASHBOARD_DB_VERSION so existing
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
		$stored = get_option( 'sendsms_dashboard_db_version' );
		if ( ! $force && SENDSMS_DASHBOARD_DB_VERSION === $stored ) {
			return;
		}

		self::run_dbdelta();
		self::seed_defaults();

		update_option( 'sendsms_dashboard_db_version', SENDSMS_DASHBOARD_DB_VERSION, false );
	}

	/**
	 * Create / upgrade all plugin tables via dbDelta.
	 *
	 * @return void
	 */
	private static function run_dbdelta(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( \SendSMS\Dashboard\Storage\HistoryRepository::dbdelta_sql() );
	}

	/**
	 * Seed default plugin settings on a fresh install.
	 *
	 * @return void
	 */
	private static function seed_defaults(): void {
		if ( false === get_option( 'sendsms_dashboard_plugin_settings' ) ) {
			add_option(
				'sendsms_dashboard_plugin_settings',
				array(
					'cc' => 'RO',
				),
				'',
				false
			);
		}
	}
}
