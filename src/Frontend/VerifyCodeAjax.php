<?php
/**
 * Public AJAX handler for OTP code verification on subscribe and unsubscribe flows.
 *
 * Consolidates the two separate v1.x handlers
 * ({@see Sendsms_Dashboard_Public::subscribe_verify_code()} and
 * {@see Sendsms_Dashboard_Public::unsubscribe_verify_code()}) into a single
 * action disambiguated by a {@code context} POST parameter ({@code sub} or
 * {@code unsub}).
 *
 * On success:
 * - {@code sub}   → inserts the subscriber row and returns verify success.
 * - {@code unsub} → deletes the subscriber row (and remote contact when synced)
 *                   and returns verify success.
 *
 * Registered for both authenticated and unauthenticated requests so that guests
 * can complete verification from any public widget.
 *
 * @package Rosendsms\Dashboard\Frontend
 */

namespace Rosendsms\Dashboard\Frontend;

use Rosendsms\Dashboard\Api\Client;
use Rosendsms\Dashboard\Storage\IpRepository;
use Rosendsms\Dashboard\Storage\Settings;
use Rosendsms\Dashboard\Storage\SubscriberRepository;
use Rosendsms\Dashboard\Support\Ip;
use Rosendsms\Dashboard\Support\IpRateLimit;
use Rosendsms\Dashboard\Support\PhoneNumber;
use Rosendsms\Dashboard\Support\VerificationCode;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the {@code rosendsms_dash_verify_code} AJAX action.
 *
 * Allowed POST parameters:
 * - {@code security}   WordPress nonce (key: 'rosendsms_dash_nonce').
 * - {@code context}    Either 'sub' (subscribe) or 'unsub' (unsubscribe).
 * - {@code phone_number} Raw phone number string.
 * - {@code code}       The 5-character OTP code the user received via SMS.
 * - {@code first_name} Required only when context is 'sub'.
 * - {@code last_name}  Required only when context is 'sub'.
 *
 * No duplicate-subscriber or not-subscribed guard is applied here — the
 * first-stage handler (SubscribeAjax / UnsubscribeAjax) already validated that.
 * The cookie created by Api\Client::message_send() is the sole binding between
 * the two stages.
 */
final class VerifyCodeAjax {

	/**
	 * Plugin settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Subscriber persistence layer.
	 *
	 * @var SubscriberRepository
	 */
	private $repo;

	/**
	 * IP address row persistence layer (for rate limiting).
	 *
	 * @var IpRepository
	 */
	private $ips;

	/**
	 * OTP code verifier.
	 *
	 * @var VerificationCode
	 */
	private $codes;

	/**
	 * Sendsms.ro API client (used to remove remote contacts on unsubscribe).
	 *
	 * @var Client
	 */
	private $api;

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings Plugin settings store.
	 * @param SubscriberRepository $repo     Subscriber persistence layer.
	 * @param IpRepository         $ips      IP address table (rate limiting).
	 * @param VerificationCode     $codes    OTP code verifier.
	 * @param Client               $api      Sendsms.ro API client.
	 */
	public function __construct(
		Settings $settings,
		SubscriberRepository $repo,
		IpRepository $ips,
		VerificationCode $codes,
		Client $api
	) {
		$this->settings = $settings;
		$this->repo     = $repo;
		$this->ips      = $ips;
		$this->codes    = $codes;
		$this->api      = $api;
	}

	/**
	 * Register the wp_ajax_* and wp_ajax_nopriv_* hooks for this handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_rosendsms_dash_verify_code', array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_rosendsms_dash_verify_code', array( $this, 'handle' ) );
	}

	/**
	 * Process a code-verification request.
	 *
	 * 1. Nonce check.
	 * 2. Context validation (sub|unsub → suffix _sub|_unsub).
	 * 3. Phone normalization.
	 * 4. IP allow-list + rate limit.
	 * 5. Cookie presence check (parity with v1.x explicit cookie check).
	 * 6. OTP verification via VerificationCode::verify().
	 * 7. Insert (sub) or delete (unsub) the subscriber row.
	 *
	 * @return void
	 */
	public function handle(): void {
		// 1. Nonce.
		if ( ! check_ajax_referer( 'rosendsms_dash_nonce', 'security', false ) ) {
			wp_send_json_error( array( 'code' => 'sendsms_dashboard_bad_nonce' ), 403 );
		}

		// 2. Context → suffix.
		$context = isset( $_POST['context'] ) ? sanitize_key( wp_unslash( $_POST['context'] ) ) : '';
		if ( ! in_array( $context, array( 'sub', 'unsub' ), true ) ) {
			wp_send_json_error( array( 'code' => 'sendsms_dashboard_invalid_context' ), 400 );
		}
		$suffix = ( 'sub' === $context ) ? '_sub' : '_unsub';

		// 3. Phone.
		$raw   = isset( $_POST['phone_number'] ) ? sanitize_text_field( wp_unslash( $_POST['phone_number'] ) ) : '';
		$cc    = $this->settings->get_esc( 'cc', 'INT' );
		$phone = PhoneNumber::normalize( $raw, $cc );
		if ( '' === $phone ) {
			wp_send_json_error( array( 'code' => 'sendsms_dashboard_invalid_phone' ), 400 );
		}

		// 4. IP allow-list + rate limit.
		$this->guard();

		// 5. Cookie presence check (mirrors v1.x explicit cookie check before verify).
		$cookie_name = 'sendsms_subscribe_check' . $suffix;
		if ( ! isset( $_COOKIE[ $cookie_name ] ) ) {
			wp_send_json_error( array( 'code' => 'sendsms_dashboard_cookie_expired' ), 400 );
		}

		// 6. OTP verification — reads $_POST['code'] internally.
		if ( ! $this->codes->verify( $phone, $suffix ) ) {
			wp_send_json_error( array( 'code' => 'sendsms_dashboard_invalid_code' ), 400 );
		}

		// 7. Perform the action that was deferred pending verification.
		if ( 'sub' === $context ) {
			$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
			$last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';

			$this->repo->insert(
				$phone,
				array(
					'first_name' => $first_name,
					'last_name'  => $last_name,
					'ip_address' => Ip::current(),
					'browser'    => isset( $_SERVER['HTTP_USER_AGENT'] )
						? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
						: '',
				)
			);
			wp_send_json_success( array( 'verify' => true ) );
		}

		// unsub context.
		$row    = $this->repo->find( $phone );
		$synced = ( is_array( $row ) && isset( $row['synced'] ) ) ? $row['synced'] : null;
		if ( ! is_null( $synced ) ) {
			$this->api->delete_contact( (int) $synced );
		}
		$this->repo->delete( $phone );
		wp_send_json_success( array( 'verify' => true ) );
	}

	/**
	 * Run IP allow-list and rate-limit checks. Terminates with a JSON error
	 * response when either check fails; returns void on success.
	 *
	 * @return void
	 */
	private function guard(): void {
		$ip         = Ip::current();
		$restricted = (string) $this->settings->get( 'restricted_ips', '' );

		if ( '' !== trim( $restricted ) ) {
			foreach ( preg_split( '/\R+/', $restricted ) as $needle ) {
				$needle = trim( (string) $needle );
				if ( '' !== $needle && $needle === $ip ) {
					wp_send_json_error( array( 'code' => 'sendsms_dashboard_ip_restricted' ), 403 );
				}
			}
		}

		$limiter = new IpRateLimit( $this->settings, $this->ips );
		if ( $limiter->is_too_many( $ip ) ) {
			wp_send_json_error( array( 'code' => 'sendsms_dashboard_rate_limited' ), 429 );
		}
	}
}
