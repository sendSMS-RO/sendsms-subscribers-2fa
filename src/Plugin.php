<?php
/**
 * Top-level plugin loader.
 *
 * @package SendSMS\Dashboard
 */

namespace SendSMS\Dashboard;

use SendSMS\Dashboard\Admin;
use SendSMS\Dashboard\Ajax;
use SendSMS\Dashboard\Api;
use SendSMS\Dashboard\Storage;
use SendSMS\Dashboard\Support;

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
	 * Shared Settings service, available after {@see boot()} runs.
	 *
	 * @var Storage\Settings|null
	 */
	private $settings = null;

	/**
	 * History repository.
	 *
	 * @var Storage\HistoryRepository|null
	 */
	private $history = null;

	/**
	 * Subscriber repository.
	 *
	 * @var Storage\SubscriberRepository|null
	 */
	private $subscribers = null;

	/**
	 * IP repository.
	 *
	 * @var Storage\IpRepository|null
	 */
	private $ips = null;

	/**
	 * Sendsms.ro API client.
	 *
	 * @var Api\Client|null
	 */
	private $api = null;

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
	 * Returns the shared Settings service.
	 *
	 * @return Storage\Settings
	 */
	public function settings(): Storage\Settings {
		return $this->settings;
	}

	/**
	 * History repository accessor.
	 *
	 * @return Storage\HistoryRepository
	 */
	public function history(): Storage\HistoryRepository {
		return $this->history;
	}

	/**
	 * Subscriber repository accessor.
	 *
	 * @return Storage\SubscriberRepository
	 */
	public function subscribers(): Storage\SubscriberRepository {
		return $this->subscribers;
	}

	/**
	 * IP repository accessor.
	 *
	 * @return Storage\IpRepository
	 */
	public function ips(): Storage\IpRepository {
		return $this->ips;
	}

	/**
	 * API client accessor.
	 *
	 * @return Api\Client
	 */
	public function api(): Api\Client {
		return $this->api;
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

		$this->settings    = new Storage\Settings();
		$this->history     = new Storage\HistoryRepository();
		$this->subscribers = new Storage\SubscriberRepository();
		$this->ips         = new Storage\IpRepository();

		$codes     = new Support\VerificationCode();
		$this->api = new Api\Client( $this->history, $this->settings, $codes );

		if ( is_admin() ) {
			( new Admin\Notices() )->register();
			( new Admin\Menu( $this->settings ) )->register();
			( new Ajax\TestSendHandler( $this->api ) )->register();
		}
	}
}
