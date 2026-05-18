<?php
/**
 * SMS-based two-factor authentication for wp-admin login.
 *
 * Pattern: after WP validates the password (`wp_login` action), clear the
 * auth cookie that was just set, stash a pending-login transient keyed on an
 * unguessable token, and redirect the user to a dedicated verify page
 * (`wp-login.php?action=sendsms_2fa_verify&token=…`). The verify page renders
 * its own form via `login_header()` / `login_footer()` so it looks like WP's
 * own login, and on successful code submission we call `wp_set_auth_cookie()`
 * directly and redirect into the admin.
 *
 * This is the same pattern used by the WP-team "Two Factor" plugin. The
 * pending-login transient is the only piece of cross-request state we need;
 * the verification code itself is stored as a hashed cookie via
 * {@see VerificationCode::generate()}, identical to how subscribe/unsubscribe
 * flows verify codes.
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
 * Two-factor authentication controller.
 */
final class TwoFactor {

	/**
	 * Action slug appended to wp-login.php for the verify form.
	 */
	private const VERIFY_ACTION = 'sendsms_2fa_verify';

	/**
	 * Action slug appended to wp-login.php for the resend-code link.
	 */
	private const RESEND_ACTION = 'sendsms_2fa_resend';

	/**
	 * Form nonce action.
	 */
	private const NONCE_KEY = 'sendsms_dashboard_2fa';

	/**
	 * Reentrancy guard for the wp_login action: when we manually fire it from
	 * the verify-success path, prevent our own handler from looping the user
	 * back into the 2FA challenge again.
	 *
	 * @var bool
	 */
	private static $bypass_wp_login = false;

	/**
	 * Plugin settings reader.
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
	 * Verification code generator/verifier.
	 *
	 * @var VerificationCode
	 */
	private $codes;

	/**
	 * IP repository (rate-limit storage).
	 *
	 * @var IpRepository
	 */
	private $ips;

