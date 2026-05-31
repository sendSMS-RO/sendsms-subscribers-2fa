<?php
/**
 * Public AJAX handler for newsletter subscription requests.
 *
 * Registered for both authenticated and unauthenticated requests so that guests
 * may subscribe from any widget placed on the public site. When phone
 * verification is active the handler sends a one-time code via SMS and returns
 * {@code verify: true}; the front-end must then collect the code and call the
 * {@see VerifyCodeAjax} handler to complete the subscription.
 *
 * Mirrors the v1.x {@see Sendsms_Dashboard_Public::subscribe_to_newsletter()}
 * and is intentionally kept outside {@code is_admin()} so that it is reachable
 * through wp-admin/admin-ajax.php from both logged-in and guest contexts.
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
 * Handles the {@code rosendsms_dash_subscribe} AJAX action.
 *
 * The guard sequence (nonce → IP allow-list → rate limit) runs before any
 * business logic. Phone normalization happens after the guard so that a bad
 * phone never consumes a rate-limit slot. Verification is gated on the
 * {@code subscribe_phone_verification} plugin setting.
 */
final class SubscribeAjax {

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
	 * OTP code generator/verifier.
	 *
	 * @var VerificationCode
	 */
	private $codes;

	/**
	 * Sendsms.ro API client.
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
	 * @param VerificationCode     $codes    OTP code generator/verifier.
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
	 * Must be called before the {@code wp_ajax} actions fire (i.e. before
	 * {@code admin_init} or the equivalent).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_rosendsms_dash_subscribe', array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_rosendsms_dash_subscribe', array( $this, 'handle' ) );
	}

	/**
	 * Process a subscribe request.
	 *
	 * Order of checks mirrors v1.x subscribe_to_newsletter:
	 * 1. Nonce
	 * 2. GDPR consent flag
	 * 3. Required name fields
	 * 4. Phone normalization
	 * 5. Duplicate check
	 * 6. IP allow-list + rate limit
	 * 7. Verification branch
	 *
	 * @return void
	 */
	public function handle(): void {
		// 1. Nonce.
		if ( ! check_ajax_referer( 'rosendsms_dash_nonce', 'security', false ) ) {
			wp_send_json_error( array( 'code' => 'sendsms_dashboard_bad_nonce' ), 403 );
		}

		// 2. GDPR.
		$gdpr = isset( $_POST['gdpr'] ) ? sanitize_text_field( wp_unslash( $_POST['gdpr'] ) ) : 'false';
		if ( 'false' === $gdpr ) {
			wp_send_json_error( array( 'code' => 'sendsms_dashboard_nogdpr' ), 400 );
		}

		// 3. Required name fields (mirrors v1.x).
		$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		if ( '' === $first_name ) {
			wp_send_json_error( array( 'code' => 'sendsms_dashboard_field_first_name' ), 400 );
		}
		$last_name = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		if ( '' === $last_name ) {
			wp_send_json_error( array( 'code' => 'sendsms_dashboard_field_last_name' ), 400 );
		}

		// 4. Phone.
		$raw   = isset( $_POST['phone_number'] ) ? sanitize_text_field( wp_unslash( $_POST['phone_number'] ) ) : '';
		$cc    = $this->settings->get_esc( 'cc', 'INT' );
		$phone = PhoneNumber::normalize( $raw, $cc );
		if ( '' === $phone ) {
			wp_send_json_error( array( 'code' => 'sendsms_dashboard_invalid_phone' ), 400 );
		}

		// 5. Duplicate check (mirrors v1.x — checked before IP guards).
		if ( null !== $this->repo->find( $phone ) ) {
			wp_send_json_error( array( 'code' => 'sendsms_dashboard_already_subscribed' ), 409 );
		}

		// 6. IP allow-list + rate limit.
		$this->guard();

		// 7. Verification branch.
		$verify_on = ! empty( $this->settings->get( 'subscribe_phone_verification' ) );

		if ( ! $verify_on ) {
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
			wp_send_json_success( array( 'verify' => false ) );
		}

		$body   = $this->settings->get_esc( 'subscribe_verification_message', '' );
		$result = $this->api->message_send( false, false, $phone, $body, 'code', '_sub' );

		if ( $result->is_success() ) {
			wp_send_json_success( array( 'verify' => true ) );
		}

		wp_send_json_error( array( 'code' => 'sendsms_dashboard_internal_error' ), 500 );
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
