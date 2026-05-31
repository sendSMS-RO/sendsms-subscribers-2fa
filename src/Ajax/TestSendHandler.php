<?php
/**
 * AJAX handler for the Test Send form.
 *
 * Handles the `wp_ajax_rosendsms_dash_test_send` action, which is
 * triggered from the Test Send admin page. The handler validates the
 * request, delegates to {@see Api\Client::message_send()}, and returns
 * a JSON response indicating success or failure.
 *
 * Admin-only (no `wp_ajax_nopriv_*` counterpart).
 *
 * @package Rosendsms\Dashboard\Ajax
 */

namespace Rosendsms\Dashboard\Ajax;

use Rosendsms\Dashboard\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX handler for sending a test SMS from the admin.
 *
 * Ported from v1.x `Sendsms_Dashboard_Admin::send_a_test_sms()`.
 * Accepts `phone_number`, `message`, `gdpr`, and `short` POST fields
 * plus a `security` nonce.
 */
final class TestSendHandler {

	/**
	 * Sendsms.ro API client.
	 *
	 * @var Client
	 */
	private $api;

	/**
	 * Constructor.
	 *
	 * @param Client $api The API client used to dispatch the test SMS.
	 */
	public function __construct( Client $api ) {
		$this->api = $api;
	}

	/**
	 * Registers the WordPress AJAX action hook.
	 *
	 * Call once during plugin boot inside an `is_admin()` guard so the
	 * `wp_ajax_*` hook is not wasted on frontend requests.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_rosendsms_dash_test_send', array( $this, 'handle' ) );
	}

	/**
	 * Handles the AJAX request.
	 *
	 * Validates the nonce and capability, reads POST data, dispatches the
	 * SMS via the API client, and returns a JSON response.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! check_ajax_referer( 'rosendsms_dash_nonce', 'security', false ) ) {
			wp_send_json_error(
				array(
					'code'    => 'sendsms_dashboard_bad_nonce',
					'message' => __( 'Security check failed.', 'sendsms-subscribers-2fa' ),
				),
				403
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'sendsms_dashboard_forbidden',
					'message' => __( 'Forbidden.', 'sendsms-subscribers-2fa' ),
				),
				403
			);
		}

		$phone = isset( $_POST['phone_number'] ) ? sanitize_text_field( wp_unslash( $_POST['phone_number'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$body  = isset( $_POST['message'] ) ? wp_kses_post( wp_unslash( $_POST['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $phone || '' === $body ) {
			wp_send_json_error(
				array(
					'code'    => 'sendsms_dashboard_missing_input',
					'message' => __( 'Phone and message are required.', 'sendsms-subscribers-2fa' ),
				),
				400
			);
		}

		$short = ! empty( $_POST['short'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$gdpr  = ! empty( $_POST['gdpr'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$response = $this->api->message_send( $short, $gdpr, $phone, $body, 'TEST' );

		if ( ! $response->is_success() ) {
			wp_send_json_error(
				array(
					'code'    => 'sendsms_dashboard_send_failed',
					'message' => $response->error_message(),
					'data'    => $response->data(),
				),
				502
			);
		}

		wp_send_json_success(
			array(
				'sent' => 1,
				'data' => $response->data(),
			)
		);
	}
}
