<?php
/**
 * Persistence layer for IP address rate-limit rows.
 *
 * @package SendSMS\Dashboard\Storage
 */

namespace SendSMS\Dashboard\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rows in the {prefix}sendsms_dashboard_ip_address table.
 *
 * This is the only path through which callers insert or update the single-row-
 * per-IP cycle counter used for request rate limiting. The rate-limit algorithm
 * itself lives in {@see \SendSMS\Dashboard\Support\IpRateLimit} — this class is
 * pure CRUD.
 *
 * Primary key is `ip_address` (VARCHAR 20). Each row tracks the start of the
 * current counting window (`date_cycle_start`) and the number of requests made
 * within it (`request_no`). The schema mirrors the v1.x activator verbatim so
 * existing installs upgrade transparently when dbDelta runs.
 */
final class IpRepository {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct queries against the plugin's own custom tables; no transient caching because writes happen in the same request and stale reads are a larger concern than query overhead.

	/**
	 * Returns the fully-qualified table name including the wpdb prefix.
	 *
	 * @return string
	 */
	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sendsms_dashboard_ip_address';
	}

	/**
	 * Returns the CREATE TABLE SQL suitable for dbDelta.
	 *
	 * Schema is preserved verbatim from the v1.x activator. The primary key is
	 * `ip_address` (VARCHAR 20) — there is no auto-increment id, no blocked
	 * flag, and no secondary index.
	 *
	 * @return string
	 */
	public static function dbdelta_sql(): string {
		global $wpdb;
		$table   = $wpdb->prefix . 'sendsms_dashboard_ip_address';
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE `{$table}` (
			`ip_address` varchar(20) NOT NULL,
			`date_cycle_start` datetime DEFAULT NULL,
			`request_no` int DEFAULT NULL,
			PRIMARY KEY (`ip_address`)
		) {$charset};";
	}

	/**
	 * Returns true when a row already exists for this IP address.
	 *
	 * @param string $ip IPv4 or IPv6 address (max 20 chars).
	 * @return bool
	 */
	public function is_registered( string $ip ): bool {
		global $wpdb;
		$result = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix only.
				"SELECT 1 FROM {$this->table()} WHERE ip_address = %s LIMIT 1",
				$ip
			)
		);
		return null !== $result;
	}

	/**
	 * Inserts a fresh row for this IP address with request_no = 1 and
	 * date_cycle_start set to the current MySQL time.
	 *
	 * Mirrors v1.x `add_ip_address_db()`. Returns true on successful insert.
	 *
	 * @param string $ip IPv4 or IPv6 address (max 20 chars).
	 * @return bool True on success, false on failure.
	 */
	public function register( string $ip ): bool {
		global $wpdb;
		$result = $wpdb->insert(
			$this->table(),
			array(
				'ip_address'       => $ip,
				'date_cycle_start' => current_time( 'mysql' ),
				'request_no'       => 1,
			),
			array( '%s', '%s', '%d' )
		);
		return false !== $result;
	}

	/**
	 * Returns the row for the given IP address, or null if no row exists.
	 *
	 * Mirrors v1.x `get_ip_address_db()`.
	 *
	 * @param string $ip IPv4 or IPv6 address (max 20 chars).
	 * @return array|null Associative array of column => value, or null.
	 */
	public function find( string $ip ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix only.
				"SELECT * FROM {$this->table()} WHERE ip_address = %s",
				$ip
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Increments request_no by 1 for the given IP address.
	 *
	 * Mirrors the increment branch of v1.x `too_many_requests()` (still within
	 * the current time window, below the configured request cap).
	 *
	 * @param string $ip IPv4 or IPv6 address (max 20 chars).
	 * @return bool True when the row was updated, false otherwise.
	 */
	public function increment( string $ip ): bool {
		global $wpdb;
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix only.
				"UPDATE {$this->table()} SET request_no = request_no + 1 WHERE ip_address = %s",
				$ip
			)
		);
		return false !== $result;
	}

	/**
	 * Resets the rate-limit cycle for the given IP address.
	 *
	 * Sets date_cycle_start to the current time and request_no to 1, opening a
	 * new counting window. Mirrors the reset branch of v1.x `too_many_requests()`
	 * (the previous window has expired).
	 *
	 * @param string $ip IPv4 or IPv6 address (max 20 chars).
	 * @return bool True when the row was updated, false otherwise.
	 */
	public function reset( string $ip ): bool {
		global $wpdb;
		$result = $wpdb->update(
			$this->table(),
			array(
				'date_cycle_start' => current_time( 'mysql' ),
				'request_no'       => 1,
			),
			array( 'ip_address' => $ip ),
			array( '%s', '%d' ),
			array( '%s' )
		);
		return false !== $result;
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}
