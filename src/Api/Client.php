<?php
/**
 * Sendsms.ro REST API client.
 *
 * Wraps all sendsms.ro API calls and ensures every send-style call writes a
 * history row via {@see HistoryRepository}. Callers receive an {@see Response}
 * value object — exceptions are never thrown. All network I/O goes through
 * WordPress's wp_remote_get / wp_remote_post so that the WP HTTP API's timeout,
 * proxy, and SSL settings apply uniformly.
 *
 * @package Rosendsms\Dashboard\Api
 */

namespace Rosendsms\Dashboard\Api;

use Rosendsms\Dashboard\Storage\HistoryRepository;
use Rosendsms\Dashboard\Storage\Settings;
use Rosendsms\Dashboard\Support\PhoneNumber;
use Rosendsms\Dashboard\Support\VerificationCode;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the sendsms.ro REST API.
 *
 * Mirrors the v1.x SendSMS class but returns {@see Response} value objects
 * instead of raw decoded arrays, and builds batch CSV in memory instead of
 * writing a temporary file (required for WordPress Plugin Check compatibility).
 */
final class Client {

	/**
	 * Base URL for all sendsms.ro API calls.
	 */
	private const BASE_URL = 'https://api.sendsms.ro/json';

	/**
	 * History repository used to persist every send.
	 *
	 * @var HistoryRepository
	 */
	private $history;

	/**
	 * Plugin settings store (reads username, password, label, cc).
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Verification code generator/verifier for OTP flows.
	 *
	 * @var VerificationCode
	 */
	private $codes;

	/**
	 * Constructor.
	 *
	 * @param HistoryRepository $history  Persistence layer for SMS history rows.
	 * @param Settings          $settings Plugin settings store.
	 * @param VerificationCode  $codes    Verification code service for OTP flows.
	 */
	public function __construct( HistoryRepository $history, Settings $settings, VerificationCode $codes ) {
		$this->history  = $history;
		$this->settings = $settings;
		$this->codes    = $codes;
	}

	/**
	 * Send a single SMS. Mirrors v1.x SendSMS::message_send.
	 *
	 * When $type is 'code', a 5-character OTP code is generated, stored in a
	 * signed cookie, and substituted into the message body in place of '{code}'.
	 * The gdpr flag is ignored for code sends (v1.x parity).
	 *
	 * One history row is written regardless of API outcome.
	 *
	 * @param bool   $short   Whether to ask sendsms.ro to shorten URLs in the body.
	 * @param bool   $gdpr    Whether to use the message_send_gdpr action (appends an unsubscribe footer).
	 * @param string $to_raw  Raw phone number; will be normalized via PhoneNumber.
	 * @param string $content Body. When $type == 'code', should contain '{code}' (one is appended if missing).
	 * @param string $type    'code' for verification flows, anything else for plain sends.
	 * @param string $suffix  Cookie suffix passed to VerificationCode when $type == 'code'. Example values: '_sub', '_unsub', '_2fa'.
	 * @return Response
	 */
	public function message_send( bool $short, bool $gdpr, string $to_raw, string $content, string $type, string $suffix = '' ): Response {
		$cc       = $this->settings->get_esc( 'cc', 'INT' );
		$to       = PhoneNumber::normalize( $to_raw, $cc );
		$username = $this->settings->get_esc( 'username', '' );
		$password = $this->settings->get_esc( 'password', '' );
		$label    = $this->settings->get_esc( 'label', '1898' );

		$sent_content = $content;
		$action       = $gdpr ? 'message_send_gdpr' : 'message_send';
		$extra_qs     = array();

		if ( 'code' === strtolower( $type ) ) {
			if ( false === strpos( $sent_content, '{code}' ) ) {
				$sent_content .= '{code}';
			}
			$code         = $this->codes->generate( $to, $suffix );
			$sent_content = str_replace( '{code}', $code, $sent_content );
			$action       = 'message_send'; // v1.x does not use _gdpr for code flow.
		} else {
			$extra_qs['short'] = $short ? 'true' : 'false';
		}

		$qs = http_build_query(
			array_merge(
				array(
					'action'   => $action,
					'username' => $username,
					'password' => $password,
					'from'     => $label,
					'to'       => $to,
					'text'     => $sent_content,
				),
				$extra_qs
			)
		);

		$response = $this->get( $qs );

		$this->history->insert(
			array(
				'phone'   => $to,
				'status'  => isset( $response->data()['status'] ) ? (string) $response->data()['status'] : '',
				'message' => isset( $response->data()['message'] ) ? (string) $response->data()['message'] : '',
				'details' => isset( $response->data()['details'] ) ? (string) $response->data()['details'] : '',
				'content' => $sent_content,
				'type'    => $type,
				'sent_on' => current_time( 'mysql' ),
			)
		);

		return $response;
	}

