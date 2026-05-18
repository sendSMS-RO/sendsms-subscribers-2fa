<?php
/**
 * Subscribe widget — lets site visitors opt in to the SMS newsletter.
 *
 * Registers a classic WP_Widget that renders a phone-number form with optional
 * first/last name fields, a GDPR consent checkbox, and a two-step verification
 * UI (revealed by JS when the first AJAX call indicates a code was sent).
 *
 * AJAX actions consumed (registered in Frontend\SubscribeAjax and
 * Frontend\VerifyCodeAjax via Plugin::boot()):
 *  - sendsms_dashboard_subscribe      — first step; triggers the SMS code
 *  - sendsms_dashboard_verify_code    — second step; validates the code
 *
 * The public JS and nonce are enqueued by Plugin::boot() via
 * wp_enqueue_scripts / wp_localize_script.
 *
 * @package SendSMS\Dashboard\Widgets
 */

namespace SendSMS\Dashboard\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * SMS subscribe widget.
 *
 * Widget ID base: sendsms_dashboard_subscribe
 * Widget settings: title (text), gdpr_link (URL)
 */
final class SubscribeWidget extends \WP_Widget {

	/**
	 * Registers the widget with WordPress.
	 */
	public function __construct() {
		parent::__construct(
			'sendsms_dashboard_subscriber',
			__( 'SendSMS Subscribe', 'sendsms-dashboard' ),
			array(
				'classname'                   => 'sendsms_dashboard_subscriber',
				'description'                 => __( 'Let visitors subscribe to your SMS newsletter. GDPR-compliant with optional two-step phone verification.', 'sendsms-dashboard' ),
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
		$title     = ! empty( $instance['title'] ) ? apply_filters( 'widget_title', $instance['title'] ) : '';
		$gdpr_link = ! empty( $instance['gdpr_link'] ) ? esc_url( $instance['gdpr_link'] ) : '';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP supplied
		echo $args['before_widget'];

		if ( $title ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP supplied
			echo $args['before_title'] . esc_html( $title ) . $args['after_title'];
		}
		?>
		<form class="sendsms-dashboard-subscribe" method="post" novalidate>
			<p class="sendsms-dashboard-field">
				<label for="sendsms-subscribe-first-name-<?php echo esc_attr( $this->id ); ?>">
					<?php esc_html_e( 'First name', 'sendsms-dashboard' ); ?>
				</label>
				<input
					type="text"
					id="sendsms-subscribe-first-name-<?php echo esc_attr( $this->id ); ?>"
					name="first_name"
					autocomplete="given-name"
				/>
			</p>

			<p class="sendsms-dashboard-field">
				<label for="sendsms-subscribe-last-name-<?php echo esc_attr( $this->id ); ?>">
					<?php esc_html_e( 'Last name', 'sendsms-dashboard' ); ?>
				</label>
				<input
					type="text"
					id="sendsms-subscribe-last-name-<?php echo esc_attr( $this->id ); ?>"
					name="last_name"
					autocomplete="family-name"
				/>
			</p>

			<p class="sendsms-dashboard-field">
				<label for="sendsms-subscribe-phone-<?php echo esc_attr( $this->id ); ?>">
					<?php esc_html_e( 'Phone number', 'sendsms-dashboard' ); ?>
				</label>
				<input
					type="tel"
					id="sendsms-subscribe-phone-<?php echo esc_attr( $this->id ); ?>"
					name="phone_number"
					autocomplete="tel"
					required
				/>
			</p>

			<p class="sendsms-dashboard-field sendsms-dashboard-gdpr">
				<label>
					<input type="checkbox" name="gdpr" value="1" required />
					<?php
					if ( $gdpr_link ) {
						$link_open  = '<a href="' . esc_url( $gdpr_link ) . '" target="_blank" rel="noopener">';
						$link_close = '</a>';
						echo wp_kses(
							sprintf(
								/* translators: 1: opening <a> tag, 2: closing </a> tag. */
								__( 'I agree with the %1$sprivacy policy%2$s', 'sendsms-dashboard' ),
								$link_open,
								$link_close
							),
							array(
								'a' => array(
									'href'   => true,
									'target' => true,
									'rel'    => true,
								),
							)
						);
					} else {
						esc_html_e( 'I agree with the privacy policy', 'sendsms-dashboard' );
					}
					?>
				</label>
			</p>

			<p class="sendsms-dashboard-field">
				<button type="submit" class="button">
					<?php esc_html_e( 'Subscribe', 'sendsms-dashboard' ); ?>
				</button>
			</p>

			<div class="sendsms-dashboard-verify" hidden>
				<p class="sendsms-dashboard-field">
					<label for="sendsms-subscribe-code-<?php echo esc_attr( $this->id ); ?>">
						<?php esc_html_e( 'Verification code', 'sendsms-dashboard' ); ?>
					</label>
					<input
						type="text"
						id="sendsms-subscribe-code-<?php echo esc_attr( $this->id ); ?>"
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
		$title     = ! empty( $instance['title'] ) ? $instance['title'] : '';
		$gdpr_link = ! empty( $instance['gdpr_link'] ) ? $instance['gdpr_link'] : '';
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
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'gdpr_link' ) ); ?>">
				<?php esc_html_e( 'GDPR / Privacy policy URL:', 'sendsms-dashboard' ); ?>
			</label>
			<input
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'gdpr_link' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'gdpr_link' ) ); ?>"
				type="url"
				value="<?php echo esc_url( $gdpr_link ); ?>"
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
		$instance              = array();
		$instance['title']     = ! empty( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '';
		$instance['gdpr_link'] = ! empty( $new_instance['gdpr_link'] ) ? esc_url_raw( $new_instance['gdpr_link'] ) : '';

		return $instance;
	}
}
