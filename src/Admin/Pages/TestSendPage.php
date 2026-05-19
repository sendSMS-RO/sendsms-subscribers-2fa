<?php
/**
 * Send a test SMS admin page.
 *
 * Renders a simple form that lets an administrator send a one-off test
 * message to a single phone number. The form submission is handled via
 * fetch() in admin.js which posts to
 * `admin-ajax.php?action=sendsms_dashboard_test_send`.
 *
 * Fields preserved from v1.x:
 *  - phone_number  (tel input, E.164 without +)
 *  - gdpr          (checkbox — appends unsubscribe link)
 *  - short         (checkbox — shrinks URLs)
 *  - message       (textarea)
 *
 * The nonce is provided via the `sendsmsDashboard.nonce` JS object that
 * Menu::enqueue_assets() localizes; no additional hidden field is required.
 *
 * @package SendSMS\Dashboard\Admin\Pages
 */

namespace SendSMS\Dashboard\Admin\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Test SMS page renderer.
 *
 * Usage — call from a menu callback after registering via Admin\Menu:
 *
 *   ( new TestSendPage() )->render();
 */
final class TestSendPage {

	/**
	 * Render the full admin page.
	 *
	 * Bails immediately when the current user lacks `manage_options`.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Send a test SMS', 'sendsms-dashboard' ); ?></h1>

			<form class="sendsms-dashboard-test-form" method="post">
				<table class="form-table" role="presentation">
					<tbody>

						<tr>
							<th scope="row">
								<label for="phone_number">
									<?php esc_html_e( 'Phone number', 'sendsms-dashboard' ); ?>
								</label>
							</th>
							<td>
								<input
									id="phone_number"
									name="phone_number"
									type="tel"
									class="regular-text"
									placeholder="40727363767"
									aria-label="<?php esc_attr_e( 'Phone number', 'sendsms-dashboard' ); ?>"
									aria-describedby="phone_number_help"
								>
								<p class="description" id="phone_number_help">
									<?php esc_html_e( 'We recommend a phone number in E.164 format but without the + sign.', 'sendsms-dashboard' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="gdpr">
									<?php esc_html_e( 'Add unsubscribe link?', 'sendsms-dashboard' ); ?>
								</label>
							</th>
							<td>
								<input
									id="gdpr"
									name="gdpr"
									type="checkbox"
									value="gdpr"
									aria-describedby="gdpr_help"
								>
								<p class="description" id="gdpr_help">
									<?php esc_html_e( 'You must include {gdpr} in your message. It will be replaced with a unique confirmation link. If omitted, the link is appended at the end.', 'sendsms-dashboard' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="short">
									<?php esc_html_e( 'Shrink URLs?', 'sendsms-dashboard' ); ?>
								</label>
							</th>
							<td>
								<input
									id="short"
									name="short"
									type="checkbox"
									value="short"
									aria-describedby="short_help"
								>
								<p class="description" id="short_help">
									<?php esc_html_e( 'Searches for long URLs and replaces them with short URLs. Use only URLs that start with https:// or http://.', 'sendsms-dashboard' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="message">
									<?php esc_html_e( 'Message', 'sendsms-dashboard' ); ?>
								</label>
							</th>
							<td>
								<textarea
									id="message"
									name="message"
									rows="4"
									class="large-text sendsms_dashboard_content"
									aria-label="<?php esc_attr_e( 'Message', 'sendsms-dashboard' ); ?>"
									aria-describedby="counterMessage"
									data-sendsms-counter="counterMessage"
								></textarea>
								<p id="counterMessage" class="description">
									<?php esc_html_e( 'The field is empty', 'sendsms-dashboard' ); ?>
								</p>
							</td>
						</tr>

					</tbody>
				</table>

				<?php
				submit_button(
					__( 'Send Message', 'sendsms-dashboard' ),
					'primary',
					'sendsms-dashboard-test-submit'
				);
				?>
			</form>
		</div>
		<?php
	}
}
