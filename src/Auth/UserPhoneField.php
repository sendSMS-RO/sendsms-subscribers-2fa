<?php
/**
 * Renders and persists the SendSMS phone-number field on WordPress user screens.
 *
 * Hooks into six WordPress actions so the field appears everywhere a phone
 * number can be captured: the Edit-User screen, the Your-Profile screen, the
 * Add-New-User screen, and all three matching save actions. Mirrors the v1.x
 * behaviour implemented via `add_new_user_field`, `user_register_metadata`, and
 * `add_new_user_field_to_edit_form`.
 *
 * @package SendSMS\Dashboard\Auth
 * @since   2.0.0
 */

namespace SendSMS\Dashboard\Auth;

use SendSMS\Dashboard\Storage\Settings;
use SendSMS\Dashboard\Support\PhoneNumber;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the phone-number field on WordPress user-management screens.
 *
 * The form field is always named `sendsms_phone_number`. The value is
 * normalised through {@see PhoneNumber::normalize()} and stored under the
 * first key returned by {@see Settings::user_phone_meta_keys()} (defaults to
 * `sendsms_phone_number`, matching v1.x).
 *
 * @since 2.0.0
 */
final class UserPhoneField {

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 * @param Settings $settings Plugin settings instance.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register all six WordPress hooks handled by this class.
	 *
	 * Must be called from the plugin bootstrap after the `init` action so that
	 * WordPress user-screen actions are available.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render_edit' ) );
		add_action( 'edit_user_profile', array( $this, 'render_edit' ) );
		add_action( 'user_new_form', array( $this, 'render_new' ) );
		add_action( 'personal_options_update', array( $this, 'save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save' ) );
		add_action( 'user_register', array( $this, 'save' ) );
	}

	/**
	 * Render the phone-number field on the Edit-User and Your-Profile screens.
	 *
	 * Pre-fills the input with the value stored in the primary meta key so
	 * existing data is visible when an admin edits a user.
	 *
	 * @since  2.0.0
	 * @param  \WP_User $user The user object whose profile is being displayed.
	 * @return void
	 */
	public function render_edit( $user ): void {
		$keys    = $this->settings->user_phone_meta_keys();
		$primary = $keys[0];
		$value   = (string) get_user_meta( $user->ID, $primary, true );

		echo '<h2>' . esc_html__( 'SendSMS Dashboard', 'sendsms-dashboard' ) . '</h2>';
		echo '<table class="form-table"><tr>';
		echo '<th><label for="sendsms_phone_number">' . esc_html__( 'Phone number', 'sendsms-dashboard' ) . '</label></th>';
		echo '<td>';
		echo '<input type="tel" name="sendsms_phone_number" id="sendsms_phone_number" value="' . esc_attr( $value ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'Required for SMS 2FA.', 'sendsms-dashboard' ) . '</p>';
		echo '</td>';
		echo '</tr></table>';
	}

	/**
	 * Render the phone-number field on the Add-New-User screen.
	 *
	 * The field is empty because no user exists yet; the value is saved by
	 * {@see self::save()} on the `user_register` action.
	 *
	 * @since  2.0.0
	 * @return void
	 */
	public function render_new(): void {
		echo '<h2>' . esc_html__( 'SendSMS Dashboard', 'sendsms-dashboard' ) . '</h2>';
		echo '<table class="form-table"><tr>';
		echo '<th><label for="sendsms_phone_number">' . esc_html__( 'Phone number', 'sendsms-dashboard' ) . '</label></th>';
		echo '<td>';
		echo '<input type="tel" name="sendsms_phone_number" id="sendsms_phone_number" value="" class="regular-text" />';
		echo '</td>';
		echo '</tr></table>';
	}

	/**
	 * Save handler shared by all six hook points.
	 *
	 * Normalises the submitted phone number through
	 * {@see PhoneNumber::normalize()} before writing it to user meta. Deletes
	 * the meta key when the submitted value is blank so stale data is cleared.
	 *
	 * WordPress verifies the `_wpnonce` for profile-update requests before
	 * firing `personal_options_update` and `edit_user_profile_update`, and the
	 * Add-New-User form is nonce-protected by core, so no additional nonce
	 * check is required here.
	 *
	 * @since  2.0.0
	 * @param  int $user_id The ID of the user being created or updated.
	 * @return void
	 */
	public function save( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) && ! current_user_can( 'create_users' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WP verifies _wpnonce for profile updates and user_register before firing these actions.
		if ( ! isset( $_POST['sendsms_phone_number'] ) ) {
			return;
		}

		$keys    = $this->settings->user_phone_meta_keys();
		$primary = $keys[0];

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WP verifies _wpnonce for profile updates and user_register before firing these actions.
		$raw = sanitize_text_field( wp_unslash( (string) $_POST['sendsms_phone_number'] ) );

		if ( '' === $raw ) {
			delete_user_meta( $user_id, $primary );
			return;
		}

		$cc   = $this->settings->get_esc( 'cc', 'INT' );
		$norm = PhoneNumber::normalize( $raw, $cc );

		if ( '' === $norm ) {
			return;
		}

		update_user_meta( $user_id, $primary, $norm );
	}
}
