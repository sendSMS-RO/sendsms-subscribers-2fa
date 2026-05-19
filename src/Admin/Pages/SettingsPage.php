<?php
/**
 * Settings admin page with three tabs and per-tab merge-save.
 *
 * Renders the SendSMS Dashboard settings across three tabs — General, User,
 * and Subscription — and saves each tab independently via
 * {@see \SendSMS\Dashboard\Storage\Settings::update_partial()} so that saving
 * one tab never overwrites values entered on another tab.
 *
 * This fixes the cross-tab-wipe bug present in v1.x, where a single
 * `options.php` form submission overwrote the entire option with only the
 * fields visible in the active tab.
 *
 * @package SendSMS\Dashboard\Admin\Pages
 */

namespace SendSMS\Dashboard\Admin\Pages;

use SendSMS\Dashboard\Api\Client;
use SendSMS\Dashboard\Storage\Settings;
use SendSMS\Dashboard\Support\CountryCodes;

defined( 'ABSPATH' ) || exit;

/**
 * Settings page renderer.
 *
 * Displays and handles the three-tab settings form. Every public method that
 * outputs HTML must escape every dynamic value. Save methods call
 * {@see Settings::update_partial()} with only the keys that belong to the
 * active tab, leaving the others untouched.
 *
 * Setting keys deliberately match v1.x `sendsms_dashboard_plugin_settings`
 * array keys so existing installations carry their configuration over without
 * any migration script.
 */
final class SettingsPage {

	/**
	 * Valid tab slugs.
	 *
	 * @var string[]
	 */
	private const TABS = array( 'general', 'user', 'subscription' );

	/**
	 * Nonce action used to validate form submissions.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'sendsms_dashboard_settings_save';

	/**
	 * Nonce field name embedded in every tab form.
	 *
	 * @var string
	 */
	private const NONCE_FIELD = '_sendsms_nonce';

	/**
	 * Transient key for the cached account balance.
	 *
	 * @var string
	 */
	private const BALANCE_TRANSIENT = 'sendsms_dashboard_balance';

	/**
	 * Plugin settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * SendSMS.ro API client.
	 *
	 * @var Client
	 */
	private $api;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Shared plugin settings service.
	 * @param Client   $api      sendsms.ro API client (for the balance banner).
	 */
	public function __construct( Settings $settings, Client $api ) {
		$this->settings = $settings;
		$this->api      = $api;
	}

