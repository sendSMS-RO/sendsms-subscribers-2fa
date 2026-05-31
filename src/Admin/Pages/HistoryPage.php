<?php
/**
 * SMS History admin page backed by a WP_List_Table.
 *
 * Contains two classes that are tightly coupled and always loaded together:
 *
 * - {@see HistoryPage}      — capability-checked page renderer that
 *                             instantiates the table and wraps it in the
 *                             standard WP admin `.wrap` chrome.
 * - {@see HistoryListTable} — WP_List_Table subclass that delegates data
 *                             fetching entirely to {@see HistoryRepository}
 *                             so no raw SQL lives in the presentation layer.
 *
 * Columns displayed: sent_on, phone, content, status, message, details, type.
 * Sortable columns: sent_on (default DESC), phone, status.
 * Search matches against `phone` and `content` via HistoryRepository::paginate().
 *
 * @package Rosendsms\Dashboard\Admin\Pages
 */

namespace Rosendsms\Dashboard\Admin\Pages;

use Rosendsms\Dashboard\Plugin;
use Rosendsms\Dashboard\Storage\HistoryRepository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * History page renderer.
 *
 * Performs a capability check then renders the full WP admin page with the
 * {@see HistoryListTable}, including the search box and pagination controls
 * produced by WP_List_Table.
 *
 * Usage — call from a menu callback after registering via Admin\Menu:
 *
 *   ( new HistoryPage() )->render();
 */
final class HistoryPage {

	/**
	 * Render the SMS History admin page.
	 *
	 * Bails silently when the current user cannot manage options, so direct URL
	 * access by unprivileged users is harmless.  The table form uses GET so that
	 * search and pagination parameters appear in the URL and the browser back
	 * button works as expected.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$table = new HistoryListTable( Plugin::instance()->history() );
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'SMS History', 'sendsms-subscribers-2fa' ) . '</h1>';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="sendsms-dashboard-history" />';
		$table->search_box( esc_html__( 'Search', 'sendsms-subscribers-2fa' ), 'sendsms-history-search' );
		$table->display();
		echo '</form>';
		echo '</div>';
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- HistoryListTable is an inner helper for HistoryPage; intentionally co-located.
/**
 * WP_List_Table subclass for the SMS History page.
 *
 * Delegates all data fetching to {@see HistoryRepository::paginate()} and
 * {@see HistoryRepository::count()} so this class contains zero SQL.
 *
 * Columns mirror the v1.x `rosendsms_dash_history` table schema:
 *   sent_on, phone, content, status, message, details, type.
 *
 * The `id` primary-key column is intentionally omitted from the display
 * because it carries no meaning for administrators reviewing send history.
 * Long `content` and `details` values are trimmed to 25 words to keep the
 * table readable.
 */
final class HistoryListTable extends \WP_List_Table {
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound

	/**
	 * History data source.
	 *
	 * @var HistoryRepository
	 */
	private $repo;

	/**
	 * Number of rows to display per page.
	 *
	 * Kept as a named constant so prepare_items() and set_pagination_args()
	 * stay in sync without a magic number.
	 *
	 * @var int
	 */
	private const PER_PAGE = 20;

	/**
	 * Constructor.
	 *
	 * Passes standard singular/plural labels to the WP_List_Table parent and
	 * stores the repository that will supply rows.
	 *
	 * @param HistoryRepository $repo History data source.
	 */
	public function __construct( HistoryRepository $repo ) {
		parent::__construct(
			array(
				'singular' => 'sms',
				'plural'   => 'sms-history',
				'ajax'     => false,
			)
		);
		$this->repo = $repo;
	}

	/**
	 * Return the array of column slugs and human-readable labels.
	 *
	 * Column order matches the v1.x display, with `sent_on` promoted to the
	 * first position to surface the most immediately useful field.
	 *
	 * @return array<string, string> Associative map of column_slug => label.
	 */
	public function get_columns(): array {
		return array(
			'sent_on' => __( 'Date', 'sendsms-subscribers-2fa' ),
			'phone'   => __( 'Recipient', 'sendsms-subscribers-2fa' ),
			'content' => __( 'Content', 'sendsms-subscribers-2fa' ),
			'status'  => __( 'Status', 'sendsms-subscribers-2fa' ),
			'message' => __( 'Message', 'sendsms-subscribers-2fa' ),
			'details' => __( 'Details', 'sendsms-subscribers-2fa' ),
			'type'    => __( 'Type', 'sendsms-subscribers-2fa' ),
		);
	}

	/**
	 * Return the map of sortable column slugs to their sort parameters.
	 *
	 * Each entry is array( orderby_value, is_already_sorted_by_default ).
	 * Only the three columns that have meaningful sort semantics are included;
	 * the rest (`content`, `message`, `details`, `type`) are left unsortable
	 * because alphabetical text sorting provides little administrative value.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function get_sortable_columns(): array {
		return array(
			'sent_on' => array( 'sent_on', true ),
			'phone'   => array( 'phone', false ),
			'status'  => array( 'status', false ),
		);
	}

	/**
	 * Fetch rows from the repository and configure WP_List_Table pagination.
	 *
	 * Reads `$_GET['paged']`, `$_GET['orderby']`, `$_GET['order']`, and
	 * `$_REQUEST['s']` to build the query args forwarded to
	 * {@see HistoryRepository::paginate()}.  All values are sanitized before
	 * use.  The nonce-verification suppression comment is intentional — this
	 * form uses GET-only navigation parameters; no state-changing action is
	 * performed.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only query-string navigation.
		$paged   = absint( wp_unslash( $_GET['paged'] ?? '0' ) );
		$page    = max( 1, $paged > 0 ? $paged : 1 );
		$orderby = sanitize_key( wp_unslash( (string) ( $_GET['orderby'] ?? 'sent_on' ) ) );
		$order   = ( isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ) ? 'ASC' : 'DESC';
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$args = array(
			'per_page' => self::PER_PAGE,
			'page'     => $page,
			'orderby'  => $orderby,
			'order'    => $order,
			'search'   => $search,
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->items           = $this->repo->paginate( $args );

		$this->set_pagination_args(
			array(
				'total_items' => $this->repo->count( $args ),
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Render any column that does not have a dedicated column_* method.
	 *
	 * All values pass through {@see esc_html()} before output.  Unknown column
	 * slugs fall back to an empty string rather than throwing.
	 *
	 * @param array<string, mixed> $item        The current row as an associative array.
	 * @param string               $column_name The slug of the column being rendered.
	 * @return string Escaped HTML to display in the cell.
	 */
	public function column_default( $item, $column_name ) {
		$value = isset( $item[ $column_name ] ) ? (string) $item[ $column_name ] : '';
		return esc_html( $value );
	}

	/**
	 * Render the `content` column, trimmed to 25 words.
	 *
	 * SMS bodies can be long; truncating keeps the table legible while the
	 * full text remains available in the raw export.
	 *
	 * @param array<string, mixed> $item The current row as an associative array.
	 * @return string Escaped, word-trimmed HTML string.
	 */
	public function column_content( $item ) {
		return esc_html( wp_trim_words( (string) ( $item['content'] ?? '' ), 25 ) );
	}

	/**
	 * Render the `details` column, trimmed to 25 words.
	 *
	 * API detail strings from sendsms.ro can be verbose; truncating here
	 * mirrors the treatment applied to `content`.
	 *
	 * @param array<string, mixed> $item The current row as an associative array.
	 * @return string Escaped, word-trimmed HTML string.
	 */
	public function column_details( $item ) {
		return esc_html( wp_trim_words( (string) ( $item['details'] ?? '' ), 25 ) );
	}
}
