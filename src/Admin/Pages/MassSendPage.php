<?php
/**
 * Mass SMS sending admin page.
 *
 * Renders a form that lets an administrator blast a message to all plugin
 * subscribers or to WordPress users filtered by role. The form submission
 * is handled via fetch() in admin.js which posts to
 * `admin-ajax.php?action=rosendsms_dash_mass_send`.
 *
 * Fields preserved from v1.x:
 *  - receiver_type  (radio: "subscribers" | "users")
 *  - role           (select — visible only when receiver_type === "users";
 *                    iterates get_editable_roles(), "all" option included)
 *  - gdpr           (checkbox — appends unsubscribe link)
 *  - short          (checkbox — shrinks URLs)
 *  - message        (textarea)
 *
 * The nonce is provided via the `rosendsmsDash.nonce` JS object that
 * Menu::enqueue_assets() localizes; no additional hidden field is required.
 *
 * @package Rosendsms\Dashboard\Admin\Pages
 */

namespace Rosendsms\Dashboard\Admin\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Mass SMS page renderer.
 *
 * Usage — call from a menu callback after registering via Admin\Menu:
 *
 *   ( new MassSendPage() )->render();
 */
final class MassSendPage {

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

		$roles = get_editable_roles();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Send mass SMS', 'sendsms-subscribers-2fa' ); ?></h1>

			<form class="sendsms-dashboard-mass-form" method="post">
				<table class="form-table" role="presentation">
					<tbody>

						<tr>
							<th scope="row">
								<?php esc_html_e( 'Send to', 'sendsms-subscribers-2fa' ); ?>
							</th>
							<td>
								<fieldset>
									<legend class="screen-reader-text">
										<span><?php esc_html_e( 'Send to', 'sendsms-subscribers-2fa' ); ?></span>
									</legend>
									<label>
										<input
											type="radio"
											name="receiver_type"
											id="receiver_type_subscribers"
											value="subscribers"
											checked="checked"
										>
										<?php esc_html_e( 'All SMS subscribers', 'sendsms-subscribers-2fa' ); ?>
									</label>
									<br>
									<label>
										<input
											type="radio"
											name="receiver_type"
											id="receiver_type_users"
											value="users"
										>
										<?php esc_html_e( 'WordPress users with role&hellip;', 'sendsms-subscribers-2fa' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>

						<tr id="sendsms-role-row" class="sendsms-hidden" style="display:none;">
							<th scope="row">
								<label for="role">
									<?php esc_html_e( 'Role', 'sendsms-subscribers-2fa' ); ?>
								</label>
							</th>
							<td>
								<select id="role" name="role" aria-describedby="role_help">
									<option value="all"><?php esc_html_e( 'All roles', 'sendsms-subscribers-2fa' ); ?></option>
									<?php foreach ( $roles as $key => $role_data ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>">
											<?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description" id="role_help">
									<?php esc_html_e( 'Choose the specific role you want to send the message to.', 'sendsms-subscribers-2fa' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="gdpr">
									<?php esc_html_e( 'Add unsubscribe link?', 'sendsms-subscribers-2fa' ); ?>
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
									<?php esc_html_e( 'You must include {gdpr} in your message. It will be replaced with a unique confirmation link. If omitted, the link is appended at the end.', 'sendsms-subscribers-2fa' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="short">
									<?php esc_html_e( 'Shrink URLs?', 'sendsms-subscribers-2fa' ); ?>
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
									<?php esc_html_e( 'Searches for long URLs and replaces them with short URLs. Use only URLs that start with https:// or http://.', 'sendsms-subscribers-2fa' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="message">
									<?php esc_html_e( 'Message', 'sendsms-subscribers-2fa' ); ?>
								</label>
							</th>
							<td>
								<textarea
									id="message"
									name="message"
									rows="4"
									class="large-text sendsms_dashboard_content"
									aria-label="<?php esc_attr_e( 'Message', 'sendsms-subscribers-2fa' ); ?>"
									aria-describedby="counterMessage"
									data-sendsms-counter="counterMessage"
								></textarea>
								<p id="counterMessage" class="description">
									<?php esc_html_e( 'The field is empty', 'sendsms-subscribers-2fa' ); ?>
								</p>
							</td>
						</tr>

					</tbody>
				</table>

				<?php
				submit_button(
					__( 'Send Message', 'sendsms-subscribers-2fa' ),
					'primary',
					'sendsms-dashboard-mass-submit'
				);
				?>
			</form>
		</div>
		<?php
	}
}
