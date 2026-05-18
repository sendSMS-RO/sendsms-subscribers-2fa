<?php
/**
 * Renders 2FA fields on wp-login.php.
 *
 * This class is responsible only for the presentation layer of the two-factor
 * authentication flow. It injects the code-entry (or phone-enrollment) fields
 * into the standard wp-login.php form when an in-progress 2FA token is
 * detected, displays a "Resend code" link, and handles the resend GET action.
 * It does NOT perform authentication — that responsibility belongs entirely to
 * {@see \SendSMS\Dashboard\Auth\TwoFactor}, which handles the `authenticate`
 * filter and validates submitted codes.
 *
 * @package SendSMS\Dashboard\Auth
 * @since   2.0.0
 */

namespace SendSMS\Dashboard\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Injects 2FA form fields into wp-login.php and handles the resend-code action.
 *
 * Registered hooks:
 *  - `login_form`                              — conditionally renders code-entry
 *                                               or phone-enrollment fields when a
 *                                               pending 2FA token is present in
 *                                               the request.
 *  - `login_form_sendsms_dashboard_resend_code` — handles the GET action triggered
 *                                               by the "Resend code" link, applies
 *                                               a 60-second rate limit, flags the
 *                                               transient for resend, and redirects.
 *  - `login_redirect`                          — restores the original `redirect_to`
 *                                               URL stored in the pending transient
 *                                               after a successful 2FA.
 *
 * @since 2.0.0
 */
final class LoginForm {

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
	 * @param PendingLogin $pending Store that holds in-progress 2FA payloads.
	 */
	public function __construct( PendingLogin $pending ) {
		$this->pending = $pending;
	}

