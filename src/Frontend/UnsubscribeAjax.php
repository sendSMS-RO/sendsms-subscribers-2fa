<?php
/**
 * Public AJAX handler for newsletter unsubscription requests.
 *
 * Registered for both authenticated and unauthenticated requests so that any
 * visitor may unsubscribe via a widget. When phone verification is active the
 * handler sends a one-time code via SMS and returns {@code verify: true}; the
 * front-end must then collect the code and call the {@see VerifyCodeAjax}
 * handler to finalise the deletion.
 *
 * Mirrors the v1.x {@see Sendsms_Dashboard_Public::unsubscribe_from_newsletter()}
 * and is intentionally kept outside {@code is_admin()} so that it is reachable
 * through wp-admin/admin-ajax.php from both logged-in and guest contexts.
 *
 * @package SendSMS\Dashboard\Frontend
 */

namespace SendSMS\Dashboard\Frontend;

use SendSMS\Dashboard\Api\Client;
use SendSMS\Dashboard\Storage\IpRepository;
use SendSMS\Dashboard\Storage\Settings;
use SendSMS\Dashboard\Storage\SubscriberRepository;
use SendSMS\Dashboard\Support\Ip;
use SendSMS\Dashboard\Support\IpRateLimit;
use SendSMS\Dashboard\Support\PhoneNumber;
use SendSMS\Dashboard\Support\VerificationCode;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the {@code sendsms_dashboard_unsubscribe} AJAX action.
 *
 * The guard sequence (nonce → phone → existence → IP allow-list → rate limit)
 * mirrors v1.x unsubscribe_from_newsletter. The sendsms.ro remote contact is
 * deleted alongside the local DB row when the subscriber was previously synced,
 * preserving v1.x parity. Verification uses the same
 * {@code subscribe_verification_message} template as the subscribe flow (v1.x
 * has no separate unsubscribe_verification_message key).
 */
final class UnsubscribeAjax {

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
	 * @param VerificationCode     $codes    OTP code generator/verifier (unused directly; reserved for future use).
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
		add_action( 'wp_ajax_sendsms_dashboard_unsubscribe', array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_sendsms_dashboard_unsubscribe', array( $this, 'handle' ) );
	}

	/**
	 * Process an unsubscribe request.
	 *
	 * Order of checks mirrors v1.x unsubscribe_from_newsletter:
	 * 1. Nonce
	 * 2. Phone normalization
	 * 3. Existence check (404 when not subscribed)
	 * 4. IP allow-list + rate limit
	 * 5. Verification branch
	 *
	 * @return void
	 */
	public function handle(): void {
		// 1. Nonce.
		if ( ! check_ajax_referer( 'sendsms-security-nonce', 'security', false ) ) {
			wp_send_json_error( array( 'code' => 'sendsms_dashboard_bad_nonce' ), 403 );
		}

		// 2. Phone.
		$raw   = isset( $_POST['phone_number'] ) ? sanitize_text_field( wp_unslash( $_POST['phone_number'] ) ) : '';
		$cc    = $this->settings->get_esc( 'cc', 'INT' );
		$phone = PhoneNumber::normalize( $raw, $cc );
		if ( '' === $phone ) {
			wp_send_json_error( array( 'code' => 'sendsms_dashboard_invalid_phone' ), 400 );
		}

		// 3. Must be a subscriber.
		$row = $this->repo->find( $phone );
		if ( null === $row ) {
			wp_send_json_error( array( 'code' => 'sendsms_dashboard_not_subscribed' ), 404 );
		}

		// 4. IP allow-list + rate limit.
		$this->guard();

		// 5. Verification branch.
		$verify_on = ! empty( $this->settings->get( 'subscribe_phone_verification' ) );

		if ( ! $verify_on ) {
			// Delete remote contact when the subscriber was previously synced (v1.x parity).
			$synced = isset( $row['synced'] ) ? $row['synced'] : null;
			if ( ! is_null( $synced ) ) {
				$this->api->delete_contact( (int) $synced );
			}
			$this->repo->delete( $phone );
			wp_send_json_success( array( 'verify' => false ) );
		}

		// v1.x reuses subscribe_verification_message for unsubscribe — no separate key.
		$body   = $this->settings->get_esc( 'subscribe_verification_message', '' );
		$result = $this->api->message_send( false, false, $phone, $body, 'code', '_unsub' );

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
