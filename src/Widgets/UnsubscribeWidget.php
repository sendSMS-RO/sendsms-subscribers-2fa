<?php
/**
 * Unsubscribe widget — lets site visitors opt out of the SMS newsletter.
 *
 * Registers a classic WP_Widget that renders a phone-number form with
 * a two-step verification UI (revealed by JS when the first AJAX call
 * indicates a code was sent).
 *
 * AJAX actions consumed (registered in Frontend\UnsubscribeAjax and
 * Frontend\VerifyCodeAjax via Plugin::boot()):
 *  - rosendsms_dash_unsubscribe     — first step; triggers the SMS code
 *  - rosendsms_dash_verify_code    — second step; validates the code
 *
 * The public JS and nonce are enqueued by Plugin::boot() via
 * wp_enqueue_scripts / wp_localize_script.
 *
 * @package Rosendsms\Dashboard\Widgets
 */

namespace Rosendsms\Dashboard\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * SMS unsubscribe widget.
 *
 * Widget ID base: sendsms_dashboard_unsubscribe
 * Widget settings: title (text)
 */
final class UnsubscribeWidget extends \WP_Widget {

	/**
	 * Registers the widget with WordPress.
	 */
	public function __construct() {
		parent::__construct(
			'sendsms_dashboard_unsubscribe',
			__( 'SendSMS Unsubscribe', 'sendsms-dashboard' ),
			array(
				'classname'                   => 'sendsms_dashboard_unsubscribe',
				'description'                 => __( 'Let visitors unsubscribe from your SMS newsletter.', 'sendsms-dashboard' ),
				'customize_selective_refresh' => true,
			)
		);
	}

	/**
	 * Outputs the widget content on the front end.
	 *
	 * @param array $args     Display arguments (before_widget, after_widget, etc.).
	 * @param array $instance Saved instance settings.
	 * @return void
	 */
	public function widget( $args, $instance ): void {
		$title = ! empty( $instance['title'] ) ? apply_filters( 'widget_title', $instance['title'] ) : '';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP supplied
		echo $args['before_widget'];

		if ( $title ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP supplied
			echo $args['before_title'] . esc_html( $title ) . $args['after_title'];
		}
		?>
		<form class="sendsms-dashboard-unsubscribe" method="post" novalidate>
			<p class="sendsms-dashboard-field">
				<label for="sendsms-unsubscribe-phone-<?php echo esc_attr( $this->id ); ?>">
					<?php esc_html_e( 'Phone number', 'sendsms-dashboard' ); ?>
				</label>
				<input
					type="tel"
					id="sendsms-unsubscribe-phone-<?php echo esc_attr( $this->id ); ?>"
					name="phone_number"
					autocomplete="tel"
					required
				/>
			</p>

			<p class="sendsms-dashboard-field">
				<button type="submit" class="button">
					<?php esc_html_e( 'Unsubscribe', 'sendsms-dashboard' ); ?>
				</button>
			</p>

			<div class="sendsms-dashboard-verify" hidden>
				<p class="sendsms-dashboard-field">
					<label for="sendsms-unsubscribe-code-<?php echo esc_attr( $this->id ); ?>">
						<?php esc_html_e( 'Verification code', 'sendsms-dashboard' ); ?>
					</label>
					<input
						type="text"
						id="sendsms-unsubscribe-code-<?php echo esc_attr( $this->id ); ?>"
						name="code"
						inputmode="numeric"
						autocomplete="one-time-code"
					/>
				</p>
				<p class="sendsms-dashboard-field">
					<button type="button" class="button" data-action="verify">
						<?php esc_html_e( 'Verify code', 'sendsms-dashboard' ); ?>
					</button>
				</p>
			</div>

			<p class="sendsms-dashboard-feedback" role="status"></p>
		</form>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP supplied
		echo $args['after_widget'];
	}

	/**
	 * Outputs the widget settings form in the admin widgets screen.
	 *
	 * @param array $instance Current saved instance settings.
	 * @return void
	 */
	public function form( $instance ): void {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Title:', 'sendsms-dashboard' ); ?>
			</label>
			<input
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $title ); ?>"
			/>
		</p>
		<?php
	}

	/**
	 * Sanitizes widget settings on save.
	 *
	 * @param array $new_instance New settings as submitted by the user.
	 * @param array $old_instance Previously saved settings.
	 * @return array Sanitized settings array.
	 */
	public function update( $new_instance, $old_instance ): array {
		$instance          = array();
		$instance['title'] = ! empty( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '';

		return $instance;
	}
}