	/**
	 * Register all hooks needed by this class.
	 *
	 * Called once from the plugin bootstrap. Does not fire any side-effects on
	 * its own — everything is deferred to the registered callbacks.
	 *
	 * @since  2.0.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'login_form', array( $this, 'maybe_render_code_fields' ) );
		add_action( 'login_form_sendsms_dashboard_resend_code', array( $this, 'handle_resend' ) );
		add_filter( 'login_redirect', array( $this, 'maybe_restore_redirect' ), 10, 3 );
	}

	/**
	 * Render the code-entry fields when the current login request carries a
	 * pending-2FA token. Called from the `login_form` hook (which fires inside
	 * the standard wp-login form).
	 *
	 * When the pending payload has no `phone_hash` the user has not yet
	 * enrolled a phone number, so a phone-enrollment input is rendered instead
	 * of the code field. Once a phone is stored in the transient by
	 * {@see \SendSMS\Dashboard\Auth\TwoFactor}, subsequent renders switch to the
	 * code-entry view.
	 *
	 * @since  2.0.0
	 * @return void
	 */
	public function maybe_render_code_fields(): void {
		$token = $this->token_from_request();
		if ( '' === $token ) {
			return;
		}

		$pending = $this->pending->get( $token );
		if ( null === $pending ) {
			return; // Expired or invalid transient — let the user log in again normally.
		}

		$needs_phone = empty( $pending['phone_hash'] );

		// Hide the standard username/password rows + the "Remember Me" line and the
		// default submit button. Restyle the WP error block as an info notice (blue)
		// instead of error red — the "enter the verification code" prompt is
		// instructional, not a failure. WP form markup varies between versions, so
		// the JS targets inputs by id and walks up to the containing <p>.
		echo '<style>'
			. 'p.forgetmenot,p.submit,p.user-login-wrap,p.user-pass-wrap{display:none!important;}'
			. '#login_error{border-left-color:#2271b1!important;background:#f0f6fc!important;color:#1d2327!important;}'
			. '#login_error::before,#login_error strong{color:#2271b1!important;}'
			. '</style>';
		echo '<script>document.addEventListener("DOMContentLoaded",function(){'
			. 'var f=document.getElementById("loginform");if(!f)return;'
			// For each std field, walk up from the input (and from its label) until
			// we hit a direct child of #loginform and hide that container. Robust
			// across WP versions whether the wrapper is <p>, <div class="wp-pwd">, etc.
			. 'function hideContainerOf(el){if(!el)return;var n=el;while(n&&n.parentElement&&n.parentElement!==f){n=n.parentElement;}if(n&&n!==f)n.style.display="none";}'
			. '["user_login","user_pass"].forEach(function(id){'
				. 'hideContainerOf(document.getElementById(id));'
				. 'hideContainerOf(document.querySelector("label[for=\\""+id+"\\"]"));'
			. '});'
			. 'f.querySelectorAll("p.forgetmenot,p.submit,p.user-login-wrap,p.user-pass-wrap").forEach(function(n){n.style.display="none";});'
			. '});</script>';

		echo '<input type="hidden" name="sendsms_2fa_token" value="' . esc_attr( $token ) . '" />';

		if ( $needs_phone ) {
			echo '<p><label for="sendsms_phone_number">' . esc_html__( 'Add your phone number for 2FA', 'sendsms-dashboard' ) . '<br />';
			echo '<input type="tel" name="sendsms_phone_number" id="sendsms_phone_number" class="input" size="20" required /></label></p>';
		} else {
			echo '<p><label for="code">' . esc_html__( 'Verification code', 'sendsms-dashboard' ) . '<br />';
			echo '<input type="text" name="code" id="code" class="input" size="20" inputmode="text" autocomplete="one-time-code" required /></label></p>';

			$resend_url = esc_url(
				add_query_arg(
					array(
						'action'            => 'sendsms_dashboard_resend_code',
						'sendsms_2fa_token' => $token,
					),
					wp_login_url()
				)
			);
			echo '<p><a href="' . $resend_url . '">' . esc_html__( 'Resend code', 'sendsms-dashboard' ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped by esc_url() above
		}

		// Submit row (the original .submit row is hidden by the style block above so we need our own).
		echo '<p class="sendsms-dashboard-2fa-submit submit">';
		echo '<button type="submit" name="wp-submit" class="button button-primary button-large" style="float:right;">';
		echo esc_html( $needs_phone ? __( 'Send code', 'sendsms-dashboard' ) : __( 'Verify', 'sendsms-dashboard' ) );
		echo '</button></p>';
	}

	/**
	 * Handle the `?action=sendsms_dashboard_resend_code` GET request from
	 * the form's "Resend code" link.
	 *
	 * Applies a 60-second rate limit based on the `last_sms_at` timestamp
	 * stored in the pending transient. When the limit is not exceeded, a
	 * `resend_requested` flag is written to the transient so that
	 * {@see \SendSMS\Dashboard\Auth\TwoFactor::filter_authenticate()} sends a
	 * fresh code and updates `last_sms_at` on the next pass. The user is then
	 * redirected back to wp-login.php with the token and a status message
	 * parameter preserved.
	 *
	 * @since  2.0.0
	 * @return void
	 */
	public function handle_resend(): void {
		$token = $this->token_from_request();
		if ( '' === $token ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$pending = $this->pending->get( $token );
		if ( null === $pending ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		// Rate-limit: allow at most one resend per 60 seconds.
		$now         = time();
		$last_sms_at = isset( $pending['last_sms_at'] ) ? (int) $pending['last_sms_at'] : 0;
		if ( $now - $last_sms_at < 60 ) {
			$url = add_query_arg(
				array(
					'sendsms_2fa_token' => $token,
					'sendsms_msg'       => 'resend_rate',
				),
				wp_login_url()
			);
			wp_safe_redirect( $url );
			exit;
		}

		// Flag the transient so TwoFactor::filter_authenticate() sends a fresh
		// code and bumps last_sms_at on the next authenticate pass.
		$pending['resend_requested'] = true;
		$this->pending->update( $token, $pending );

		$url = add_query_arg(
			array(
				'sendsms_2fa_token' => $token,
				'sendsms_msg'       => 'code_resent',
			),
			wp_login_url()
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * After a successful 2FA, restore the redirect_to URL that was stashed in
	 * the pending transient at the start of the flow.
	 *
	 * Hooked on `login_redirect` with priority 10. When the pending transient
	 * contains a non-empty `redirect_to` entry it takes precedence over the
	 * value WordPress would otherwise use, ensuring the user lands on the page
	 * they originally requested before the 2FA challenge interrupted the flow.
	 *
	 * @since  2.0.0
	 * @param  string                  $redirect_to The redirect destination URL.
	 * @param  string                  $requested   The originally requested redirect.
	 * @param  \WP_User|\WP_Error|null $user        The logged-in user, a WP_Error,
	 *                                              or null.
	 * @return string The redirect URL to use.
	 */
	public function maybe_restore_redirect( $redirect_to, $requested, $user ) {
		if ( ! ( $user instanceof \WP_User ) ) {
			return $redirect_to;
		}

		$token = $this->token_from_request();
		if ( '' === $token ) {
			return $redirect_to;
		}

		$pending = $this->pending->get( $token );
		if ( null !== $pending && ! empty( $pending['redirect_to'] ) ) {
			return (string) $pending['redirect_to'];
		}

		return $redirect_to;
	}

	/**
	 * Pull the 2FA token from the current request.
	 *
	 * POST takes precedence over GET so that form submissions are handled
	 * correctly when the token is also present in the query string. The value
	 * is sanitized but no nonce check is performed here — wp-login.php applies
	 * its own CSRF protection on form submissions, and the token itself is an
	 * unguessable secret that mitigates replay on GET requests.
	 *
	 * @since  2.0.0
	 * @return string The sanitized token, or an empty string if not present.
	 */
	private function token_from_request(): string {
		if ( isset( $_POST['sendsms_2fa_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- login flow; CSRF mitigated by wp-login.php's own nonce on POST
			return sanitize_text_field( wp_unslash( $_POST['sendsms_2fa_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( isset( $_GET['sendsms_2fa_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET param used only for form continuation, no state change
			return sanitize_text_field( wp_unslash( $_GET['sendsms_2fa_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( isset( $_COOKIE[ self::TOKEN_COOKIE ] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE[ self::TOKEN_COOKIE ] ) );
		}
		return '';
	}

	/**
	 * Cookie name used to forward the pending-2FA token from the begin-flow
	 * request to the subsequent login-form render.
	 */
	public const TOKEN_COOKIE = 'sendsms_dashboard_2fa_token';

	/**
	 * Set the pending-2FA token cookie.
	 *
	 * Called by {@see \SendSMS\Dashboard\Auth\TwoFactor} when a fresh token is
	 * issued so that {@see token_from_request()} can pick it up on the form
	 * re-render (WordPress does not forward WP_Error data into $_REQUEST).
	 *
	 * @param string $token Fresh pending-login token.
	 * @return void
	 */
	public static function set_token_cookie( string $token ): void {
		setcookie(
			self::TOKEN_COOKIE,
			$token,
			array(
				'expires'  => time() + 300,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::TOKEN_COOKIE ] = $token;
	}

	/**
	 * Clear the pending-2FA token cookie.
	 *
	 * @return void
	 */
	public static function clear_token_cookie(): void {
		setcookie(
			self::TOKEN_COOKIE,
			'',
			array(
				'expires'  => time() - 3600,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		unset( $_COOKIE[ self::TOKEN_COOKIE ] );
	}
}