	/**
	 * Render the full settings page.
	 *
	 * Guards against non-admins, determines the active tab, handles a POST
	 * submission for that tab, then prints the tab navigation and active form.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab navigation only; no data mutation here.
		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		if ( ! in_array( $active, self::TABS, true ) ) {
			$active = 'general';
		}

		if ( isset( $_POST['sendsms_dashboard_settings_save'] ) ) {
			check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );
			$tab = sanitize_key( wp_unslash( $_POST['sendsms_dashboard_settings_save'] ) );

			if ( 'general' === $tab ) {
				$this->save_general();
			}
			if ( 'user' === $tab ) {
				$this->save_user();
			}
			if ( 'subscription' === $tab ) {
				$this->save_subscription();
			}

			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Settings saved.', 'sendsms-dashboard' )
				. '</p></div>';
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'SendSMS Dashboard', 'sendsms-dashboard' ) . '</h1>';
		$this->render_balance_banner();
		$this->render_tabs( $active );

		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<input type="hidden" name="sendsms_dashboard_settings_save" value="' . esc_attr( $active ) . '" />';

		if ( 'general' === $active ) {
			$this->render_general();
		}
		if ( 'user' === $active ) {
			$this->render_user();
		}
		if ( 'subscription' === $active ) {
			$this->render_subscription();
		}

		submit_button( __( 'Save Settings', 'sendsms-dashboard' ) );
		echo '</form></div>';
	}

	// -------------------------------------------------------------------------
	// Balance banner
	// -------------------------------------------------------------------------

	/**
	 * Render the "available balance" info bar at the top of the settings page.
	 *
	 * If API credentials are missing, prints a warning prompting the admin to
	 * configure them. Otherwise queries the sendsms.ro `user_get_balance`
	 * endpoint (cached for 5 minutes via transient) and prints the result. On
	 * API failure prints a soft error notice — the settings form remains
	 * accessible regardless.
	 *
	 * @return void
	 */
	private function render_balance_banner(): void {
		$username = trim( $this->settings->get_esc( 'username', '' ) );
		$password = trim( $this->settings->get_esc( 'password', '' ) );

		if ( '' === $username || '' === $password ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'Enter your sendsms.ro username and password under the General tab to see your account balance.', 'sendsms-dashboard' )
			);
			return;
		}

		$balance = get_transient( self::BALANCE_TRANSIENT );

		if ( false === $balance ) {
			$response = $this->api->get_user_balance();
			if ( ! $response->is_success() ) {
				printf(
					'<div class="notice notice-error inline"><p>%s</p></div>',
					esc_html__( 'Could not contact sendsms.ro to retrieve your balance. Check your credentials.', 'sendsms-dashboard' )
				);
				return;
			}
			$data        = $response->data();
			$balance_raw = isset( $data['details'] ) && is_scalar( $data['details'] ) ? (string) $data['details'] : '0';
			$balance     = $balance_raw;
			set_transient( self::BALANCE_TRANSIENT, $balance, 5 * MINUTE_IN_SECONDS );
		}

		printf(
			'<div class="notice notice-info inline"><p>%s <strong>%s</strong> EUR</p></div>',
			esc_html__( 'Available balance:', 'sendsms-dashboard' ),
			esc_html( (string) $balance )
		);
	}

	// -------------------------------------------------------------------------
	// Tab navigation
	// -------------------------------------------------------------------------

	/**
	 * Print the tab navigation bar.
	 *
	 * @param string $active Currently active tab slug.
	 * @return void
	 */
	private function render_tabs( string $active ): void {
		$tabs = array(
			'general'      => __( 'General', 'sendsms-dashboard' ),
			'user'         => __( 'User', 'sendsms-dashboard' ),
			'subscription' => __( 'Subscription', 'sendsms-dashboard' ),
		);

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url          = add_query_arg( 'tab', $slug );
			$active_class = ( $slug === $active ) ? ' nav-tab-active' : '';
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $url ),
				esc_attr( $active_class ),
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	// -------------------------------------------------------------------------
	// General tab
	// -------------------------------------------------------------------------

	/**
	 * Render the General settings tab.
	 *
	 * Contains: username, password (never echoed back), label (sender ID),
	 * and country code dropdown.
	 *
	 * @return void
	 */
	private function render_general(): void {
		$username = $this->settings->get_esc( 'username', '' );
		$label    = $this->settings->get_esc( 'label', '1898' );
		$cc       = $this->settings->get_esc( 'cc', 'INT' );

		echo '<table class="form-table" role="presentation"><tbody>';

		// Username.
		echo '<tr>';
		echo '<th scope="row"><label for="sendsms_username">' . esc_html__( 'SendSMS Username', 'sendsms-dashboard' ) . '</label></th>';
		echo '<td><input type="text" id="sendsms_username" name="sendsms_username" value="'
			. esc_attr( $username ) . '" class="regular-text" /></td>';
		echo '</tr>';

		// Password — value is intentionally blank to avoid echoing the stored secret.
		echo '<tr>';
		echo '<th scope="row"><label for="sendsms_password">' . esc_html__( 'SendSMS Password / API Key', 'sendsms-dashboard' ) . '</label></th>';
		echo '<td>';
		echo '<input type="password" id="sendsms_password" name="sendsms_password" value="" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'Leave blank to keep the current password.', 'sendsms-dashboard' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		// Label (sender ID).
		echo '<tr>';
		echo '<th scope="row"><label for="sendsms_label">' . esc_html__( 'SendSMS Label (Sender ID)', 'sendsms-dashboard' ) . '</label></th>';
		echo '<td><input type="text" id="sendsms_label" name="sendsms_label" value="'
			. esc_attr( $label ) . '" class="regular-text" /></td>';
		echo '</tr>';

		// Country code dropdown.
		echo '<tr>';
		echo '<th scope="row"><label for="sendsms_cc">' . esc_html__( 'Country Code', 'sendsms-dashboard' ) . '</label></th>';
		echo '<td><select id="sendsms_cc" name="sendsms_cc">';
		echo '<option value="INT"' . selected( $cc, 'INT', false ) . '>' . esc_html__( 'International', 'sendsms-dashboard' ) . '</option>';
		foreach ( CountryCodes::map() as $code => $prefix ) {
			printf(
				'<option value="%s"%s>%s (+%s)</option>',
				esc_attr( $code ),
				selected( $cc, $code, false ),
				esc_html( $code ),
				esc_html( $prefix )
			);
		}
		echo '</select></td>';
		echo '</tr>';

		echo '</tbody></table>';
	}

	/**
	 * Save the General tab fields.
	 *
	 * Only updates `username`, `password`, `label`, and `cc`. Treats a blank
	 * submitted password as "no change" to avoid wiping the stored credential.
	 *
	 * @return void
	 */
	private function save_general(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified by check_admin_referer() in render() before this method is called.
		$patch = array(
			'username' => sanitize_text_field( wp_unslash( $_POST['sendsms_username'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
			'label'    => sanitize_text_field( wp_unslash( $_POST['sendsms_label'] ?? '' ) ),    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
			'cc'       => sanitize_key( wp_unslash( $_POST['sendsms_cc'] ?? 'INT' ) ),           // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		);

		// Validate cc is in the allowed list; fall back to INT.
		$allowed_cc = array_merge( array( 'INT' ), array_keys( CountryCodes::map() ) );
		if ( ! in_array( $patch['cc'], $allowed_cc, true ) ) {
			$patch['cc'] = 'INT';
		}

		// Only overwrite the stored password when the admin typed a new one.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- passwords are stored as-is; sanitize_text_field would strip valid characters such as <>&"'.
		$submitted_password = trim( isset( $_POST['sendsms_password'] ) ? (string) wp_unslash( $_POST['sendsms_password'] ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' !== $submitted_password ) {
			$patch['password'] = $submitted_password;
		}

		$this->settings->update_partial( $patch );

		// Bust the balance cache so the banner re-fetches with the new credentials.
		delete_transient( self::BALANCE_TRANSIENT );
	}

	// -------------------------------------------------------------------------
	// User tab
	// -------------------------------------------------------------------------

	/**
	 * Render the User settings tab.
	 *
	 * Contains: add_phone_field (checkbox), 2fa_roles (per-role checkboxes),
	 * 2fa_verification_message (textarea), and phone_meta (textarea).
	 *
	 * @return void
	 */
	private function render_user(): void {
		$add_phone = $this->settings->get( 'add_phone_field', false );
		$fa_roles  = $this->settings->get( '2fa_roles', array() );
		if ( ! is_array( $fa_roles ) ) {
			$fa_roles = array();
		}
		$fa_msg     = $this->settings->get_esc( '2fa_verification_message', '' );
		$phone_meta = $this->settings->get_esc( 'phone_meta', '' );

		echo '<table class="form-table" role="presentation"><tbody>';

		// add_phone_field checkbox.
		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Add phone number field / Enable 2FA', 'sendsms-dashboard' ) . '</th>';
		echo '<td>';
		echo '<label><input type="checkbox" name="sendsms_add_phone_field" value="1"'
			. checked( $add_phone, true, false ) . ' /> ';
		echo esc_html__( 'Add a phone number field in the user editing form and activate the 2FA feature.', 'sendsms-dashboard' );
		echo '</label>';
		echo '<p class="description">'
			. esc_html__(
				'This is designed only with the default wp-admin login form in mind. It may break if you have another login system. Test in a development environment first.',
				'sendsms-dashboard'
			)
			. '</p>';
		echo '</td>';
		echo '</tr>';

		// 2fa_roles — one checkbox per role.
		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Enable 2FA for the following roles', 'sendsms-dashboard' ) . '</th>';
		echo '<td>';
		$roles = wp_roles()->roles;
		foreach ( $roles as $role_key => $role_data ) {
			$checked = ( array_key_exists( $role_key, $fa_roles ) && '1' === (string) $fa_roles[ $role_key ] );
			printf(
				'<label style="display:block;margin-bottom:4px;">'
				. '<input type="checkbox" name="sendsms_2fa_roles[%s]" value="1"%s /> %s'
				. '</label>',
				esc_attr( $role_key ),
				checked( $checked, true, false ),
				esc_html( $role_data['name'] )
			);
		}
		echo '</td>';
		echo '</tr>';

		// 2fa_verification_message textarea.
		echo '<tr>';
		echo '<th scope="row"><label for="sendsms_2fa_msg">'
			. esc_html__( 'Two-factor authentication verification message', 'sendsms-dashboard' )
			. '</label></th>';
		echo '<td>';
		echo '<textarea id="sendsms_2fa_msg" name="sendsms_2fa_verification_message" cols="50" rows="5">'
			. esc_textarea( $fa_msg ) . '</textarea>';
		echo '<p class="description">'
			. esc_html__(
				'Use {code} as a placeholder for the verification code. If omitted, the code is appended at the end.',
				'sendsms-dashboard'
			)
			. '</p>';
		echo '</td>';
		echo '</tr>';

		// phone_meta textarea.
		echo '<tr>';
		echo '<th scope="row"><label for="sendsms_phone_meta">'
			. esc_html__( 'Phone metadata list', 'sendsms-dashboard' )
			. '</label></th>';
		echo '<td>';
		echo '<textarea id="sendsms_phone_meta" name="sendsms_phone_meta" cols="50" rows="5">'
			. esc_textarea( $phone_meta ) . '</textarea>';
		echo '<p class="description">'
			. esc_html__(
				'One user meta key per line. The plugin queries each key in order and uses the first valid phone number found. If empty, defaults to sendsms_phone_number.',
				'sendsms-dashboard'
			)
			. '</p>';
		echo '</td>';
		echo '</tr>';

		echo '</tbody></table>';
	}

	/**
	 * Save the User tab fields.
	 *
	 * Updates `add_phone_field`, `2fa_roles`, `2fa_verification_message`,
	 * and `phone_meta`. Stores `2fa_roles` as `array( role_key => '1' )` to
	 * match the v1.x shape exactly.
	 *
	 * @return void
	 */
	private function save_user(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified by check_admin_referer() in render() before this method is called.

		// add_phone_field — boolean stored as truthy value.
		$add_phone = ! empty( $_POST['sendsms_add_phone_field'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- checkbox presence check.

		// 2fa_roles — build role_key => '1' map to match v1.x shape.
		$submitted_roles = isset( $_POST['sendsms_2fa_roles'] ) && is_array( $_POST['sendsms_2fa_roles'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['sendsms_2fa_roles'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- validated by is_array check above.
			: array();

		$fa_roles  = array();
		$all_roles = wp_roles()->roles;
		foreach ( $all_roles as $role_key => $role_data ) {
			if ( isset( $submitted_roles[ $role_key ] ) && '1' === (string) $submitted_roles[ $role_key ] ) {
				$fa_roles[ sanitize_key( $role_key ) ] = '1';
			}
		}

		// 2fa_verification_message — may contain {code} placeholder.
		$fa_msg = sanitize_textarea_field(
			wp_unslash( $_POST['sendsms_2fa_verification_message'] ?? '' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		);

		// phone_meta — one meta key per line; plain text.
		$phone_meta = sanitize_textarea_field(
			wp_unslash( $_POST['sendsms_phone_meta'] ?? '' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		);

		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->settings->update_partial(
			array(
				'add_phone_field'          => $add_phone,
				'2fa_roles'                => $fa_roles,
				'2fa_verification_message' => $fa_msg,
				'phone_meta'               => $phone_meta,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Subscription tab
	// -------------------------------------------------------------------------

	/**
	 * Render the Subscription settings tab.
	 *
	 * Contains: subscribe_phone_verification (checkbox),
	 * subscribe_verification_message (textarea), ip_limit (text),
	 * and restricted_ips (textarea).
	 *
	 * @return void
	 */
	private function render_subscription(): void {
		$verification_on = $this->settings->get( 'subscribe_phone_verification', false );
		$verify_msg      = $this->settings->get_esc( 'subscribe_verification_message', '' );
		$ip_limit        = $this->settings->get_esc( 'ip_limit', '' );
		$restricted_ips  = $this->settings->get_esc( 'restricted_ips', '' );

		echo '<table class="form-table" role="presentation"><tbody>';

		// subscribe_phone_verification checkbox.
		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'SMS verification?', 'sendsms-dashboard' ) . '</th>';
		echo '<td>';
		echo '<label><input type="checkbox" name="sendsms_subscribe_phone_verification" value="1"'
			. checked( $verification_on, true, false ) . ' /> ';
		echo esc_html__( 'Send a verification code when someone subscribes or unsubscribes.', 'sendsms-dashboard' );
		echo '</label>';
		echo '</td>';
		echo '</tr>';

		// subscribe_verification_message textarea.
		echo '<tr>';
		echo '<th scope="row"><label for="sendsms_sub_msg">'
			. esc_html__( 'Verification message', 'sendsms-dashboard' )
			. '</label></th>';
		echo '<td>';
		echo '<textarea id="sendsms_sub_msg" name="sendsms_subscribe_verification_message" cols="50" rows="5">'
			. esc_textarea( $verify_msg ) . '</textarea>';
		echo '<p class="description">'
			. esc_html__(
				'Use {code} as a placeholder for the verification code. If omitted, the code is appended at the end.',
				'sendsms-dashboard'
			)
			. '</p>';
		echo '</td>';
		echo '</tr>';

		// ip_limit text field.
		echo '<tr>';
		echo '<th scope="row"><label for="sendsms_ip_limit">'
			. esc_html__( 'IP limit', 'sendsms-dashboard' )
			. '</label></th>';
		echo '<td>';
		echo '<input type="text" id="sendsms_ip_limit" name="sendsms_ip_limit" value="'
			. esc_attr( $ip_limit ) . '" class="regular-text" />';
		echo '<p class="description">'
			. esc_html__(
				'Max subscriptions per IP in format maximum/minutes (e.g. 5/10 = 5 per 10 min). Use -1 for unlimited minutes (e.g. 5/-1). Leave blank for no restriction.',
				'sendsms-dashboard'
			)
			. '</p>';
		echo '</td>';
		echo '</tr>';

		// restricted_ips textarea.
		echo '<tr>';
		echo '<th scope="row"><label for="sendsms_restricted_ips">'
			. esc_html__( 'Restricted IP addresses', 'sendsms-dashboard' )
			. '</label></th>';
		echo '<td>';
		echo '<textarea id="sendsms_restricted_ips" name="sendsms_restricted_ips" cols="50" rows="5">'
			. esc_textarea( $restricted_ips ) . '</textarea>';
		echo '<p class="description">'
			. esc_html__( 'One IP address per line. These IPs will be blocked from subscribing or unsubscribing.', 'sendsms-dashboard' )
			. '</p>';
		echo '</td>';
		echo '</tr>';

		echo '</tbody></table>';
	}

	/**
	 * Save the Subscription tab fields.
	 *
	 * Updates `subscribe_phone_verification`, `subscribe_verification_message`,
	 * `ip_limit`, and `restricted_ips`.
	 *
	 * @return void
	 */
	private function save_subscription(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified by check_admin_referer() in render() before this method is called.

		$verification_on = ! empty( $_POST['sendsms_subscribe_phone_verification'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- checkbox presence check.

		// Verification message may contain {code} placeholder.
		$verify_msg = sanitize_textarea_field(
			wp_unslash( $_POST['sendsms_subscribe_verification_message'] ?? '' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		);

		$ip_limit = sanitize_text_field(
			wp_unslash( $_POST['sendsms_ip_limit'] ?? '' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		);

		$restricted_ips = sanitize_textarea_field(
			wp_unslash( $_POST['sendsms_restricted_ips'] ?? '' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		);

		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->settings->update_partial(
			array(
				'subscribe_phone_verification'   => $verification_on,
				'subscribe_verification_message' => $verify_msg,
				'ip_limit'                       => $ip_limit,
				'restricted_ips'                 => $restricted_ips,
			)
		);
	}
}