	/**
	 * Pending-login transient wrapper.
	 *
	 * @var PendingLogin
	 */
	private $pending;

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings Plugin settings.
	 * @param Client           $api      sendsms.ro API client.
	 * @param VerificationCode $codes    Verification code helper.
	 * @param IpRepository     $ips      IP rate-limit storage.
	 * @param PendingLogin     $pending  Pending-login transient wrapper.
	 */
	public function __construct( Settings $settings, Client $api, VerificationCode $codes, IpRepository $ips, PendingLogin $pending ) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->codes    = $codes;
		$this->ips      = $ips;
		$this->pending  = $pending;
	}

	/**
	 * Wire up the WordPress hooks. Idempotent.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_login', array( $this, 'on_wp_login' ), 10, 2 );
		add_action( 'login_form_' . self::VERIFY_ACTION, array( $this, 'on_verify' ) );
		add_action( 'login_form_' . self::RESEND_ACTION, array( $this, 'on_resend' ) );
	}

	/**
	 * Intercept successful username/password logins for users with 2FA enabled.
	 *
	 * Fires from {@see wp_signon()} immediately after {@see wp_set_auth_cookie()}.
	 * For users that need 2FA we clear the auth cookie WP just set, stash a
	 * pending-login record, send the SMS code, and redirect to the verify page.
	 *
	 * @param string   $user_login The username that just authenticated.
	 * @param \WP_User $user       The authenticated user object.
	 * @return void
	 */
	public function on_wp_login( $user_login, $user ): void {
		if ( self::$bypass_wp_login ) {
			return;
		}
		if ( ! ( $user instanceof \WP_User ) ) {
			return;
		}
		if ( ! $this->user_requires_2fa( $user ) ) {
			return;
		}

		// wp_signon just set the auth cookie — undo it. The user isn't through yet.
		wp_clear_auth_cookie();

		$token   = PendingLogin::fresh_token();
		$pending = array(
			'user_id'     => $user->ID,
			'redirect_to' => $this->read_redirect_to(),
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- inherits wp-login.php's CSRF protection
			'remember'    => ! empty( $_POST['rememberme'] ),
			'attempts'    => 0,
			'last_sms_at' => 0,
		);

		// Send the SMS now if the user has a phone on file; otherwise the
		// verify page renders an enrollment field first.
		$phone = UserPhone::resolve( $user->ID, $this->settings );
		if ( '' !== $phone ) {
			$this->send_code( $phone );
			$pending['last_sms_at'] = time();
		}

		$this->pending->store( $token, $pending );

		$verify_url = add_query_arg(
			array(
				'action' => self::VERIFY_ACTION,
				'token'  => $token,
			),
			site_url( 'wp-login.php', 'login_post' )
		);

		wp_safe_redirect( $verify_url );
		exit;
	}

	/**
	 * Handle GET (render form) and POST (verify code or save phone) on the
	 * verify URL.
	 *
	 * On the success branch this method calls {@see wp_set_auth_cookie()}
	 * directly and redirects to the original `redirect_to`. The reentrancy
	 * guard {@see self::$bypass_wp_login} ensures the manual
	 * `do_action( 'wp_login', … )` we fire to keep downstream plugins happy
	 * does not re-enter this class.
	 *
	 * @return void
	 */
	public function on_verify(): void {
		$token   = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token is the unguessable secret; POST mutations are nonce-protected separately
		$pending = '' !== $token ? $this->pending->get( $token ) : null;

		if ( null === $pending ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$user = get_user_by( 'id', (int) $pending['user_id'] );
		if ( ! ( $user instanceof \WP_User ) ) {
			$this->pending->delete( $token );
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$error_message = '';

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			check_admin_referer( self::NONCE_KEY, '_sendsms_nonce' );

			// IP rate-limit (uses the same per-IP cycle counter as the public flow).
			$ip = Ip::current();
			if ( '' !== $ip ) {
				$limiter = new IpRateLimit( $this->settings, $this->ips );
				if ( $limiter->is_too_many( $ip ) ) {
					$error_message = __( 'Too many attempts. Please wait a moment and try again.', 'sendsms-dashboard' );
				}
			}

			if ( '' === $error_message ) {
				$phone = UserPhone::resolve( $user->ID, $this->settings );

				if ( '' === $phone ) {
					// Enrollment branch.
					$raw  = isset( $_POST['sendsms_phone_number'] ) ? sanitize_text_field( wp_unslash( $_POST['sendsms_phone_number'] ) ) : '';
					$cc   = $this->settings->get_esc( 'cc', 'INT' );
					$norm = PhoneNumber::normalize( $raw, $cc );
					if ( '' === $norm ) {
						$error_message = __( 'Please enter a valid phone number.', 'sendsms-dashboard' );
					} else {
						$keys = $this->settings->user_phone_meta_keys();
						update_user_meta( $user->ID, $keys[0], $norm );
						$this->send_code( $norm );
						$pending['last_sms_at'] = time();
						$this->pending->update( $token, $pending );
						// Fall through to render in code-entry mode.
					}
				} else {
					// Code-verification branch.
					if ( $this->codes->verify( $phone, '_2fa' ) ) {
						$this->finish_login( $token, $user, $pending );
						return; // Unreachable: finish_login exits.
					}
					$pending['attempts'] = (int) ( $pending['attempts'] ?? 0 ) + 1;
					if ( $pending['attempts'] >= 5 ) {
						$this->pending->delete( $token );
						wp_safe_redirect( wp_login_url() );
						exit;
					}
					$this->pending->update( $token, $pending );
					$error_message = __( 'Invalid code. Please try again.', 'sendsms-dashboard' );
				}
			}
		}

		$current_phone = UserPhone::resolve( $user->ID, $this->settings );
		$this->render_form( $token, '' === $current_phone, $error_message );
		exit;
	}

	/**
	 * Handle the "Resend code" link from the verify form.
	 *
	 * Applies a 60-second rate limit based on the `last_sms_at` timestamp in
	 * the pending transient, sends a fresh SMS when allowed, and redirects
	 * back to the verify form with a `resent=1` flash flag for the UI.
	 *
	 * @return void
	 */
	public function on_resend(): void {
		$token   = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token is the unguessable secret; resend is rate-limited
		$pending = '' !== $token ? $this->pending->get( $token ) : null;

		if ( null === $pending ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$user = get_user_by( 'id', (int) $pending['user_id'] );
		if ( ! ( $user instanceof \WP_User ) ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$last_sms_at = (int) ( $pending['last_sms_at'] ?? 0 );
		if ( time() - $last_sms_at >= 60 ) {
			$phone = UserPhone::resolve( $user->ID, $this->settings );
			if ( '' !== $phone ) {
				$this->send_code( $phone );
				$pending['last_sms_at'] = time();
				$this->pending->update( $token, $pending );
			}
		}

		$verify_url = add_query_arg(
			array(
				'action' => self::VERIFY_ACTION,
				'token'  => $token,
				'resent' => '1',
			),
			site_url( 'wp-login.php', 'login_post' )
		);

		wp_safe_redirect( $verify_url );
		exit;
	}

	/**
	 * Finalize a successful 2FA challenge: delete the pending transient, issue
	 * the WP auth cookie, fire the standard wp_login action with the
	 * reentrancy guard set, then redirect to the original destination.
	 *
	 * @param string   $token   Pending-login token.
	 * @param \WP_User $user    Authenticated user.
	 * @param array    $pending Pending payload.
	 * @return void This method always exits.
	 */
	private function finish_login( string $token, \WP_User $user, array $pending ): void {
		$this->pending->delete( $token );

		wp_set_auth_cookie( $user->ID, (bool) ( $pending['remember'] ?? false ) );

		// Re-fire the wp_login action so other plugins listening for login
		// events still run. Our own handler short-circuits via the bypass flag.
		self::$bypass_wp_login = true;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing WP core action so downstream plugins (audit logs, etc.) still observe the login event
		do_action( 'wp_login', $user->user_login, $user );
		self::$bypass_wp_login = false;

		$redirect_to = ! empty( $pending['redirect_to'] ) ? (string) $pending['redirect_to'] : admin_url();
		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * Render the verify page (form + login_header chrome).
	 *
	 * @param string $token         Pending-login token to preserve across submit.
	 * @param bool   $enroll        True to render the phone-enrollment field instead of the code field.
	 * @param string $error_message Error to display in red. Empty string for none.
	 * @return void
	 */
	private function render_form( string $token, bool $enroll, string $error_message ): void {
		$title     = __( 'Two-factor authentication', 'sendsms-dashboard' );
		$wp_errors = null;
		if ( '' !== $error_message ) {
			$wp_errors = new \WP_Error( 'sendsms_dashboard_2fa', $error_message );
		}

		// "Code resent" flash message (notice-style, not error-style).
		$top_message = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash flag
		if ( ! empty( $_GET['resent'] ) ) {
			$top_message = '<p class="message">' . esc_html__( 'A new code has been sent to your phone.', 'sendsms-dashboard' ) . '</p>';
		}

		login_header( $title, $top_message, $wp_errors );

		$action_url = esc_url( site_url( 'wp-login.php?action=' . self::VERIFY_ACTION, 'login_post' ) );
		$resend_url = esc_url(
			add_query_arg(
				array(
					'action' => self::RESEND_ACTION,
					'token'  => $token,
				),
				site_url( 'wp-login.php', 'login_post' )
			)
		);

		?>
		<form name="loginform" id="loginform" action="<?php echo esc_attr( $action_url ); ?>" method="post">
			<?php wp_nonce_field( self::NONCE_KEY, '_sendsms_nonce' ); ?>
			<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />

			<?php if ( $enroll ) : ?>
				<p>
					<label for="sendsms_phone_number"><?php esc_html_e( 'Phone number', 'sendsms-dashboard' ); ?></label>
					<input
						type="tel"
						name="sendsms_phone_number"
						id="sendsms_phone_number"
						class="input"
						size="20"
						autocomplete="tel"
						required
						autofocus
					/>
				</p>
				<p class="submit">
					<button type="submit" class="button button-primary button-large" style="float:right;">
						<?php esc_html_e( 'Send code', 'sendsms-dashboard' ); ?>
					</button>
				</p>
			<?php else : ?>
				<p>
					<label for="sendsms_2fa_code"><?php esc_html_e( 'Verification code', 'sendsms-dashboard' ); ?></label>
					<input
						type="text"
						name="code"
						id="sendsms_2fa_code"
						class="input"
						size="20"
						autocomplete="one-time-code"
						inputmode="text"
						required
						autofocus
					/>
				</p>
				<p>
					<a href="<?php echo esc_attr( $resend_url ); ?>"><?php esc_html_e( 'Resend code', 'sendsms-dashboard' ); ?></a>
				</p>
				<p class="submit">
					<button type="submit" class="button button-primary button-large" style="float:right;">
						<?php esc_html_e( 'Verify', 'sendsms-dashboard' ); ?>
					</button>
				</p>
			<?php endif; ?>
		</form>
		<?php

		login_footer();
	}

	/**
	 * Send the 2FA verification code to the given phone via the sendsms.ro API.
	 *
	 * The {@see Api\Client::message_send()} call with type 'code' triggers
	 * {@see VerificationCode::generate()} internally, which sets the
	 * `sendsms_subscribe_check_2fa` cookie holding the hashed code so the
	 * later verify call can check the user-submitted code against it.
	 *
	 * @param string $phone Normalised phone number.
	 * @return void
	 */
	private function send_code( string $phone ): void {
		$body = $this->settings->get_esc(
			'2fa_verification_message',
			__( 'Your verification code: {code}', 'sendsms-dashboard' )
		);
		$this->api->message_send( false, false, $phone, $body, 'code', '_2fa' );
	}

	/**
	 * Whether the given user is opted into SMS 2FA via their role assignment.
	 *
	 * @param \WP_User $user The user to check.
	 * @return bool
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
	 * Read the original `redirect_to` from the login submission, defaulting to
	 * the admin home when absent.
	 *
	 * @return string A safe URL string.
	 */
	private function read_redirect_to(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- read-only forwarding of wp-login's own redirect_to value
		if ( isset( $_REQUEST['redirect_to'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- read-only forwarding of wp-login's own redirect_to value
			$candidate = esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}
		return admin_url();
	}
}
