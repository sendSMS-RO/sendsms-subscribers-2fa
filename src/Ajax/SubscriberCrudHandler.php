<?php
/**
 * AJAX handler for subscriber add / update / delete operations.
 *
 * Exposes three admin-only AJAX endpoints that replace the single v1.x
 * `update_a_subscriber` action (which used a `mode` field to fan out).
 * Breaking them into distinct actions makes the intent explicit and allows
 * each operation to be guarded and validated independently.
 *
 * Ported from v1.x {@see Sendsms_Dashboard_Admin::update_a_subscriber()}.
 *
 * Registered actions (all `wp_ajax_*` only — no `nopriv` variant):
 *  - wp_ajax_rosendsms_dash_subscriber_add
 *  - wp_ajax_rosendsms_dash_subscriber_update
 *  - wp_ajax_rosendsms_dash_subscriber_delete
 *
 * @package Rosendsms\Dashboard\Ajax
 */

namespace Rosendsms\Dashboard\Ajax;

use Rosendsms\Dashboard\Storage\Settings;
use Rosendsms\Dashboard\Storage\SubscriberRepository;
use Rosendsms\Dashboard\Support\Ip;
use Rosendsms\Dashboard\Support\PhoneNumber;

defined( 'ABSPATH' ) || exit;

/**
 * Handles add, update, and delete AJAX requests for SMS subscribers.
 *
 * All three public handler methods share a common guard sequence:
 *  1. Nonce check (`rosendsms_dash_nonce` / `security` field).
 *  2. Capability check (`manage_options`).
 *  3. Phone normalisation via {@see PhoneNumber::normalize()}.
 *
 * On success each handler returns a JSON envelope containing the affected
 * phone and, where relevant, the first/last name. On failure a structured
 * JSON error with a machine-readable `code` and HTTP status is returned.
 */
final class SubscriberCrudHandler {

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
	private $repo;

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings Plugin settings service.
	 * @param SubscriberRepository $repo     Subscriber repository.
	 */
	public function __construct( Settings $settings, SubscriberRepository $repo ) {
		$this->settings = $settings;
		$this->repo     = $repo;
	}

