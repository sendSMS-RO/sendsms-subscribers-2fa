<?php
/**
 * Generates and verifies SMS verification codes stored in signed cookies.
 *
 * Mirrors v1.x SendSMSFunctions::generateVerificationCode,
 * SendSMSFunctions::verifyVerificationCode, and SendSMSFunctions::random_str.
 * The cookie name is 'sendsms_subscribe_check' + $suffix, where $suffix carries
 * a leading underscore (e.g. '_sub', '_unsub', '_2fa'). An empty suffix is legal.
 *
 * @package SendSMS\Dashboard\Support
 */

namespace SendSMS\Dashboard\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Generates and verifies 5-character alphanumeric SMS verification codes.
 *
 * The code is bound to a phone number via a hashed cookie so it cannot be
 * replayed against a different phone. The caller is responsible for checking
 * the WordPress nonce before invoking verify().
 */
final class VerificationCode {

	/**
	 * Cookie name prefix shared by all verification contexts.
	 */
	private const COOKIE_PREFIX = 'sendsms_subscribe_check';

	/**
	 * Cookie lifetime in seconds (1 hour). Mirrors v1.x `time() + 60 * 60`.
	 */
	private const TTL_SECONDS = 3600;

	/**
	 * Character pool used when drawing random code characters.
	 */
	private const KEYSPACE = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

	/**
	 * Generate a 5-character alphanumeric code, store its hash in the verification
	 * cookie keyed by suffix, and return the code (for the SMS body). Mirrors
	 * v1.x generateVerificationCode + random_str(5).
	 *
	 * @param string $phone  Normalized phone the cookie is bound to.
	 * @param string $suffix Cookie-name suffix, e.g. '_sub', '_unsub', '_2fa'. May be empty.
	 * @return string The plain 5-char code to send via SMS.
	 */
	public function generate( string $phone, string $suffix = '' ): string {
		$code   = $this->random_str( 5 );
		$hash   = wp_hash( $code . $phone );
		$name   = self::COOKIE_PREFIX . $suffix;
		$expiry = time() + self::TTL_SECONDS;

		setcookie( $name, $hash, $expiry, COOKIEPATH, COOKIE_DOMAIN, is_ssl() );
		return $code;
	}

	/**
	 * Verify the code currently in $_POST['code'] against the cookie at
	 * 'sendsms_subscribe_check' . $suffix. Mirrors v1.x verifyVerificationCode.
	 *
	 * Returns false (and leaves the cookie alone) when the cookie is missing,
	 * the code is absent, or the hash doesn't match. On success, expires the
	 * cookie immediately and returns true.
	 *
	 * The caller MUST have already verified the WordPress nonce before invoking
	 * this method.
	 *
	 * @param string $phone  Normalized phone the code was generated for.
	 * @param string $suffix Cookie-name suffix.
	 * @return bool True when the submitted code is valid, false otherwise.
	 */
	public function verify( string $phone, string $suffix = '' ): bool {
		if ( ! isset( $_POST['code'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by caller
			return false;
		}

		$name = self::COOKIE_PREFIX . $suffix;

		if ( ! isset( $_COOKIE[ $name ] ) ) {
			return false;
		}

		$submitted = preg_replace(
			'/[^A-Za-z0-9\-]/',
			'',
			(string) wp_unslash( $_POST['code'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by caller
		);

		$expected = wp_hash( $submitted . $phone );

		if ( hash_equals( (string) wp_unslash( $_COOKIE[ $name ] ), $expected ) ) {
			setcookie( $name, '', time() - 1, COOKIEPATH, COOKIE_DOMAIN, is_ssl() );
			return true;
		}

		return false;
	}

	/**
	 * Return a $length-character random string drawn from KEYSPACE using wp_rand.
	 * Mirrors v1.x random_str with a hard-coded keyspace.
	 *
	 * @param int $length Number of characters to generate. Must be >= 1.
	 * @return string
	 */
	private function random_str( int $length = 5 ): string {
		$pieces = array();
		$max    = mb_strlen( self::KEYSPACE, '8bit' ) - 1;
		for ( $i = 0; $i < $length; ++$i ) {
			$pieces[] = self::KEYSPACE[ wp_rand( 0, $max ) ];
		}
		return implode( '', $pieces );
	}
}
