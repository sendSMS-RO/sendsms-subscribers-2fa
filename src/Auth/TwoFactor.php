<?php
/**
 * Authenticate-filter handler for SMS two-factor authentication.
 *
 * This class owns the entire 2FA state machine. It registers a single
 * callback on the `authenticate` filter at priority 30 (after WordPress's
 * default `wp_authenticate_username_password` at priority 20) and branches
 * on whether the second-trip token is present in `$_POST`.
 *
 * Trip 1 (no token): the password has already been verified by WP's own
 * filter. If the credentials are good and the user requires 2FA this class
 * stores a pending-login transient, sends an SMS (or queues an enrollment
 * prompt) and returns a `WP_Error` to halt the login.
 *
 * Trip 2 (token present): WP's default filter returns WP_Error('empty_password')
 * because the second-trip form carries no password. This class ignores that
 * error, reads the pending transient identified by the token, and either
 * verifies the submitted code (code branch) or processes phone enrollment
 * first (enrollment branch).
 *
 * @package SendSMS\Dashboard\Auth
 * @since   2.0.0
 */

namespace SendSMS\Dashboard\Auth;

use SendSMS\Dashboard\Api\Client;
use SendSMS\Dashboard\Storage\IpRepository;
use SendSMS\Dashboard\Storage\Settings;
use SendSMS\Dashboard\Support\Ip;
use SendSMS\Dashboard\Support\IpRateLimit;
use SendSMS\Dashboard\Support\PhoneNumber;
use SendSMS\Dashboard\Support\UserPhone;
use SendSMS\Dashboard\Support\VerificationCode;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the `authenticate` WordPress filter to implement SMS 2FA.
 *
 * A single instance is wired by {@see \SendSMS\Dashboard\Plugin::boot()} only
 * when the `add_phone_field` setting is enabled. The class does not call
 * `add_action` / `add_filter` itself; all hook registration is deferred to
 * {@see self::register()}.
 *
 * @since 2.0.0
 */
final class TwoFactor {

	/**
	 * Plugin settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * SendSMS.ro API client used to dispatch SMS codes.
	 *
	 * @var Client
	 */
	private $api;

	/**
	 * Cookie-backed verification-code generator and verifier.
	 *
	 * @var VerificationCode
	 */
	private $codes;

	/**
	 * IP address repository used for rate limiting.
	 *
	 * @var IpRepository
	 */
	private $ips;

