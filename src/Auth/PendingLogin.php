<?php
/**
 * Transient-backed store for in-progress 2FA login payloads.
 *
 * Each pending login is identified by a URL-safe 32-character token that is
 * handed to the browser as a query-string parameter. The transient TTL matches
 * the five-minute window the user has to complete the 2FA challenge.
 *
 * @package SendSMS\Dashboard\Auth
 * @since   2.0.0
 */

namespace SendSMS\Dashboard\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Manages transient-backed pending 2FA login sessions.
 *
 * Wraps WordPress `set_transient` / `get_transient` / `delete_transient` with
 * a fixed key prefix and a 5-minute TTL so callers never have to repeat those
 * implementation details.
 *
 * @since 2.0.0
 */
final class PendingLogin {

	/**
	 * Transient key prefix applied to every token.
	 *
	 * @var string
	 */
	private const PREFIX = 'sendsms_dashboard_2fa_pending_';

	/**
	 * Time-to-live for each pending-login transient, in seconds.
	 *
	 * Five minutes matches the window the user has to complete the 2FA
	 * challenge after the SMS code is sent.
	 *
	 * @var int
	 */
	private const TTL = 300;

	/**
	 * Generate a fresh, URL-safe 32-character token.
	 *
	 * Uses `wp_generate_password()` restricted to alphanumeric characters so
	 * the token is safe to embed in a URL without encoding.
	 *
	 * @since  2.0.0
	 * @return string A 32-character alphanumeric token.
	 */
	public static function fresh_token(): string {
		return wp_generate_password( 32, false, false );
	}

	/**
	 * Store the pending-auth payload against the given token.
	 *
	 * @since  2.0.0
	 * @param  string $token   Fresh token returned by {@see self::fresh_token()}.
	 * @param  array  $payload Associative array, e.g.:
	 *                         [
	 *                           'user_id'     => int,
	 *                           'redirect_to' => string,
	 *                           'remember'    => bool,
	 *                           'phone_hash'  => string,
	 *                           'attempts'    => int,
	 *                           'last_sms_at' => int,
	 *                         ].
	 * @return bool True on success, false on failure.
	 */
	public function store( string $token, array $payload ): bool {
		return set_transient( self::PREFIX . $token, $payload, self::TTL );
	}

	/**
	 * Read the payload for a given token.
	 *
	 * Returns null when the transient is missing or expired, so callers can
	 * distinguish "valid session" from "timed-out / tampered token" without
	 * a separate existence check.
	 *
	 * @since  2.0.0
	 * @param  string $token The token previously passed to {@see self::store()}.
	 * @return array|null The stored payload, or null if missing or expired.
	 */
	public function get( string $token ): ?array {
		$value = get_transient( self::PREFIX . $token );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Overwrite an existing pending payload and reset the 5-minute TTL.
	 *
	 * Typical use: bump the attempt counter or update `last_sms_at` without
	 * invalidating the token.
	 *
	 * @since  2.0.0
	 * @param  string $token   The token previously passed to {@see self::store()}.
	 * @param  array  $payload The updated payload array.
	 * @return bool True on success, false on failure.
	 */
	public function update( string $token, array $payload ): bool {
		return set_transient( self::PREFIX . $token, $payload, self::TTL );
	}

	/**
	 * Delete a pending payload, invalidating the token immediately.
	 *
	 * Called after successful verification or when the session is abandoned.
	 *
	 * @since  2.0.0
	 * @param  string $token The token to invalidate.
	 * @return bool True if the transient was deleted, false otherwise.
	 */
	public function delete( string $token ): bool {
		return (bool) delete_transient( self::PREFIX . $token );
	}
}