	/**
	 * Send a batch campaign via the batch_create action. Mirrors v1.x SendSMS::send_batch.
	 *
	 * The CSV is built in memory (string buffer) and POSTed directly — no
	 * temporary file is written to disk. This is an intentional improvement over
	 * v1.x required for WordPress Plugin Check compliance.
	 *
	 * One history row tagged 'Batch Campaign' is written after the POST.
	 *
	 * @param string[] $phones  Already-normalized phone numbers.
	 * @param string   $message The body sent to every recipient.
	 * @return Response
	 */
	public function send_batch( array $phones, string $message ): Response {
		$username = $this->settings->get_esc( 'username', '' );
		$password = $this->settings->get_esc( 'password', '' );
		$label    = $this->settings->get_esc( 'label', '1898' );

		// Build CSV in memory.
		$rows   = array();
		$rows[] = 'message,to,from';
		foreach ( $phones as $phone ) {
			$rows[] = $this->csv_row( array( $message, $phone, $label ) );
		}
		$csv = implode( "\n", $rows ) . "\n";

		$name       = 'WordPress - ' . get_site_url() . ' - ' . uniqid();
		$start_time = current_time( 'mysql' );

		$url = self::BASE_URL . '?' . http_build_query(
			array(
				'action'     => 'batch_create',
				'username'   => $username,
				'password'   => $password,
				'start_time' => $start_time,
				'name'       => $name,
			)
		);

		$raw = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array( 'url' => get_site_url() ),
				'body'    => array( 'data' => $csv ),
			)
		);

		$response = $this->parse( $raw );

		$this->history->insert(
			array(
				'phone'   => __( 'Go to hub.sendsms.ro', 'sendsms-dashboard' ),
				'status'  => isset( $response->data()['status'] ) ? (string) $response->data()['status'] : '',
				'message' => isset( $response->data()['message'] ) ? (string) $response->data()['message'] : '',
				'details' => isset( $response->data()['details'] ) ? (string) $response->data()['details'] : '',
				/* translators: %s is the sendsms.ro batch campaign name. */
				'content' => sprintf( __( 'We created your campaign. Go and check the batch called: %s', 'sendsms-dashboard' ), $name ),
				'type'    => __( 'Batch Campaign', 'sendsms-dashboard' ),
				'sent_on' => current_time( 'mysql' ),
			)
		);

		return $response;
	}

	/**
	 * Read account balance. Mirrors v1.x SendSMS::get_user_balance.
	 *
	 * Does not write a history row.
	 *
	 * @return Response
	 */
	public function get_user_balance(): Response {
		$qs = http_build_query(
			array(
				'action'   => 'user_get_balance',
				'username' => $this->settings->get_esc( 'username', '' ),
				'password' => $this->settings->get_esc( 'password', '' ),
			)
		);
		return $this->get( $qs );
	}

	/**
	 * Create an address-book group on sendsms.ro. Group name is "WordPress - {site_url}".
	 *
	 * Mirrors v1.x SendSMS::create_group. Does not write a history row.
	 *
	 * @return Response
	 */
	public function create_group(): Response {
		$qs = http_build_query(
			array(
				'action'   => 'address_book_group_add',
				'username' => $this->settings->get_esc( 'username', '' ),
				'password' => $this->settings->get_esc( 'password', '' ),
				'name'     => 'WordPress - ' . get_site_url(),
			)
		);
		return $this->get( $qs );
	}

	/**
	 * Delete an address-book group by remote id.
	 *
	 * Mirrors v1.x SendSMS::delete_group. Does not write a history row.
	 *
	 * @param int $group_id Remote group ID on sendsms.ro.
	 * @return Response
	 */
	public function delete_group( int $group_id ): Response {
		$qs = http_build_query(
			array(
				'action'   => 'address_book_group_delete',
				'username' => $this->settings->get_esc( 'username', '' ),
				'password' => $this->settings->get_esc( 'password', '' ),
				'group_id' => $group_id,
			)
		);
		return $this->get( $qs );
	}

	/**
	 * List all address-book groups.
	 *
	 * Mirrors v1.x SendSMS::get_groups. Does not write a history row.
	 *
	 * @return Response
	 */
	public function get_groups(): Response {
		$qs = http_build_query(
			array(
				'action'   => 'address_book_groups_get_list',
				'username' => $this->settings->get_esc( 'username', '' ),
				'password' => $this->settings->get_esc( 'password', '' ),
			)
		);
		return $this->get( $qs );
	}

	/**
	 * Add a contact to an address-book group.
	 *
	 * Mirrors v1.x SendSMS::add_contact. Does not write a history row.
	 *
	 * @param int    $group_id     Remote group ID on sendsms.ro.
	 * @param string $first_name   Contact first name.
	 * @param string $last_name    Contact last name.
	 * @param string $phone_number Normalized phone number.
	 * @return Response
	 */
	public function add_contact( int $group_id, string $first_name, string $last_name, string $phone_number ): Response {
		$qs = http_build_query(
			array(
				'action'       => 'address_book_contact_add',
				'username'     => $this->settings->get_esc( 'username', '' ),
				'password'     => $this->settings->get_esc( 'password', '' ),
				'group_id'     => $group_id,
				'phone_number' => $phone_number,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
			)
		);
		return $this->get( $qs );
	}

	/**
	 * Delete a contact by remote id.
	 *
	 * Mirrors v1.x SendSMS::delete_contact. Does not write a history row.
	 *
	 * @param int $contact_id Remote contact ID on sendsms.ro.
	 * @return Response
	 */
	public function delete_contact( int $contact_id ): Response {
		$qs = http_build_query(
			array(
				'action'     => 'address_book_contact_delete',
				'username'   => $this->settings->get_esc( 'username', '' ),
				'password'   => $this->settings->get_esc( 'password', '' ),
				'contact_id' => $contact_id,
			)
		);
		return $this->get( $qs );
	}

	/**
	 * Issue a GET request to the sendsms.ro API and return a parsed Response.
	 *
	 * @param string $query_string Pre-encoded query string (no leading '?').
	 * @return Response
	 */
	private function get( string $query_string ): Response {
		$raw = wp_remote_get(
			self::BASE_URL . '?' . $query_string,
			array(
				'timeout' => 15,
				'headers' => array( 'url' => get_site_url() ),
			)
		);
		return $this->parse( $raw );
	}

	/**
	 * Parse a wp_remote_get / wp_remote_post result into an Api\Response.
	 *
	 * Maps WP_Error, non-2xx HTTP responses, unparseable JSON, and negative
	 * sendsms.ro status codes to Response::failure(). All other responses yield
	 * Response::success().
	 *
	 * @param mixed $raw Return value from wp_remote_get() or wp_remote_post().
	 * @return Response
	 */
	private function parse( $raw ): Response {
		if ( is_wp_error( $raw ) ) {
			return Response::failure( $raw->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $raw );
		$body   = (string) wp_remote_retrieve_body( $raw );

		if ( $status < 200 || $status >= 300 ) {
			/* translators: %d is an HTTP response status code. */
			return Response::failure( sprintf( __( 'HTTP %d', 'sendsms-dashboard' ), $status ), $status, $body );
		}

		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			return Response::failure( __( 'Unparseable API response.', 'sendsms-dashboard' ), $status, $body );
		}

		// sendsms.ro convention: numeric status < 0 indicates an error.
		if ( isset( $json['status'] ) && is_numeric( $json['status'] ) && (int) $json['status'] < 0 ) {
			$error = (string) ( $json['message'] ?? $json['details'] ?? __( 'API error', 'sendsms-dashboard' ) );
			return Response::failure( $error, $status, $body );
		}

		return Response::success( $json, $status, $body );
	}

	/**
	 * Encode a single CSV row, quoting cells that contain commas, double-quotes,
	 * or newlines (RFC 4180).
	 *
	 * @param string[] $cells Values to encode as one CSV line.
	 * @return string Encoded CSV line without a trailing newline.
	 */
	private function csv_row( array $cells ): string {
		$escaped = array();
		foreach ( $cells as $cell ) {
			$cell = (string) $cell;
			if ( false !== strpos( $cell, ',' ) || false !== strpos( $cell, '"' ) || false !== strpos( $cell, "\n" ) ) {
				$cell = '"' . str_replace( '"', '""', $cell ) . '"';
			}
			$escaped[] = $cell;
		}
		return implode( ',', $escaped );
	}
}
