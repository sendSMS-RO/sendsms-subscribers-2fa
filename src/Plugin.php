<?php
/**
 * Top-level plugin loader.
 *
 * @package SendSMS\Dashboard
 */

namespace SendSMS\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin loader.
 *
 * Wires all subsystems together and registers WordPress hooks.
 * Use {@see Plugin::instance()} to obtain the singleton.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether {@see boot()} has run already.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {}

	/**
	 * Singleton accessor.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register all hooks. Idempotent.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action(
			'init',
			static function () {
				load_plugin_textdomain(
					'sendsms-dashboard',
					false,
					dirname( SENDSMS_DASHBOARD_BASENAME ) . '/languages'
				);
			}
		);

		Install::maybe_upgrade();
	}
}
