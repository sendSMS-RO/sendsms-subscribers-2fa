<?php
/**
 * Client IP address resolution utility.
 *
 * Provides a single static entry point for resolving the real client IP from
 * server variables. Mirrors v1.x {@see SendSMSFunctions::get_ip_address()}
 * exactly: HTTP_X_REAL_IP is preferred, then the first token of
 * HTTP_X_FORWARDED_FOR (validated via rest_is_ip_address), then REMOTE_ADDR.
 *
 * @package SendSMS\Dashboard\Support
 */

namespace SendSMS\Dashboard\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the client's IP address from available server variables.
 *
 * This class has no dependencies and is safe to call from any context where
 * WordPress functions (sanitize_text_field, wp_unslash, rest_is_ip_address)
 * are available. The resolution order matches v1.x verbatim so behaviour is
 * identical to existing installations on upgrade.
 */
final class Ip {

	/**
	 * Resolve the client IP. Mirrors v1.x get_ip_address: HTTP_X_REAL_IP wins,
	 * then the first usable HTTP_X_FORWARDED_FOR entry, then REMOTE_ADDR.
	 * Returns an empty string when nothing usable is available.
	 *
	 * For the X_FORWARDED_FOR case, rest_is_ip_address is used to validate the
	 * first comma-delimited token; it silently returns false for invalid values
	 * (matching v1.x behaviour), so the cast to string converts false to ''.
	 *
	 * @return string Client IP address, or empty string if none could be resolved.
	 */
	public static function current(): string {
		if ( isset( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
		}
		if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$first = trim( current( preg_split( '/,/', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ) );
			return (string) rest_is_ip_address( $first );
		}
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return '';
	}
}
