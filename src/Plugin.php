<?php
/**
 * Top-level plugin loader.
 *
 * @package Rosendsms\Dashboard
 */

namespace Rosendsms\Dashboard;

use Rosendsms\Dashboard\Admin;
use Rosendsms\Dashboard\Ajax;
use Rosendsms\Dashboard\Api;
use Rosendsms\Dashboard\Auth;
use Rosendsms\Dashboard\Frontend;
use Rosendsms\Dashboard\Storage;
use Rosendsms\Dashboard\Support;
use Rosendsms\Dashboard\Widgets;

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

		// Translations load just-in-time on WordPress 6.0+ (the plugin minimum);
		// no manual load_plugin_textdomain() call is needed for a WordPress.org-hosted plugin.
		Install::maybe_upgrade();

		$this->settings    = new Storage\Settings();
		$this->history     = new Storage\HistoryRepository();
		$this->subscribers = new Storage\SubscriberRepository();
		$this->ips         = new Storage\IpRepository();

		$codes     = new Support\VerificationCode();
		$this->api = new Api\Client( $this->history, $this->settings, $codes );

		// Public AJAX handlers — registered outside is_admin() so they are reachable
		// from wp-admin/admin-ajax.php for both logged-in and guest (nopriv) contexts.
		( new Frontend\SubscribeAjax( $this->settings, $this->subscribers, $this->ips, $codes, $this->api ) )->register();
		( new Frontend\UnsubscribeAjax( $this->settings, $this->subscribers, $this->ips, $codes, $this->api ) )->register();
		( new Frontend\VerifyCodeAjax( $this->settings, $this->subscribers, $this->ips, $codes, $this->api ) )->register();

		// 2FA subsystem — registered outside is_admin() because the `authenticate`
		// filter fires on wp-login.php POST, which is not an admin context.
		// UserPhoneField hooks into profile screens (which are admin), but
		// WordPress still fires those actions when is_admin() is true, so it is
		// safe to register all three here unconditionally when the feature is on.
		if ( (bool) $this->settings->get( 'add_phone_field', false ) ) {
			$pending = new Auth\PendingLogin();
			( new Auth\UserPhoneField( $this->settings ) )->register();
			( new Auth\TwoFactor( $this->settings, $this->api, $codes, $this->ips, $pending ) )->register();
		}

		// Register widgets — must be outside is_admin() so they are available on
		// the frontend. WordPress fires widgets_init on every request.
		add_action(
			'widgets_init',
			static function () {
				register_widget( Widgets\SubscribeWidget::class );
				register_widget( Widgets\UnsubscribeWidget::class );
			}
		);

		// Shortcodes [sendsms_subscribe] / [sendsms_unsubscribe] — for block
		// themes (and any content) where the Legacy Widget block isn't
		// available.
		$shortcodes = new Frontend\Shortcodes();
		$shortcodes->register();

		// Gutenberg blocks sendsms-dashboard/subscribe + /unsubscribe — share
		// the shortcode renderer so all three (widget, shortcode, block) emit
		// identical markup.
		( new Frontend\Blocks( $shortcodes ) )->register();

		// Enqueue public stylesheet and script (front-end only).
		add_action(
			'wp_enqueue_scripts',
			static function () {
				wp_enqueue_style(
					'rosendsms-dash-public',
					ROSENDSMS_DASH_URL . 'assets/css/public.css',
					array(),
					ROSENDSMS_DASH_VERSION
				);

				wp_register_script(
					'rosendsms-dash-public',
					ROSENDSMS_DASH_URL . 'assets/js/public.js',
					array(),
					ROSENDSMS_DASH_VERSION,
					true
				);

				wp_localize_script(
					'rosendsms-dash-public',
					'rosendsmsDashPublic',
					array(
						'ajaxUrl' => admin_url( 'admin-ajax.php' ),
						'nonce'   => wp_create_nonce( 'rosendsms_dash_nonce' ),
						'i18n'    => array(
							'sending'  => __( 'Sending…', 'sendsms-subscribers-2fa' ),
							'success'  => __( 'Thank you!', 'sendsms-subscribers-2fa' ),
							'fail'     => __( 'Something went wrong. Please try again.', 'sendsms-subscribers-2fa' ),
							'codeSent' => __( 'Check your phone for the verification code.', 'sendsms-subscribers-2fa' ),
						),
					)
				);

				wp_enqueue_script( 'rosendsms-dash-public' );
			}
		);

		if ( is_admin() ) {
			( new Admin\Notices() )->register();
			( new Admin\Menu( $this->settings, $this->api ) )->register();
			( new Ajax\TestSendHandler( $this->api ) )->register();
			( new Ajax\MassSendHandler( $this->settings, $this->subscribers, $this->api ) )->register();
			( new Ajax\SubscriberCrudHandler( $this->settings, $this->subscribers ) )->register();
			( new Ajax\ContactSyncHandler( $this->settings, $this->subscribers, $this->api ) )->register();
		}
	}
}