	/**
	 * Registers the WordPress AJAX action hooks.
	 *
	 * Call once during plugin boot inside an `is_admin()` guard so the
	 * `wp_ajax_*` hooks are not wasted on frontend requests.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_rosendsms_dash_subscriber_add', array( $this, 'add' ) );
		add_action( 'wp_ajax_rosendsms_dash_subscriber_update', array( $this, 'update' ) );
		add_action( 'wp_ajax_rosendsms_dash_subscriber_delete', array( $this, 'delete' ) );
	}

	/**
	 * Shared nonce + capability guard.
	 *
	 * Terminates the request with a 403 JSON error when either the nonce is
	 * invalid or the current user lacks `manage_options`. Because
	 * `wp_send_json_error()` calls `wp_die()` internally, this method only
	 * returns when both checks pass.
	 *
	 * @return void
	 */
	private function guard(): void {
		if ( ! check_ajax_referer( 'rosendsms_dash_nonce', 'security', false ) ) {
			wp_send_json_error(
				array(
					'code'    => 'sendsms_dashboard_bad_nonce',
					'message' => __( 'Security check failed.', 'sendsms-dashboard' ),
				),
				403
			);
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'sendsms_dashboard_forbidden',
					'message' => __( 'Forbidden.', 'sendsms-dashboard' ),
				),
				403
			);
		}
	}

	/**
	 * Read a phone field from POST and normalise it.
	 *
	 * Returns the normalised phone number string when the raw value can be
	 * parsed, or terminates the request with a 400 JSON error when the value
	 * is absent or cannot be normalised to a non-empty digits string.
	 *
	 * @param string $field POST field name to read. Default 'phone'.
	 * @return string Normalised phone number (never empty).
	 */
	private function read_phone( string $field = 'phone' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified in guard() before any call to this method.
		$raw  = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		$cc   = $this->settings->get_esc( 'cc', 'INT' );
		$norm = PhoneNumber::normalize( $raw, $cc );
		if ( '' === $norm ) {
			wp_send_json_error(
				array(
					'code'    => 'sendsms_dashboard_invalid_phone',
					'message' => __( 'Invalid phone number.', 'sendsms-dashboard' ),
				),
				400
			);
		}
		return $norm;
	}

	/**
	 * Handles `wp_ajax_rosendsms_dash_subscriber_add`.
	 *
	 * Expects POST fields: `security`, `phone`, and optionally `first_name`
	 * and `last_name`. Rejects with 409 when the phone already exists.
	 *
	 * @return void
	 */
	public function add(): void {
		$this->guard();

		$phone = $this->read_phone( 'phone' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard().
		$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard().
		$last_name = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';

		if ( null !== $this->repo->find( $phone ) ) {
			wp_send_json_error(
				array(
					'code'    => 'sendsms_dashboard_duplicate_phone',
					'message' => __( 'A subscriber with this phone already exists.', 'sendsms-dashboard' ),
				),
				409
			);
		}

		$ok = $this->repo->insert(
			$phone,
			array(
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'ip_address' => Ip::current(),
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below; parity with v1.x.
				'browser'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			)
		);

		if ( ! $ok ) {
			wp_send_json_error(
				array(
					'code'    => 'sendsms_dashboard_insert_failed',
					'message' => __( 'Failed to add subscriber.', 'sendsms-dashboard' ),
				),
				500
			);
		}

		wp_send_json_success(
			array(
				'phone'      => $phone,
				'first_name' => $first_name,
				'last_name'  => $last_name,
			)
		);
	}

	/**
	 * Handles `wp_ajax_rosendsms_dash_subscriber_update`.
	 *
	 * Expects POST fields: `security`, `phone` (new phone), and optionally
	 * `old_phone`, `first_name`, and `last_name`. When `old_phone` is absent
	 * or cannot be normalised, `phone` is treated as both old and new (i.e. an
	 * in-place update with no phone change).
	 *
	 * When the phone is changed, the old row is deleted and a new row is
	 * inserted to work around the PK constraint. The original `date`,
	 * `ip_address`, `browser`, and `synced` values are preserved.
	 *
	 * @return void
	 */
	public function update(): void {
		$this->guard();

		$new_phone = $this->read_phone( 'phone' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard().
		$old_phone_raw = isset( $_POST['old_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['old_phone'] ) ) : '';
		$cc            = $this->settings->get_esc( 'cc', 'INT' );
		$old_phone     = '' !== $old_phone_raw ? PhoneNumber::normalize( $old_phone_raw, $cc ) : '';

		// Fall back to treating the new phone as the existing PK when old_phone
		// was not provided or could not be normalised.
		if ( '' === $old_phone ) {
			$old_phone = $new_phone;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard().
		$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard().
		$last_name = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';

		$existing = $this->repo->find( $old_phone );
		if ( null === $existing ) {
			wp_send_json_error(
				array(
					'code'    => 'sendsms_dashboard_not_found',
					'message' => __( 'Subscriber not found.', 'sendsms-dashboard' ),
				),
				404
			);
		}

		if ( $old_phone !== $new_phone ) {
			// Phone (PK) is changing — guard against collision, then delete + re-insert.
			if ( null !== $this->repo->find( $new_phone ) ) {
				wp_send_json_error(
					array(
						'code'    => 'sendsms_dashboard_duplicate_phone',
						'message' => __( 'A subscriber with the new phone already exists.', 'sendsms-dashboard' ),
					),
					409
				);
			}

			$this->repo->delete( $old_phone );
			$this->repo->insert(
				$new_phone,
				array(
					'first_name' => $first_name,
					'last_name'  => $last_name,
					'date'       => isset( $existing['date'] ) ? (string) $existing['date'] : current_time( 'mysql' ),
					'ip_address' => isset( $existing['ip_address'] ) ? (string) $existing['ip_address'] : '',
					'browser'    => isset( $existing['browser'] ) ? (string) $existing['browser'] : '',
					'synced'     => isset( $existing['synced'] ) ? (int) $existing['synced'] : 0,
				)
			);
		} else {
			// Same phone — simple column update.
			$this->repo->update(
				$new_phone,
				array(
					'first_name' => $first_name,
					'last_name'  => $last_name,
				)
			);
		}

		wp_send_json_success(
			array(
				'phone'      => $new_phone,
				'first_name' => $first_name,
				'last_name'  => $last_name,
			)
		);
	}

	/**
	 * Handles `wp_ajax_rosendsms_dash_subscriber_delete`.
	 *
	 * Expects POST fields: `security` and `phone`. Returns 404 when the
	 * subscriber does not exist, 500 on a DB failure, or a success envelope
	 * with the deleted phone on success.
	 *
	 * @return void
	 */
	public function delete(): void {
		$this->guard();

		$phone = $this->read_phone( 'phone' );

		if ( null === $this->repo->find( $phone ) ) {
			wp_send_json_error(
				array(
					'code'    => 'sendsms_dashboard_not_found',
					'message' => __( 'Subscriber not found.', 'sendsms-dashboard' ),
				),
				404
			);
		}

		$ok = $this->repo->delete( $phone );

		if ( ! $ok ) {
			wp_send_json_error(
				array(
					'code'    => 'sendsms_dashboard_delete_failed',
					'message' => __( 'Failed to delete subscriber.', 'sendsms-dashboard' ),
				),
				500
			);
		}

		wp_send_json_success( array( 'phone' => $phone ) );
	}
}
