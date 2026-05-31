<?php
/**
 * Phone number normalization utility.
 *
 * Provides a single static entry point for cleaning and prefixing phone numbers
 * before they are stored in the database or forwarded to the sendsms.ro API.
 * Mirrors the v1.x {@see SendSMSFunctions::validate_phone()} and
 * {@see SendSMSFunctions::clear_phone_number()} methods exactly so that
 * existing data and behaviour are preserved on upgrade.
 *
 * @package Rosendsms\Dashboard\Support
 */

namespace Rosendsms\Dashboard\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes raw phone number strings for storage and API submission.
 *
 * All phone values that enter the plugin — from widget forms, AJAX handlers,
 * the API client, mass-send recipient lists, and the 2FA flow — must pass
 * through {@see PhoneNumber::normalize()} before touching the database or the
 * sendsms.ro REST API.
 */
final class PhoneNumber {

	/**
	 * Normalize a phone number for a given country-code setting.
	 *
	 * Strips non-numeric characters (via the same three-step pipeline as v1.x),
	 * removes leading zeros, then optionally prepends the country dial prefix
	 * unless the number already starts with it.  Returns an empty string when
	 * nothing usable remains after cleaning.
	 *
	 * Mirrors v1.x {@see SendSMSFunctions::validate_phone()} +
	 * {@see SendSMSFunctions::clear_phone_number()} exactly, including the
	 * FILTER_SANITIZE_NUMBER_INT → str_replace → preg_replace pipeline in
	 * {@see PhoneNumber::clear()}.
	 *
	 * @param string $raw Raw phone number (any format).
	 * @param string $cc  Country code setting value (e.g. 'RO', 'INT').
	 * @return string Normalized phone number, or '' if the input was unparseable.
	 */
	public static function normalize( string $raw, string $cc ): string {
		$digits = self::clear( $raw );
		$digits = ltrim( $digits, '0' );

		if ( '' === $digits ) {
			return '';
		}

		if ( 'INT' === $cc ) {
			return $digits;
		}

		$map = CountryCodes::map();
		if ( ! isset( $map[ $cc ] ) || '' === $map[ $cc ] ) {
			return $digits;
		}

		$prefix = $map[ $cc ];
		if ( preg_match( '/^' . preg_quote( $prefix, '/' ) . '/', $digits ) ) {
			return $digits;
		}

		return $prefix . $digits;
	}

	/**
	 * Strip a phone number of every non-numeric character.
	 *
	 * Mirrors v1.x {@see SendSMSFunctions::clear_phone_number()} verbatim:
	 * FILTER_SANITIZE_NUMBER_INT preserves '+' and '-' (and digits), then
	 * str_replace removes '+' and '-', then preg_replace removes any remaining
	 * non-digit characters (e.g. spaces left by the INT filter).  Do not
	 * collapse these three steps into a single regex.
	 *
	 * @param string $raw Raw phone number.
	 * @return string Digits only.
	 */
	private static function clear( string $raw ): string {
		$stripped = str_replace( array( '+', '-' ), '', (string) filter_var( $raw, FILTER_SANITIZE_NUMBER_INT ) );
		$stripped = preg_replace( '/[^0-9]/', '', $stripped );
		return is_string( $stripped ) ? $stripped : '';
	}
}
