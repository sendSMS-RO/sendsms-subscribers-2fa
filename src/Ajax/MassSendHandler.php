<?php
/**
 * AJAX handler for the Mass Send form.
 *
 * Handles the `wp_ajax_rosendsms_dash_mass_send` action, which is
 * triggered from the Mass Send admin page. The handler resolves phone
 * numbers from either the plugin's subscriber table or from WordPress
 * user accounts (optionally filtered by role), normalises and deduplicates
 * the list, dispatches a batch SMS via {@see Api\Client::send_batch()},
 * and returns a JSON response with sent/skipped counts.
 *
 * Admin-only (no `wp_ajax_nopriv_*` counterpart).
 *
 * Ported from v1.x `Sendsms_Dashboard_Admin::send_mass_sms()`.
 *
 * @package Rosendsms\Dashboard\Ajax
 */

namespace Rosendsms\Dashboard\Ajax;

use Rosendsms\Dashboard\Api\Client;
use Rosendsms\Dashboard\Storage\Settings;
use Rosendsms\Dashboard\Storage\SubscriberRepository;
use Rosendsms\Dashboard\Support\PhoneNumber;
use Rosendsms\Dashboard\Support\UserPhone;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX handler for sending a mass SMS from the admin.
 *
 * Accepts `receiver_type`, `role`, `message`, `gdpr`, `short`, and
 * `security` POST fields. The `gdpr` and `short` flags are accepted for
 * parity with the form but are not forwarded to `send_batch()`, which
 * does not support per-message flags in v1.x.
 */
final class MassSendHandler {

	/**
	 * Plugin settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Subscriber database repository.
	 *
	 * @var SubscriberRepository
	 */
	private $subscribers;

	/**
	 * Sendsms.ro API client.
	 *
	 * @var Client
	 */
	private $api;

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings    Plugin settings service.
	 * @param SubscriberRepository $subscribers Subscriber repository.
	 * @param Client               $api         API client used to dispatch the batch SMS.
	 */
	public function __construct( Settings $settings, SubscriberRepository $subscribers, Client $api ) {
		$this->settings    = $settings;
		$this->subscribers = $subscribers;
		$this->api         = $api;
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
		add_action( 'wp_ajax_rosendsms_dash_mass_send', array( $this, 'handle' ) );
	}

	/**
	 * Handles the AJAX request.
	 *
	 * Validates the nonce and capability, resolves and normalises the
	 * recipient list, dispatches the batch SMS via the API client, and
	 * returns a JSON response indicating success or failure.
	 *
	 * @return void
	 */
	public function handle(): void {
		// 1. Nonce check.
		if ( ! check_ajax_referer( 'rosendsms_dash_nonce', 'security', false ) ) {
			wp_send_json_error(
				array(
					'code'    => 'sendsms_dashboard_bad_nonce',
					'message' => __( 'Security check failed.', 'sendsms-subscribers-2fa' ),
				),
				403
			);
		}

		// 2. Capability check.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'sendsms_dashboard_forbidden',
					'message' => __( 'Forbidden.', 'sendsms-subscribers-2fa' ),
				),
				403
			);
		}

		// 3. Validate message.
		$body = isset( $_POST['message'] ) ? wp_kses_post( wp_unslash( $_POST['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === trim( $body ) ) {
			wp_send_json_error(
				array(
					'code'    => 'sendsms_dashboard_missing_input',
					'message' => __( 'Message is required.', 'sendsms-subscribers-2fa' ),
				),
				400
			);
		}

		$receiver = isset( $_POST['receiver_type'] ) ? sanitize_key( wp_unslash( $_POST['receiver_type'] ) ) : 'subscribers'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$role     = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// 4. Resolve raw phone numbers from the chosen receiver source.
		$raw_phones = array();

		if ( 'users' === $receiver ) {
			$args = array(
				'number' => -1,
				'fields' => array( 'ID' ),
			);

			if ( 'all' !== $role && '' !== $role ) {
				$args['role'] = $role;
			}

			foreach ( get_users( $args ) as $u ) {
				$p = UserPhone::resolve( (int) $u->ID, $this->settings );
				if ( '' !== $p ) {
					$raw_phones[] = $p;
				}
			}
		} else {
			// Default: pull all subscribers from the plugin table.
			foreach ( $this->subscribers->paginate( array( 'per_page' => -1 ) ) as $row ) {
				if ( ! empty( $row['phone'] ) ) {
					$raw_phones[] = (string) $row['phone'];
				}
			}
		}

		// 5. Normalise and deduplicate.
		$cc      = $this->settings->get_esc( 'cc', 'INT' );
		$phones  = array();
		$skipped = 0;

		foreach ( $raw_phones as $raw ) {
			$normalized = PhoneNumber::normalize( $raw, $cc );
			if ( '' === $normalized ) {
				++$skipped;
				continue;
			}
			$phones[ $normalized ] = $normalized;
		}

		$phones = array_values( $phones );

		if ( empty( $phones ) ) {
			wp_send_json_error(
				array(
					'code'    => 'sendsms_dashboard_no_recipients',
					'message' => __( 'No valid recipients.', 'sendsms-subscribers-2fa' ),
				),
				400
			);
		}

		/*
		 * 6. Dispatch the batch. `gdpr` and `short` are accepted from the form
		 * for parity but are not forwarded — send_batch() does not support them.
		 */
		$response = $this->api->send_batch( $phones, $body );

		if ( ! $response->is_success() ) {
			wp_send_json_error(
				array(
					'code'    => 'sendsms_dashboard_batch_failed',
					'message' => $response->error_message(),
					'data'    => $response->data(),
				),
				502
			);
		}

		wp_send_json_success(
			array(
				'sent'    => count( $phones ),
				'skipped' => $skipped,
				'data'    => $response->data(),
			)
		);
	}
}
