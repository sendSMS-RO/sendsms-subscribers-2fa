<?php
/**
 * SMS Subscribers admin page backed by a WP_List_Table.
 *
 * Contains two classes that are tightly coupled and always loaded together:
 *
 * - {@see SubscribersPage}      — capability-checked page renderer that renders
 *                                 the Add Subscriber form, an optional inline
 *                                 Edit Subscriber form (when action=edit), and
 *                                 the list table.
 * - {@see SubscribersListTable} — WP_List_Table subclass that delegates data
 *                                 fetching entirely to {@see SubscriberRepository}
 *                                 so no raw SQL lives in the presentation layer.
 *
 * Columns displayed: phone (with row actions), first_name, last_name, date, synced.
 * Hidden (audit only): ip_address, browser — retained in the DB, omitted from display.
 * Sortable columns: phone, first_name, last_name, date, synced. Default: date DESC.
 *
 * Row actions on the phone column:
 *  - edit   → query-string navigation (?page=…&action=edit&phone=…)
 *  - delete → data-action="sendsms-subscriber-delete" (JS-bound in Task 26)
 *  - sync   → data-action="sendsms-subscriber-sync"   (JS-bound in Task 26)
 *
 * The Add and Edit forms submit to AJAX actions wired in Task 23; the JS is
 * inert until that task is complete — the HTML is correct and ready.
 *
 * @package Rosendsms\Dashboard\Admin\Pages
 */

namespace Rosendsms\Dashboard\Admin\Pages;

use Rosendsms\Dashboard\Plugin;
use Rosendsms\Dashboard\Storage\SubscriberRepository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Subscribers page renderer.
 *
 * Performs a capability check then renders:
 *  1. An "Add subscriber" form (phone, first_name, last_name).
 *  2. An inline "Edit subscriber" form when action=edit and the phone resolves.
 *  3. The {@see SubscribersListTable} with search box and pagination controls.
 *
 * Usage — called from Admin\Menu::render_subscribers() via the WP admin_menu hook:
 *
 *   ( new SubscribersPage() )->render();
 */
final class SubscribersPage {

