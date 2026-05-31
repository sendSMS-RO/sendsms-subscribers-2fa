<?php
/**
 * Plugin Name:       SendSMS Dashboard
 * Plugin URI:        https://www.sendsms.ro/en/
 * Description:       Manage SMS subscribers, send mass campaigns, and protect wp-admin with SMS-based 2FA through the sendsms.ro gateway.
 * Version:           2.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            sendSMS
 * Author URI:        https://www.sendsms.ro/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sendsms-dashboard
 * Domain Path:       /languages
 *
 * @package Rosendsms\Dashboard
 */

defined( 'ABSPATH' ) || exit;

define( 'ROSENDSMS_DASH_VERSION', '2.0.1' );
define( 'ROSENDSMS_DASH_DB_VERSION', '1.1.0' );
define( 'ROSENDSMS_DASH_FILE', __FILE__ );
define( 'ROSENDSMS_DASH_DIR', plugin_dir_path( __FILE__ ) );
define( 'ROSENDSMS_DASH_URL', plugin_dir_url( __FILE__ ) );
define( 'ROSENDSMS_DASH_BASENAME', plugin_basename( __FILE__ ) );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Rosendsms\\Dashboard\\';
		if ( strpos( $class_name, $prefix ) !== 0 ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = ROSENDSMS_DASH_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'Rosendsms\\Dashboard\\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Rosendsms\\Dashboard\\Install', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\Rosendsms\Dashboard\Plugin::instance()->boot();
	}
);
