<?php
/**
 * Error payloads for the public AJAX endpoints.
 *
 * @package Rosendsms\Dashboard\Frontend
 */

namespace Rosendsms\Dashboard\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the `wp_send_json_error()` payloads for the public subscribe,
 * unsubscribe, and verify-code endpoints.
 *
 * Each payload carries a machine-readable `code` alongside a human `message`.
 * The codes keep their v1.x `sendsms_dashboard_` spelling because they are part
 * of the response contract that existing integrations match on — do not rename
 * them. The messages exist because `assets/js/public.js` renders `data.message`
 * when one is present and otherwise falls back to a single generic string,
 * which tells the visitor nothing about what to correct.
 */
final class AjaxError {

	/**
	 * Send an error payload for the given code. Ends the request.
	 *
	 * @param string $code   Machine-readable error code (`sendsms_dashboard_*`).
	 * @param int    $status HTTP status code to respond with.
	 * @return void
	 */
	public static function send( string $code, int $status ): void {
		wp_send_json_error(
			array(
				'code'    => $code,
				'message' => self::message( $code ),
			),
			$status
		);
	}

	/**
	 * Map an error code to the message shown to the visitor.
	 *
	 * @param string $code Machine-readable error code.
	 * @return string Translated message.
	 */
	private static function message( string $code ): string {
		switch ( $code ) {
			case 'sendsms_dashboard_bad_nonce':
				return __( 'Your session has expired. Please reload the page and try again.', 'sendsms-subscribers-2fa' );

			case 'sendsms_dashboard_nogdpr':
				return __( 'Please agree to the privacy policy to continue.', 'sendsms-subscribers-2fa' );

			case 'sendsms_dashboard_field_first_name':
				return __( 'Please enter your first name.', 'sendsms-subscribers-2fa' );

			case 'sendsms_dashboard_field_last_name':
				return __( 'Please enter your last name.', 'sendsms-subscribers-2fa' );

			case 'sendsms_dashboard_invalid_phone':
				return __( 'Please enter a valid phone number.', 'sendsms-subscribers-2fa' );

			case 'sendsms_dashboard_already_subscribed':
				return __( 'This phone number is already subscribed.', 'sendsms-subscribers-2fa' );

			case 'sendsms_dashboard_not_subscribed':
				return __( 'This phone number is not on the list.', 'sendsms-subscribers-2fa' );

			case 'sendsms_dashboard_cookie_expired':
				return __( 'Your verification code has expired. Please request a new one.', 'sendsms-subscribers-2fa' );

			case 'sendsms_dashboard_invalid_code':
				return __( 'That code is not correct. Please check it and try again.', 'sendsms-subscribers-2fa' );

			case 'sendsms_dashboard_rate_limited':
				return __( 'Too many attempts. Please wait a moment and try again.', 'sendsms-subscribers-2fa' );

			case 'sendsms_dashboard_ip_restricted':
				return __( 'Requests from your network are not allowed.', 'sendsms-subscribers-2fa' );

			case 'sendsms_dashboard_internal_error':
				return __( 'The message could not be sent. Please try again in a moment.', 'sendsms-subscribers-2fa' );

			// sendsms_dashboard_invalid_context means the request named a step
			// the handler does not know, which a visitor cannot act on.
			default:
				return __( 'Something went wrong. Please reload the page and try again.', 'sendsms-subscribers-2fa' );
		}
	}
}
