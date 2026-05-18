<?php
/**
 * Persistence layer for SMS send history rows.
 *
 * @package SendSMS\Dashboard\Storage
 */

namespace SendSMS\Dashboard\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rows in the {prefix}sendsms_dashboard_history table.
 *
 * This is the only path through which callers insert or query SMS history.
 * Every API send writes a row via {@see HistoryRepository::insert()}; every
 * History admin page query reads through {@see HistoryRepository::paginate()}
 * and {@see HistoryRepository::count()}.
 *
 * The table schema mirrors the v1.x activator verbatim so that existing installs
 * upgrade transparently when dbDelta runs.
 */
final class HistoryRepository {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct queries against the plugin's own custom tables; no transient caching because writes happen in the same request and stale reads are a larger concern than query overhead.

	/**
	 * Returns the fully-qualified table name including the wpdb prefix.
	 *
	 * @return string
	 */
	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sendsms_dashboard_history';
	}

	/**
	 * Returns the CREATE TABLE SQL suitable for dbDelta.
	 *
	 * Schema is preserved verbatim from the v1.x activator so that existing
	 * installs upgrade without data loss.
	 *
	 * @return string
	 */
	public static function dbdelta_sql(): string {
		global $wpdb;
		$table   = $wpdb->prefix . 'sendsms_dashboard_history';
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE `{$table}` (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`phone` varchar(255) DEFAULT NULL,
			`status` varchar(255) DEFAULT NULL,
			`message` varchar(255) DEFAULT NULL,
			`details` longtext,
			`content` longtext,
			`type` varchar(255) DEFAULT NULL,
			`sent_on` datetime DEFAULT NULL,
			PRIMARY KEY (`id`)
		) {$charset};";
	}

	/**
	 * Inserts a single history row and returns the new row ID.
	 *
	 * Callers only need to supply the columns that differ from defaults.
	 *
	 * @param array $row Associative array of column => value pairs.
	 * @return int Inserted row ID, or 0 on failure.
	 */
	public function insert( array $row ): int {
		global $wpdb;
		$defaults = array(
			'sent_on' => current_time( 'mysql' ),
			'phone'   => '',
			'status'  => '',
			'message' => '',
			'details' => '',
			'content' => '',
			'type'    => '',
		);
		$wpdb->insert( $this->table(), array_merge( $defaults, $row ) );
		return (int) $wpdb->insert_id;
	}


	/**
	 * Returns a page of history rows matching the given arguments.
	 *
	 * Accepted keys in $args:
	 *  - per_page (int)    Rows per page. Default 20.
	 *  - page     (int)    1-based page number. Default 1.
	 *  - orderby  (string) Column to sort by. Allowed: id, sent_on, phone, status. Default sent_on.
	 *  - order    (string) ASC or DESC. Default DESC.
	 *  - search   (string) Substring match against phone or content. Default ''.
	 *
	 * @param array $args Query arguments.
	 * @return array List of rows as associative arrays.
	 */
	public function paginate( array $args ): array {
		global $wpdb;
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;
		$orderby  = in_array( $args['orderby'] ?? 'sent_on', array( 'id', 'sent_on', 'phone', 'status' ), true ) ? $args['orderby'] : 'sent_on';
		$order    = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';
		$search   = trim( (string) ( $args['search'] ?? '' ) );

		$where = 'WHERE 1=1';
		$prep  = array();
		if ( '' !== $search ) {
			$where .= ' AND (phone LIKE %s OR content LIKE %s)';
			$prep[] = '%' . $wpdb->esc_like( $search ) . '%';
			$prep[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		// Table name comes from $wpdb->prefix — safe to interpolate.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built with wpdb->prepare() below.
		$sql  = "SELECT * FROM {$this->table()} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$prep = array_merge( $prep, array( $per_page, $offset ) );
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $prep ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Returns the total number of history rows, optionally filtered by a search term.
	 *
	 * @param array $args Accepts the same 'search' key as {@see paginate()}.
	 * @return int Total matching row count.
	 */
	public function count( array $args = array() ): int {
		global $wpdb;
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' === $search ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix only.
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" );
		}
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix only.
				"SELECT COUNT(*) FROM {$this->table()} WHERE phone LIKE %s OR content LIKE %s",
				$like,
				$like
			)
		);
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
}