	/**
	 * Transient-backed store for in-progress 2FA login sessions.
	 *
	 * @var PendingLogin
	 */
	private $pending;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 * @param Settings         $settings Plugin settings instance.
	 * @param Client           $api      SendSMS.ro API client.
	 * @param VerificationCode $codes    Cookie-backed code service.
	 * @param IpRepository     $ips      IP-address row store for rate limiting.
	 * @param PendingLogin     $pending  In-progress 2FA session store.
	 */
	public function __construct(
		Settings $settings,
		Client $api,
		VerificationCode $codes,
		IpRepository $ips,
		PendingLogin $pending
	) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->codes    = $codes;
		$this->ips      = $ips;
		$this->pending  = $pending;
	}

	/**
	 * Register the `authenticate` filter hook.
	 *
	 * Priority 30 ensures this callback runs after WordPress's own
	 * `wp_authenticate_username_password` (priority 20), so `$user` already
	 * holds a fully-validated `WP_User` on the first trip through the filter.
	 *
	 * @since  2.0.0
	 * @return void
	 */
	public function register(): void {
		add_filter( 'authenticate', array( $this, 'filter_authenticate' ), 30, 3 );
	}

	/**
	 * Main authenticate filter callback.
	 *
	 * Dispatches to {@see self::begin_flow()} on the first login trip (password
	 * verified, no 2FA token yet) or to {@see self::continue_flow()} on the
	 * second trip (token present, code or phone submitted).
	 *
	 * @since  2.0.0
	 * @param  \WP_User|\WP_Error|null $user     Result from the previous filter in the chain.
	 * @param  string                  $username The submitted username.
	 * @param  string                  $password The submitted password (empty on trip 2).
	 * @return \WP_User|\WP_Error
	 */
	public function filter_authenticate( $user, string $username, string $password ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $username and $password are required by the authenticate filter signature but not used here
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handled by WP's own login nonce on wp-login.php
		$token = isset( $_POST['sendsms_2fa_token'] ) ? sanitize_text_field( wp_unslash( $_POST['sendsms_2fa_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handled by WP's own login nonce on wp-login.php

		if ( '' !== $token ) {
			return $this->continue_flow( $token );
		}

		// Trip 1: pass through anything that is not a successfully-authenticated user.
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( ! ( $user instanceof \WP_User ) ) {
			return $user;
		}
		if ( ! $this->user_requires_2fa( $user ) ) {
			return $user;
		}

		return $this->begin_flow( $user );
	}

	/**
	 * Determine whether a user must complete the 2FA challenge.
	 *
	 * Reads the `2fa_roles` setting (an associative array of role-slug => '1'
	 * pairs, as stored by the v1.x admin UI) and returns true when at least one
	 * of the user's assigned roles appears as a key with value '1'.
	 *
	 * @since  2.0.0
	 * @param  \WP_User $user The user who just authenticated with a password.
	 * @return bool True when 2FA is required for this user.
	 */
	private function user_requires_2fa( \WP_User $user ): bool {
		if ( ! (bool) $this->settings->get( 'add_phone_field', false ) ) {
			return false;
		}
		$roles_setting = (array) $this->settings->get( '2fa_roles', array() );
		foreach ( $user->roles as $role ) {
			if ( isset( $roles_setting[ $role ] ) && '1' === (string) $roles_setting[ $role ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Begin the 2FA flow immediately after a successful password check (trip 1).
	 *
	 * If the user already has a phone number on file an SMS code is sent right
	 * away and `WP_Error('sendsms_dashboard_2fa_required')` is returned so
	 * wp-login.php re-renders the form with the code-entry fields injected by
	 * {@see \SendSMS\Dashboard\Auth\LoginForm}.
	 *
	 * If no phone is found the flow enters enrollment mode: the pending transient
	 * is stored without a `phone_hash` and `WP_Error('sendsms_dashboard_2fa_enroll_required')`
	 * is returned so the phone-number field is displayed instead.
	 *
	 * @since  2.0.0
	 * @param  \WP_User $user The user who passed the password check.
	 * @return \WP_Error Always returns a WP_Error to halt the login until 2FA is complete.
	 */
	private function begin_flow( \WP_User $user ): \WP_Error {
		$phone = UserPhone::resolve( $user->ID, $this->settings );

		$token   = PendingLogin::fresh_token();
		$pending = array(
			'user_id'     => $user->ID,
			'redirect_to' => isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- login flow; CSRF mitigated by wp-login.php's own nonce
			'remember'    => ! empty( $_POST['rememberme'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- login flow; CSRF mitigated by wp-login.php's own nonce
			'attempts'    => 0,
			'phone_hash'  => '',
			'last_sms_at' => 0,
		);

		if ( '' === $phone ) {
			// No phone on file yet — enter enrollment mode.
			$this->pending->store( $token, $pending );
			return new \WP_Error(
				'sendsms_dashboard_2fa_enroll_required',
				__( 'Add your phone number to complete sign-in.', 'sendsms-dashboard' ),
				array( 'token' => $token )
			);
		}

		// Phone already on file — send the code immediately.
		$pending['phone_hash']  = sha1( $phone );
		$pending['last_sms_at'] = time();
		$this->pending->store( $token, $pending );

		$body = $this->settings->get_esc( '2fa_verification_message', __( 'Your verification code: {code}', 'sendsms-dashboard' ) );
		$this->api->message_send( false, false, $phone, $body, 'code', '_2fa' );

		return new \WP_Error(
			'sendsms_dashboard_2fa_required',
			__( 'Check your phone and enter the verification code.', 'sendsms-dashboard' ),
			array( 'token' => $token )
		);
	}

	/**
	 * Continue a previously started 2FA flow (trip 2).
	 *
	 * Loads the pending transient identified by `$token` and dispatches to the
	 * appropriate sub-flow:
	 *
	 * - **Resend**: the `resend_requested` flag was set by
	 *   {@see \SendSMS\Dashboard\Auth\LoginForm::handle_resend()}. A fresh code
	 *   is sent and a `WP_Error` is returned to re-render the form.
	 * - **Enrollment**: `phone_hash` is empty, so the user submitted a phone
	 *   number for the first time. The number is validated, stored in user meta,
	 *   a code is sent, and the transient is updated to the code-verification state.
	 * - **Code verification**: the submitted `$_POST['code']` is checked against
	 *   the hashed cookie. On success the transient is deleted and the `WP_User`
	 *   is returned to complete the login. On failure the attempt counter is
	 *   incremented; after 5 failures the session is invalidated.
	 *
	 * IP-based rate limiting (via {@see IpRateLimit}) is applied before the
	 * code-verification and enrollment branches.
	 *
	 * @since  2.0.0
	 * @param  string $token The pending-login token from `$_POST['sendsms_2fa_token']`.
	 * @return \WP_User|\WP_Error A `WP_User` on success, `WP_Error` otherwise.
	 */
	private function continue_flow( string $token ) {
		$pending = $this->pending->get( $token );
		if ( null === $pending ) {
			return new \WP_Error(
				'sendsms_dashboard_2fa_expired',
				__( 'Your login session expired. Please sign in again.', 'sendsms-dashboard' )
			);
		}

		$user = get_user_by( 'id', (int) $pending['user_id'] );
		if ( ! ( $user instanceof \WP_User ) ) {
			$this->pending->delete( $token );
			return new \WP_Error(
				'sendsms_dashboard_2fa_invalid_user',
				__( 'User no longer exists.', 'sendsms-dashboard' )
			);
		}

		// Handle resend-requested flag set by LoginForm::handle_resend().
		if ( ! empty( $pending['resend_requested'] ) ) {
			unset( $pending['resend_requested'] );
			$phone = UserPhone::resolve( $user->ID, $this->settings );
			if ( '' !== $phone ) {
				$body = $this->settings->get_esc( '2fa_verification_message', __( 'Your verification code: {code}', 'sendsms-dashboard' ) );
				$this->api->message_send( false, false, $phone, $body, 'code', '_2fa' );
				$pending['last_sms_at'] = time();
				$pending['phone_hash']  = sha1( $phone );
				$pending['attempts']    = 0;
			}
			$this->pending->update( $token, $pending );
			return new \WP_Error(
				'sendsms_dashboard_2fa_code_resent',
				__( 'A new code has been sent.', 'sendsms-dashboard' ),
				array( 'token' => $token )
			);
		}

		// Rate-limit verify attempts by IP.
		$ip = Ip::current();
		if ( '' !== $ip ) {
			$limiter = new IpRateLimit( $this->settings, $this->ips );
			if ( $limiter->is_too_many( $ip ) ) {
				return new \WP_Error(
					'sendsms_dashboard_2fa_rate_limited',
					__( 'Too many attempts. Please wait a moment.', 'sendsms-dashboard' )
				);
			}
		}

		// Enrollment branch: phone_hash is empty, user is submitting a new phone.
		if ( empty( $pending['phone_hash'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handled by WP's own login nonce on wp-login.php
			$raw_phone = isset( $_POST['sendsms_phone_number'] ) ? sanitize_text_field( wp_unslash( $_POST['sendsms_phone_number'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handled by WP's own login nonce on wp-login.php
			$cc        = $this->settings->get_esc( 'cc', 'INT' );
			$phone     = PhoneNumber::normalize( $raw_phone, $cc );
			if ( '' === $phone ) {
				return new \WP_Error(
					'sendsms_dashboard_2fa_invalid_phone',
					__( 'Invalid phone number.', 'sendsms-dashboard' ),
					array( 'token' => $token )
				);
			}

			// Persist the phone to the primary user meta key.
			$keys = $this->settings->user_phone_meta_keys();
			update_user_meta( $user->ID, $keys[0], $phone );

			// Send the verification code immediately.
			$body = $this->settings->get_esc( '2fa_verification_message', __( 'Your verification code: {code}', 'sendsms-dashboard' ) );
			$this->api->message_send( false, false, $phone, $body, 'code', '_2fa' );

			$pending['phone_hash']  = sha1( $phone );
			$pending['last_sms_at'] = time();
			$pending['attempts']    = 0;
			$this->pending->update( $token, $pending );

			return new \WP_Error(
				'sendsms_dashboard_2fa_required',
				__( 'Check your phone and enter the verification code.', 'sendsms-dashboard' ),
				array( 'token' => $token )
			);
		}

		// Code-verification branch.
		$phone = UserPhone::resolve( $user->ID, $this->settings );
		if ( '' === $phone ) {
			$this->pending->delete( $token );
			return new \WP_Error(
				'sendsms_dashboard_2fa_invalid_user',
				__( 'Phone number not found.', 'sendsms-dashboard' )
			);
		}

		// Delegate to VerificationCode, which reads the submitted value from the 'code' POST field internally.
		if ( ! $this->codes->verify( $phone, '_2fa' ) ) {
			$pending['attempts'] = (int) ( $pending['attempts'] ?? 0 ) + 1;
			if ( $pending['attempts'] >= 5 ) {
				$this->pending->delete( $token );
				return new \WP_Error(
					'sendsms_dashboard_2fa_locked',
					__( 'Too many wrong codes. Please sign in again.', 'sendsms-dashboard' )
				);
			}
			$this->pending->update( $token, $pending );
			return new \WP_Error(
				'sendsms_dashboard_2fa_invalid_code',
				__( 'Invalid code.', 'sendsms-dashboard' ),
				array( 'token' => $token )
			);
		}

		// Successful verification — delete the transient and return the user.
		$this->pending->delete( $token );
		return $user;
	}
}
