<?php
/**
 * Plugin settings store backed by a single WP option.
 *
 * @package SendSMS\Dashboard\Storage
 */

namespace SendSMS\Dashboard\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the serialised plugin settings option.
 *
 * All plugin code that needs a setting MUST go through this class so that
 * partial saves (`update_partial`) merge instead of overwriting, avoiding
 * the cross-tab wipe bug present in v1.x.
 */
final class Settings {

	/**
	 * WP option name that holds all plugin settings.
	 */
	private const OPTION = 'sendsms_dashboard_plugin_settings';

	/**
	 * In-process cache of the full settings array.
	 *
	 * @var array<string,mixed>|null
	 */
	private $cache = null;

	/**
	 * Returns the full settings array, loading it from the DB if needed.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$value       = get_option( self::OPTION, array() );
			$this->cache = is_array( $value ) ? $value : array();
		}
		return $this->cache;
	}

	/**
	 * Returns a single setting value, or `$default` when the key is absent.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Value returned when the key is not set.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Returns a single setting cast to a safe string, or `$default` on failure.
	 *
	 * @param string $key     Setting key.
	 * @param string $default Fallback when the stored value is not scalar.
	 * @return string
	 */
	public function get_esc( string $key, string $default = '' ): string {
		$value = $this->get( $key, $default );
		return is_scalar( $value ) ? (string) $value : $default;
	}

	/**
	 * Merges `$patch` into the existing settings and persists the result.
	 *
	 * Only the keys present in `$patch` are updated; all other keys keep their
	 * current values. This prevents one admin tab from wiping settings saved
	 * by another tab.
	 *
	 * @param array<string,mixed> $patch Key/value pairs to update.
	 * @return bool True when the option was updated, false otherwise.
	 */
	public function update_partial( array $patch ): bool {
		$existing = $this->all();
		// Top-level replace (NOT recursive) so empty sub-arrays in $patch clear the prior value.
		// Example: unchecking every box in 2fa_roles must produce an empty array, not preserve the prior set.
		$merged      = array_replace( $existing, $patch );
		$this->cache = $merged;
		return update_option( self::OPTION, $merged, false );
	}

	/**
	 * Returns the list of user meta keys the plugin checks for a phone number.
	 *
	 * Reads the `phone_meta` setting (one meta key per line) — the same key
	 * v1.x uses — so existing installations carry their configuration over
	 * transparently. 'sendsms_phone_number' is always appended as the final
	 * fallback, matching v1.x behaviour where get_user_phone falls back to that
	 * meta key unconditionally.
	 *
	 * @return string[]
	 */
	public function user_phone_meta_keys(): array {
		$raw = (string) $this->get( 'phone_meta', '' );
		$raw = trim( $raw );

		$keys = array();
		if ( '' !== $raw ) {
			$lines = preg_split( '/\R+/', $raw );
			foreach ( (array) $lines as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$keys[] = sanitize_key( $line );
				}
			}
		}

		// Always include the v1.x default fallback at the end.
		if ( ! in_array( 'sendsms_phone_number', $keys, true ) ) {
			$keys[] = 'sendsms_phone_number';
		}

		return $keys;
	}
}
