<?php
/**
 * Resolves the phone number for a WordPress user.
 *
 * Mirrors v1.x SendSMSFunctions::get_user_phone: iterates the meta keys
 * listed in the 'phone_meta' setting and returns the first value accepted
 * by PhoneNumber::normalize. Falls back to 'sendsms_phone_number' (always
 * appended by Settings::user_phone_meta_keys).
 *
 * @package Rosendsms\Dashboard\Support
 */

namespace Rosendsms\Dashboard\Support;

use Rosendsms\Dashboard\Storage\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a WordPress user's phone number from configurable user meta keys.
 *
 * The list of meta keys to check is controlled by the 'phone_meta' setting
 * (one key per line, managed via Settings::user_phone_meta_keys). The
 * 'sendsms_phone_number' meta key is always tried last as a fallback.
 */
final class UserPhone {

	/**
	 * Resolve the user's phone number. Mirrors v1.x get_user_phone.
	 *
	 * Iterates the meta keys from Settings::user_phone_meta_keys() and returns
	 * the first value that PhoneNumber::normalize accepts as non-empty. Returns
	 * an empty string when no valid phone is found across all configured keys.
	 *
	 * @param int      $user_id  WordPress user ID.
	 * @param Settings $settings Plugin settings instance.
	 * @return string Resolved phone value (not yet normalized), or '' when not found.
	 */
	public static function resolve( int $user_id, Settings $settings ): string {
		$cc   = $settings->get_esc( 'cc', 'INT' );
		$keys = $settings->user_phone_meta_keys();

		foreach ( $keys as $key ) {
			$value = (string) get_user_meta( $user_id, $key, true );
			if ( '' !== trim( $value ) && '' !== PhoneNumber::normalize( $value, $cc ) ) {
				return $value;
			}
		}

		return '';
	}
}
