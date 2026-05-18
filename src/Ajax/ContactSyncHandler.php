<?php
/**
 * Handles the admin-only AJAX action that pushes a single subscriber to the
 * sendsms.ro address book.
 *
 * Action:  wp_ajax_sendsms_dashboard_sync_contact  (logged-in admin only)
 * Nonce:   sendsms-security-nonce / security
 * POST:    phone  – raw phone string to look up and sync
 *
 * Group management mirrors v1.x exactly:
 *   - The remote group ID is cached in WP option `sendsms-dashboard-sync-group`.
 *   - On every call the cached ID is verified against the live group list;
 *     if the group no longer exists a new one is created and the option updated.
 *   - Subscribers with synced = 0 are added fresh.
 *   - Subscribers already synced (synced > 0) have their remote contact deleted
 *     first, then re-added so that name/phone changes propagate (full refresh).
 *
 * @package SendSMS\Dashboard\Ajax
 */

namespace SendSMS\Dashboard\Ajax;

use SendSMS\Dashboard\Api\Client;
use SendSMS\Dashboard\Storage\Settings;
use SendSMS\Dashboard\Storage\SubscriberRepository;
use SendSMS\Dashboard\Support\PhoneNumber;

defined( 'ABSPATH' ) || exit;

/**
 * Admin AJAX handler: sync a single subscriber to the sendsms.ro address book.
 *
 * @since 2.0.0
 */
final class ContactSyncHandler {

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Subscriber data-access object.
	 *
	 * @var SubscriberRepository
	 */
	private $repo;

	/**
	 * SendSMS.ro API client.
	 *
	 * @var Client
	 */
	private $api;

	/**
	 * WordPress option key used to persist the remote address-book group ID.
	 *
	 * Mirrors the v1.x key so existing installs retain their group assignment.
	 *
	 * @var string
	 */
	const GROUP_OPTION = 'sendsms-dashboard-sync-group';

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings Plugin settings.
	 * @param SubscriberRepository $repo     Subscriber repository.
	 * @param Client               $api      API client.
	 */
	public function __construct( Settings $settings, SubscriberRepository $repo, Client $api ) {
		$this->settings = $settings;
		$this->repo     = $repo;
		$this->api      = $api;
	}

	/**
	 * Register the wp_ajax action hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_sendsms_dashboard_sync_contact', array( $this, 'handle' ) );
	}

	/**
	 * Handle the AJAX request.
	 *
	 * Validates the nonce and capability, resolves the group (creating it when
	 * necessary), then pushes the subscriber to sendsms.ro. Already-synced
	 * subscribers have their remote record deleted and re-created so that any
	 * local name or phone changes are reflected on the remote side.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! check_ajax_referer( 'sendsms-security-nonce', 'security', false ) ) {
			wp_send_json_error( array( 'code' => 'sendsms_dashboard_bad_nonce' ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'sendsms_dashboard_forbidden' ), 403 );
		}

		// Normalize the submitted phone number.
		$raw   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$phone = PhoneNumber::normalize( $raw, $this->settings->get_esc( 'cc', 'INT' ) );

		if ( '' === $phone ) {
			wp_send_json_error( array( 'code' => 'sendsms_dashboard_invalid_phone' ), 400 );
		}

		// Verify the subscriber exists locally.
		$row = $this->repo->find( $phone );
		if ( null === $row ) {
			wp_send_json_error( array( 'code' => 'sendsms_dashboard_not_found' ), 404 );
		}

		// ------------------------------------------------------------------
		// Step 1 – resolve the remote address-book group (mirrors v1.x logic).
		// ------------------------------------------------------------------
		$group_id = (int) get_option( self::GROUP_OPTION, 0 );
		$found    = false;

		if ( ! $group_id ) {
			// No group stored yet – create one now.
			$create = $this->api->create_group();
			if ( ! $create->is_success() ) {
				wp_send_json_error(
					array(
						'code'    => 'sendsms_dashboard_group_create_failed',
						'message' => $create->error_message(),
					),
					502
				);
			}
			$group_id = (int) ( $create->data()['details'] ?? 0 );
		}

		if ( $group_id ) {
			// Verify the cached group still exists on sendsms.ro.
			$groups_resp = $this->api->get_groups();
			if ( ! $groups_resp->is_success() ) {
				wp_send_json_error(
					array(
						'code'    => 'sendsms_dashboard_group_list_failed',
						'message' => $groups_resp->error_message(),
					),
					502
				);
			}

			$group_list = $groups_resp->data()['details'] ?? array();
			if ( is_array( $group_list ) ) {
				foreach ( $group_list as $group ) {
					if ( isset( $group['id'] ) && (int) $group['id'] === $group_id ) {
						$found = true;
						break;
					}
				}
			}

			if ( ! $found ) {
				// Stored group no longer exists – create a fresh one.
				$create = $this->api->create_group();
				if ( ! $create->is_success() ) {
					wp_send_json_error(
						array(
							'code'    => 'sendsms_dashboard_group_create_failed',
							'message' => $create->error_message(),
						),
						502
					);
				}
				$group_id = (int) ( $create->data()['details'] ?? 0 );
			}
		}

		// ------------------------------------------------------------------
		// Step 2 – push the subscriber to sendsms.ro.
		// ------------------------------------------------------------------
		$synced_id = (int) ( $row['synced'] ?? 0 );

		// If the contact was previously synced, delete the remote record first
		// so that name/phone changes are propagated (full refresh).
		if ( $synced_id > 0 ) {
			$delete = $this->api->delete_contact( $synced_id );
			// A delete failure is non-fatal; we continue to re-add the contact.
			// This mirrors the spirit of v1.x, which never checked delete results.
		}

		$add_resp = $this->api->add_contact(
			$group_id,
			(string) ( $row['first_name'] ?? '' ),
			(string) ( $row['last_name'] ?? '' ),
			$phone
		);

		if ( ! $add_resp->is_success() ) {
			wp_send_json_error(
				array(
					'code'    => 'sendsms_dashboard_add_contact_failed',
					'message' => $add_resp->error_message(),
				),
				502
			);
		}

		$new_remote_id = (int) ( $add_resp->data()['details'] ?? 0 );

		// Persist the remote contact ID locally and update the group option.
		$this->repo->mark_synced( $phone, $new_remote_id );
		update_option( self::GROUP_OPTION, $group_id );

		wp_send_json_success(
			array(
				'phone'  => $phone,
				'synced' => $new_remote_id,
				'data'   => $add_resp->data(),
			)
		);
	}
}