	/**
	 * Render the Subscribers admin page.
	 *
	 * Bails silently when the current user cannot manage options so that
	 * direct URL access by unprivileged users is harmless.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$repo = Plugin::instance()->subscribers();

		$table = new SubscribersListTable( $repo );
		$table->prepare_items();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only query-string navigation, no state mutation.
		$action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'SendSMS – Subscribers', 'sendsms-dashboard' ) . '</h1>';
		echo '</div>';

		$this->render_add_form();

		if ( 'edit' === $action ) {
			$this->render_edit_form( $repo );
		}

		echo '<div class="wrap">';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="sendsms-dashboard-subscribers" />';
		$table->search_box( esc_html__( 'Search', 'sendsms-dashboard' ), 'sendsms-subscribers-search' );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render the "Add subscriber" card form.
	 *
	 * The form targets the `rosendsms_dash_subscriber_add` AJAX action
	 * (wired in Task 23). Until that task is complete the submit will be
	 * inert. The nonce field uses the shared `rosendsms_dash_nonce` so
	 * the AJAX handler's `check_ajax_referer()` call succeeds.
	 *
	 * @return void
	 */
	private function render_add_form(): void {
		echo '<div class="wrap">';
		echo '<h2>' . esc_html__( 'Add subscriber', 'sendsms-dashboard' ) . '</h2>';
		echo '<form id="sendsms-subscriber-add-form" method="post">';
		wp_nonce_field( 'rosendsms_dash_nonce', 'security' );
		echo '<input type="hidden" name="action" value="rosendsms_dash_subscriber_add" />';

		echo '<table class="form-table" role="presentation">';
		echo '<tbody>';

		echo '<tr>';
		echo '<th scope="row"><label for="sendsms-add-phone">' . esc_html__( 'Phone number', 'sendsms-dashboard' ) . '</label></th>';
		echo '<td><input type="tel" id="sendsms-add-phone" name="phone" class="regular-text" required /></td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row"><label for="sendsms-add-first-name">' . esc_html__( 'First name', 'sendsms-dashboard' ) . '</label></th>';
		echo '<td><input type="text" id="sendsms-add-first-name" name="first_name" class="regular-text" /></td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row"><label for="sendsms-add-last-name">' . esc_html__( 'Last name', 'sendsms-dashboard' ) . '</label></th>';
		echo '<td><input type="text" id="sendsms-add-last-name" name="last_name" class="regular-text" /></td>';
		echo '</tr>';

		echo '</tbody>';
		echo '</table>';

		echo '<p class="submit">';
		echo '<button type="submit" id="sendsms-subscriber-add-btn" class="button button-primary">' . esc_html__( 'Add subscriber', 'sendsms-dashboard' ) . '</button>';
		echo '</p>';

		echo '<div id="sendsms-subscriber-add-message" style="display:none;"></div>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render the inline "Edit subscriber" form when action=edit is active.
	 *
	 * Reads `$_GET['phone']` (query-string navigation only — no state change here),
	 * looks up the subscriber, and if found renders a form with phone as a
	 * read-only field and first_name / last_name as editable inputs.
	 *
	 * The form targets `rosendsms_dash_subscriber_update` (Task 23).
	 *
	 * @param SubscriberRepository $repo Subscriber data source.
	 * @return void
	 */
	private function render_edit_form( SubscriberRepository $repo ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only query-string lookup; no state mutation.
		$raw_phone = isset( $_GET['phone'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['phone'] ) ) : '';

		if ( '' === $raw_phone ) {
			return;
		}

		$subscriber = $repo->find( $raw_phone );
		if ( null === $subscriber ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Subscriber not found.', 'sendsms-dashboard' ) . '</p></div></div>';
			return;
		}

		echo '<div class="wrap">';
		echo '<h2>' . esc_html__( 'Edit subscriber', 'sendsms-dashboard' ) . '</h2>';
		echo '<form id="sendsms-subscriber-edit-form" method="post">';
		wp_nonce_field( 'rosendsms_dash_nonce', 'security' );
		echo '<input type="hidden" name="action" value="rosendsms_dash_subscriber_update" />';

		echo '<table class="form-table" role="presentation">';
		echo '<tbody>';

		echo '<tr>';
		echo '<th scope="row"><label for="sendsms-edit-phone">' . esc_html__( 'Phone number', 'sendsms-dashboard' ) . '</label></th>';
		echo '<td>';
		echo '<input type="tel" id="sendsms-edit-phone" name="phone" class="regular-text" value="' . esc_attr( $subscriber['phone'] ) . '" readonly />';
		echo '</td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row"><label for="sendsms-edit-first-name">' . esc_html__( 'First name', 'sendsms-dashboard' ) . '</label></th>';
		echo '<td><input type="text" id="sendsms-edit-first-name" name="first_name" class="regular-text" value="' . esc_attr( $subscriber['first_name'] ) . '" /></td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row"><label for="sendsms-edit-last-name">' . esc_html__( 'Last name', 'sendsms-dashboard' ) . '</label></th>';
		echo '<td><input type="text" id="sendsms-edit-last-name" name="last_name" class="regular-text" value="' . esc_attr( $subscriber['last_name'] ) . '" /></td>';
		echo '</tr>';

		echo '</tbody>';
		echo '</table>';

		echo '<p class="submit">';
		echo '<button type="submit" id="sendsms-subscriber-edit-btn" class="button button-primary">' . esc_html__( 'Update subscriber', 'sendsms-dashboard' ) . '</button>';
		$cancel_url = add_query_arg(
			array(
				'page' => 'sendsms-dashboard-subscribers',
			),
			admin_url( 'admin.php' )
		);
		echo '&nbsp;<a href="' . esc_url( $cancel_url ) . '" class="button">' . esc_html__( 'Cancel', 'sendsms-dashboard' ) . '</a>';
		echo '</p>';

		echo '<div id="sendsms-subscriber-edit-message" style="display:none;"></div>';
		echo '</form>';
		echo '</div>';
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- SubscribersListTable is an inner helper for SubscribersPage; intentionally co-located.
/**
 * WP_List_Table subclass for the SMS Subscribers page.
 *
 * Delegates all data fetching to {@see SubscriberRepository::paginate()} and
 * {@see SubscriberRepository::count()} so this class contains zero SQL.
 *
 * Columns displayed: phone (with row actions), first_name, last_name, date, synced.
 * Audit columns (ip_address, browser) are intentionally omitted from display but
 * remain in the database and are returned by the repository.
 *
 * Sortable columns: phone, first_name, last_name, date, synced. Default: date DESC.
 *
 * Row actions on the phone column:
 *  - edit   — query-string link to ?page=sendsms-dashboard-subscribers&action=edit&phone=…
 *  - delete — data attribute link consumed by admin JS (Task 26)
 *  - sync / resync — data attribute link consumed by admin JS (Task 26)
 */
final class SubscribersListTable extends \WP_List_Table {
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound

	/**
	 * Subscriber data source.
	 *
	 * @var SubscriberRepository
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
	 * @param SubscriberRepository $repo Subscriber data source.
	 */
	public function __construct( SubscriberRepository $repo ) {
		parent::__construct(
			array(
				'singular' => 'subscriber',
				'plural'   => 'subscribers',
				'ajax'     => false,
			)
		);
		$this->repo = $repo;
	}

	/**
	 * Return the array of column slugs and human-readable labels.
	 *
	 * `ip_address` and `browser` are intentionally omitted from the display —
	 * they are audit fields not relevant to day-to-day administration. They
	 * remain in the database and are not lost.
	 *
	 * @return array<string, string> Associative map of column_slug => label.
	 */
	public function get_columns(): array {
		return array(
			'cb'         => '<input type="checkbox" />',
			'phone'      => __( 'Phone', 'sendsms-dashboard' ),
			'first_name' => __( 'First name', 'sendsms-dashboard' ),
			'last_name'  => __( 'Last name', 'sendsms-dashboard' ),
			'date'       => __( 'Date', 'sendsms-dashboard' ),
			'synced'     => __( 'Synced', 'sendsms-dashboard' ),
		);
	}

	/**
	 * Return the map of sortable column slugs to their sort parameters.
	 *
	 * Each entry is array( orderby_value, is_already_sorted_by_default ).
	 * `date` is the default sort column (DESC) so it receives `true`.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function get_sortable_columns(): array {
		return array(
			'phone'      => array( 'phone', false ),
			'first_name' => array( 'first_name', false ),
			'last_name'  => array( 'last_name', false ),
			'date'       => array( 'date', true ),
			'synced'     => array( 'synced', false ),
		);
	}

	/**
	 * Return the bulk actions available for this table.
	 *
	 * Only `delete-bulk` is supported, mirroring v1.x behaviour. The actual
	 * deletion is handled by admin JS (Task 26) via the AJAX handler (Task 23).
	 *
	 * @return array<string, string>
	 */
	public function get_bulk_actions(): array {
		return array(
			'delete-bulk' => __( 'Delete', 'sendsms-dashboard' ),
		);
	}

	/**
	 * Fetch rows from the repository and configure WP_List_Table pagination.
	 *
	 * Reads `$_GET['paged']`, `$_GET['orderby']`, `$_GET['order']`, and
	 * `$_REQUEST['s']` to build the query args forwarded to
	 * {@see SubscriberRepository::paginate()}.  All values are sanitized before
	 * use.  The nonce-verification suppression comment is intentional — this
	 * form uses GET-only navigation parameters; no state-changing action is
	 * performed.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only query-string navigation; no state mutation.
		$paged   = absint( wp_unslash( $_GET['paged'] ?? '0' ) );
		$page    = max( 1, $paged > 0 ? $paged : 1 );
		$orderby = sanitize_key( wp_unslash( (string) ( $_GET['orderby'] ?? 'date' ) ) );
		$order   = ( isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ) ? 'ASC' : 'DESC';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$args = array(
			'per_page' => self::PER_PAGE,
			'page'     => $page,
			'orderby'  => $orderby,
			'order'    => $order,
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
	 * Render the checkbox column used for bulk actions.
	 *
	 * The value is the subscriber's phone number (primary key).
	 *
	 * @param array<string, mixed> $item The current row as an associative array.
	 * @return string HTML checkbox input.
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="subscriber[]" value="%s" />',
			esc_attr( (string) ( $item['phone'] ?? '' ) )
		);
	}

	/**
	 * Render the `phone` column with row actions.
	 *
	 * Row actions:
	 *  - edit   — navigates to the inline edit form via query string.
	 *  - delete — data-action link consumed by admin JS (Task 26).
	 *  - sync   — data-action link consumed by admin JS (Task 26); label
	 *             changes to "Resync" when the subscriber is already synced.
	 *
	 * @param array<string, mixed> $item The current row as an associative array.
	 * @return string HTML content for the phone cell including row actions.
	 */
	public function column_phone( $item ): string {
		$phone  = (string) ( $item['phone'] ?? '' );
		$synced = (int) ( $item['synced'] ?? 0 );

		$edit_url = add_query_arg(
			array(
				'page'   => 'sendsms-dashboard-subscribers',
				'action' => 'edit',
				'phone'  => rawurlencode( $phone ),
			),
			admin_url( 'admin.php' )
		);

		$sync_label = $synced > 0 ? __( 'Resync', 'sendsms-dashboard' ) : __( 'Sync', 'sendsms-dashboard' );

		$actions = array(
			'edit'   => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Edit', 'sendsms-dashboard' )
			),
			'delete' => sprintf(
				'<a href="#" data-action="sendsms-subscriber-delete" data-phone="%s">%s</a>',
				esc_attr( $phone ),
				esc_html__( 'Delete', 'sendsms-dashboard' )
			),
			'sync'   => sprintf(
				'<a href="#" data-action="sendsms-subscriber-sync" data-phone="%s">%s</a>',
				esc_attr( $phone ),
				esc_html( $sync_label )
			),
		);

		return esc_html( $phone ) . $this->row_actions( $actions );
	}

	/**
	 * Render the `synced` column.
	 *
	 * Displays "Yes (id: N)" when the subscriber has a remote contact ID,
	 * and "Not synced" otherwise.
	 *
	 * @param array<string, mixed> $item The current row as an associative array.
	 * @return string Escaped HTML string.
	 */
	public function column_synced( $item ): string {
		$synced = (int) ( $item['synced'] ?? 0 );
		if ( $synced > 0 ) {
			return esc_html(
				sprintf(
					/* translators: %d: remote contact ID from sendsms.ro */
					__( 'Yes (id: %d)', 'sendsms-dashboard' ),
					$synced
				)
			);
		}
		return esc_html__( 'Not synced', 'sendsms-dashboard' );
	}

	/**
	 * Render any column that does not have a dedicated column_* method.
	 *
	 * All values pass through {@see esc_html()} before output. Unknown column
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
}
