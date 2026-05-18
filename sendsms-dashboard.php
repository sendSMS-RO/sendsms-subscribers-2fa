<?php
/**
 * Plugin Name:       SendSMS Dashboard
 * Plugin URI:        https://www.sendsms.ro/en/
 * Description:       Manage SMS subscribers, send mass campaigns, and protect wp-admin with SMS-based 2FA through the sendsms.ro gateway.
 * Version:           2.0.0
 * Requires at least: 4.0
 * Requires PHP:      7.4
 * Author:            sendSMS
 * Author URI:        https://www.sendsms.ro/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sendsms-dashboard
 * Domain Path:       /languages
 *
 * @package SendSMS\Dashboard
 */

defined( 'ABSPATH' ) || exit;

define( 'SENDSMS_DASHBOARD_VERSION', '2.0.0' );
define( 'SENDSMS_DASHBOARD_DB_VERSION', '1.0.0' );
define( 'SENDSMS_DASHBOARD_FILE', __FILE__ );
define( 'SENDSMS_DASHBOARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'SENDSMS_DASHBOARD_URL', plugin_dir_url( __FILE__ ) );
define( 'SENDSMS_DASHBOARD_BASENAME', plugin_basename( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'SendSMS\\Dashboard\\';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$path     = SENDSMS_DASHBOARD_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'SendSMS\\Dashboard\\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SendSMS\\Dashboard\\Install', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\SendSMS\Dashboard\Plugin::instance()->boot();
	}
);
