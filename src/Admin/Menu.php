<?php
/**
 * Admin menu registration and asset enqueueing.
 *
 * Registers the top-level "SendSMS Dashboard" menu and five submenus,
 * captures their screen IDs, and enqueues the admin stylesheet and
 * script only on those screens.
 *
 * @package SendSMS\Dashboard\Admin
 */

namespace SendSMS\Dashboard\Admin;

use SendSMS\Dashboard\Admin\Pages;
use SendSMS\Dashboard\Storage\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Admin menu and asset loader.
 *
 * Registers the top-level menu and 5 submenus, enqueues admin assets
 * only on those screens, and renders placeholder pages for each item
 * until the dedicated Page classes are wired in by later tasks.
 */
final class Menu {

	/**
	 * Top-level menu slug.
	 *
	 * @var string
	 */
	public const SLUG = 'sendsms-dashboard';

	/**
	 * Shared plugin settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Captured screen IDs returned by add_menu_page / add_submenu_page.
	 *
	 * Used to restrict asset enqueuing to plugin-owned screens only.
	 *
	 * @var string[]
	 */
	private $screen_ids = array();

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Shared plugin settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Attach WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the top-level menu page and all five submenu pages.
	 *
	 * Captures the hook suffix (screen ID) returned by each registration
	 * call so that {@see enqueue_assets()} can limit enqueueing to these
	 * screens only.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$cap = 'manage_options';

		$top = add_menu_page(
			__( 'SendSMS Dashboard', 'sendsms-dashboard' ),
			__( 'SendSMS Dashboard', 'sendsms-dashboard' ),
			$cap,
			self::SLUG,
			array( $this, 'render_settings' ),
			'dashicons-email-alt',
			55
		);
		if ( $top ) {
			$this->screen_ids[] = $top;
		}

		$screens = array(
			array( self::SLUG, __( 'Settings', 'sendsms-dashboard' ), __( 'Settings', 'sendsms-dashboard' ), array( $this, 'render_settings' ) ),
			array( self::SLUG . '-test', __( 'Send a test SMS', 'sendsms-dashboard' ), __( 'Send a test SMS', 'sendsms-dashboard' ), array( $this, 'render_test' ) ),
			array( self::SLUG . '-history', __( 'History', 'sendsms-dashboard' ), __( 'History', 'sendsms-dashboard' ), array( $this, 'render_history' ) ),
			array( self::SLUG . '-subscribers', __( 'Subscribers', 'sendsms-dashboard' ), __( 'Subscribers', 'sendsms-dashboard' ), array( $this, 'render_subscribers' ) ),
			array( self::SLUG . '-mass-send', __( 'SMS sending', 'sendsms-dashboard' ), __( 'SMS sending', 'sendsms-dashboard' ), array( $this, 'render_mass_send' ) ),
		);

		foreach ( $screens as $row ) {
			$screen = add_submenu_page( self::SLUG, $row[1], $row[2], $cap, $row[0], $row[3] );
			if ( $screen ) {
				$this->screen_ids[] = $screen;
			}
		}
	}

	/**
	 * Enqueue the admin stylesheet, script, and JS object on plugin screens.
	 *
	 * Bails early when the current admin hook suffix is not one of the
	 * screen IDs captured during {@see add_menu()}.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, $this->screen_ids, true ) ) {
			return;
		}

		wp_enqueue_style(
			'sendsms-dashboard-admin',
			SENDSMS_DASHBOARD_URL . 'assets/css/admin.css',
			array(),
			SENDSMS_DASHBOARD_VERSION
		);

		wp_enqueue_script(
			'sendsms-dashboard-admin',
			SENDSMS_DASHBOARD_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			SENDSMS_DASHBOARD_VERSION,
			true
		);

		wp_localize_script(
			'sendsms-dashboard-admin',
			'sendsmsDashboard',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'sendsms-security-nonce' ),
				'i18n'    => array(
					'sending'    => __( 'Sending…', 'sendsms-dashboard' ),
					'sent'       => __( 'Sent.', 'sendsms-dashboard' ),
					'failed'     => __( 'Failed.', 'sendsms-dashboard' ),
					'confirmDel' => __( 'Delete this subscriber?', 'sendsms-dashboard' ),
				),
			)
		);
	}

	/**
	 * Render the Settings page.
	 *
	 * Delegates to the SettingsPage class which handles three tabs
	 * (General / User / Subscription) with per-tab merge-save.
	 *
	 * @return void
	 */
	public function render_settings(): void {
		( new Pages\SettingsPage( $this->settings ) )->render();
	}

	/**
	 * Render the Send a test SMS page placeholder.
	 *
	 * @return void
	 */
	public function render_test(): void {
		$this->coming_soon( __( 'Send a test SMS', 'sendsms-dashboard' ) );
	}

	/**
	 * Render the SMS History page.
	 *
	 * Delegates to {@see Pages\HistoryPage} which owns the WP_List_Table
	 * subclass and all data-fetching logic.
	 *
	 * @return void
	 */
	public function render_history(): void {
		( new Pages\HistoryPage() )->render();
	}

	/**
	 * Render the Subscribers page placeholder.
	 *
	 * @return void
	 */
	public function render_subscribers(): void {
		$this->coming_soon( __( 'Subscribers', 'sendsms-dashboard' ) );
	}

	/**
	 * Render the SMS sending page placeholder.
	 *
	 * @return void
	 */
	public function render_mass_send(): void {
		$this->coming_soon( __( 'SMS sending', 'sendsms-dashboard' ) );
	}

	/**
	 * Output a minimal "Coming soon" admin page.
	 *
	 * Performs a capability check before rendering so that direct URL
	 * access by unprivileged users is harmless.
	 *
	 * @param string $title Page title shown in the <h1>.
	 * @return void
	 */
	private function coming_soon( string $title ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html__( 'Coming soon.', 'sendsms-dashboard' ) . '</p></div>';
	}
}
