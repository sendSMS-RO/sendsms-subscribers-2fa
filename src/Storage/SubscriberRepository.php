<?php
/**
 * Persistence layer for SMS subscriber rows.
 *
 * @package SendSMS\Dashboard\Storage
 */

namespace SendSMS\Dashboard\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rows in the {prefix}sendsms_dashboard_subscribers table.
 *
 * This is the only path through which callers insert, update, or query SMS
 * subscribers. The table schema mirrors the v1.x activator verbatim so that
 * existing installs upgrade transparently when dbDelta runs.
 *
 * Primary key is `phone` (VARCHAR 50) — every public method that accepts a
 * phone number expects it to have already been normalised through
 * {@see \SendSMS\Dashboard\Functions::validate_phone()}.
 */
final class SubscriberRepository {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct queries against the plugin's own custom tables; no transient caching because writes happen in the same request and stale reads are a larger concern than query overhead.

	/**
	 * Returns the fully-qualified table name including the wpdb prefix.
	 *
	 * @return string
	 */
	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sendsms_dashboard_subscribers';
	}

	/**
	 * Returns the CREATE TABLE SQL suitable for dbDelta.
	 *
	 * Schema is preserved verbatim from the v1.x activator so that existing
	 * installs upgrade without data loss. Two separate name columns
	 * (`first_name`, `last_name`) are kept — do NOT collapse them into one.
	 * The `synced` column stores the remote sendsms.ro contact ID as an INT.
	 *
	 * @return string
	 */
	public static function dbdelta_sql(): string {
		global $wpdb;
		$table   = $wpdb->prefix . 'sendsms_dashboard_subscribers';
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE `{$table}` (
			`phone` varchar(50) NOT NULL,
			`first_name` varchar(255) NOT NULL,
			`last_name` varchar(255) NOT NULL,
			`date` datetime NOT NULL,
			`ip_address` varchar(20) DEFAULT NULL,
			`browser` text DEFAULT NULL,
			`synced` int(8) DEFAULT NULL,
			PRIMARY KEY (`phone`)
		) {$charset};";
	}

	/**
	 * Returns the row for the given phone, or null if no row exists.
	 *
	 * @param string $phone Normalised phone number (primary key).
	 * @return array|null Associative array of column => value, or null.
	 */
	public function find( string $phone ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix only.
				"SELECT * FROM {$this->table()} WHERE phone = %s",
				$phone
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Inserts a subscriber row and returns true on success.
	 *
	 * Returns false without inserting when the phone number already exists in
	 * the table, mirroring the guard in v1.x `add_subscriber_db()`.
	 *
	 * Accepted keys in $data:
	 *  - first_name  (string) Default ''.
	 *  - last_name   (string) Default ''.
	 *  - date        (string) MySQL datetime. Default current_time('mysql').
	 *  - ip_address  (string|null) Default null.
	 *  - browser     (string|null) Default null; falls back to HTTP_USER_AGENT when omitted.
	 *
	 * The `synced` column is intentionally left NULL on insert.
	 *
	 * @param string $phone Normalised phone number used as the primary key.
	 * @param array  $data  Optional column overrides (see above).
	 * @return bool True on successful insert, false if the phone already exists.
	 */
	public function insert( string $phone, array $data = array() ): bool {
		global $wpdb;

		if ( null !== $this->find( $phone ) ) {
			return false;
		}

		$browser = isset( $data['browser'] ) ? $data['browser'] : ( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : null );

		$row = array(
			'phone'      => $phone,
			'first_name' => (string) ( $data['first_name'] ?? '' ),
			'last_name'  => (string) ( $data['last_name'] ?? '' ),
			'date'       => (string) ( $data['date'] ?? current_time( 'mysql' ) ),
			'ip_address' => isset( $data['ip_address'] ) ? $data['ip_address'] : null,
			'browser'    => $browser,
			'synced'     => isset( $data['synced'] ) ? (int) $data['synced'] : null,
		);

		$result = $wpdb->insert( $this->table(), $row );
		return false !== $result;
	}

	/**
	 * Updates columns on an existing subscriber row.
	 *
	 * Only the columns listed in the whitelist are written; any other keys in
	 * $data are silently ignored, mirroring v1.x `update_subscriber_db()`.
	 *
	 * Allowed columns: first_name, last_name, date, ip_address, browser, synced.
	 *
	 * @param string $phone Normalised phone number (primary key) to update.
	 * @param array  $data  Associative array of column => value to write.
	 * @return bool True when at least one row was affected, false otherwise.
	 */
	public function update( string $phone, array $data ): bool {
		global $wpdb;

		$allowed = array( 'first_name', 'last_name', 'date', 'ip_address', 'browser', 'synced' );
		$payload = array_intersect_key( $data, array_flip( $allowed ) );

		if ( empty( $payload ) ) {
			return false;
		}

		$result = $wpdb->update(
			$this->table(),
			$payload,
			array( 'phone' => $phone )
		);

		// `update()` returns 0 when row exists but data is unchanged. Treat that as success.
		return false !== $result;
	}

	/**
	 * Deletes the row identified by the given phone number.
	 *
	 * Mirrors v1.x `remove_subscriber_db()`. The IP-address side-effect present
	 * in v1.x is handled at the call-site (AJAX handler), not here.
	 *
	 * @param string $phone Normalised phone number (primary key) to remove.
	 * @return bool True when a row was deleted, false otherwise.
	 */
	public function delete( string $phone ): bool {
		global $wpdb;
		$result = $wpdb->delete(
			$this->table(),
			array( 'phone' => $phone ),
			array( '%s' )
		);
		return false !== $result && $result > 0;
	}

	/**
	 * Sets the `synced` column to the remote sendsms.ro contact ID.
	 *
	 * Mirrors v1.x `update_subscriber_sync_db()`. Pass 0 to clear the link.
	 *
	 * @param string $phone     Normalised phone number (primary key).
	 * @param int    $remote_id Remote contact ID from the sendsms.ro API.
	 * @return bool True when the row was updated, false otherwise.
	 */
	public function mark_synced( string $phone, int $remote_id ): bool {
		global $wpdb;
		$result = $wpdb->update(
			$this->table(),
			array( 'synced' => $remote_id ),
			array( 'phone' => $phone )
		);
		return false !== $result && $result > 0;
	}

	/**
	 * Returns a page of subscriber rows.
	 *
	 * Accepted keys in $args:
	 *  - per_page (int)    Rows per page. Pass -1 to return all rows. Default 20.
	 *  - page     (int)    1-based page number. Default 1.
	 *  - orderby  (string) Column to sort by. Allowed: phone, first_name, last_name, date, synced. Default date.
	 *  - order    (string) ASC or DESC. Default DESC.
	 *
	 * @param array $args Query arguments (see above).
	 * @return array List of rows as associative arrays.
	 */
	public function paginate( array $args ): array {
		global $wpdb;

		$allowed_orderby = array( 'phone', 'first_name', 'last_name', 'date', 'synced' );
		$orderby         = in_array( $args['orderby'] ?? 'date', $allowed_orderby, true ) ? ( $args['orderby'] ?? 'date' ) : 'date';
		$order           = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';
		$per_page        = (int) ( $args['per_page'] ?? 20 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and $orderby/$order are sanitised above.
		$base_sql = "SELECT * FROM {$this->table()} ORDER BY {$orderby} {$order}";

		if ( -1 === $per_page ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input in this branch.
			return (array) $wpdb->get_results( $base_sql, ARRAY_A );
		}

		$per_page = max( 1, $per_page );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $base_sql is safe (table from $wpdb->prefix; orderby/order sanitised above).
		$paged_sql = $wpdb->prepare( "{$base_sql} LIMIT %d OFFSET %d", $per_page, $offset );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built with wpdb->prepare() on the line above.
		return (array) $wpdb->get_results( $paged_sql, ARRAY_A );
	}

	/**
	 * Returns the total subscriber count.
	 *
	 * The $args parameter is accepted for interface consistency but is currently
	 * unused — v1.x does not support server-side search on the subscribers table.
	 *
	 * @param array $args Reserved for future use.
	 * @return int Total number of subscriber rows.
	 */
	public function count( array $args = array() ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- reserved for future search support; v1.x does not filter on this table.
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix only.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" );
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
}
