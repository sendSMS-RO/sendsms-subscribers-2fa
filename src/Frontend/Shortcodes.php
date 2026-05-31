<?php
/**
 * Subscribe / unsubscribe shortcodes.
 *
 * Registers `[sendsms_subscribe]` and `[sendsms_unsubscribe]` so the same forms
 * the SubscribeWidget / UnsubscribeWidget render in classic widget areas can be
 * dropped anywhere a shortcode is honoured (posts, pages, block editor's
 * Shortcode block, page builders, etc.). This is the workaround for block
 * themes where the Legacy Widget block isn't exposed in the site editor's
 * block inserter.
 *
 * The rendered HTML and class names match the widget output exactly, so the
 * public JS (assets/js/public.js) keeps working without changes.
 *
 * Supported attributes:
 *  - title     (string, default '')    Optional heading above the form.
 *  - gdpr_link (string, default '')    Privacy-policy URL for the subscribe form.
 *
 * @package Rosendsms\Dashboard\Frontend
 */

namespace Rosendsms\Dashboard\Frontend;

use Rosendsms\Dashboard\Widgets\SubscribeWidget;
use Rosendsms\Dashboard\Widgets\UnsubscribeWidget;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode wrappers around the two public widgets.
 */
final class Shortcodes {

	/**
	 * Register the shortcode handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'sendsms_subscribe', array( $this, 'render_subscribe' ) );
		add_shortcode( 'sendsms_unsubscribe', array( $this, 'render_unsubscribe' ) );
	}

	/**
	 * `[sendsms_subscribe]` — renders the subscribe form by delegating to the
	 * SubscribeWidget's `widget()` method.
	 *
	 * @param array|string $atts Shortcode attributes (title, gdpr_link).
	 * @return string The captured form HTML.
	 */
	public function render_subscribe( $atts ): string {
		$atts = shortcode_atts(
			array(
				'title'     => '',
				'gdpr_link' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'sendsms_subscribe'
		);

		ob_start();
		$widget = new SubscribeWidget();
		$widget->widget(
			array(
				'before_widget' => '<div class="sendsms-dashboard-shortcode sendsms-dashboard-shortcode-subscribe">',
				'after_widget'  => '</div>',
				'before_title'  => '<h3>',
				'after_title'   => '</h3>',
			),
			array(
				'title'     => $atts['title'],
				'gdpr_link' => $atts['gdpr_link'],
			)
		);
		return (string) ob_get_clean();
	}

	/**
	 * `[sendsms_unsubscribe]` — renders the unsubscribe form by delegating to
	 * the UnsubscribeWidget's `widget()` method.
	 *
	 * @param array|string $atts Shortcode attributes (title only).
	 * @return string The captured form HTML.
	 */
	public function render_unsubscribe( $atts ): string {
		$atts = shortcode_atts(
			array(
				'title' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'sendsms_unsubscribe'
		);

		ob_start();
		$widget = new UnsubscribeWidget();
		$widget->widget(
			array(
				'before_widget' => '<div class="sendsms-dashboard-shortcode sendsms-dashboard-shortcode-unsubscribe">',
				'after_widget'  => '</div>',
				'before_title'  => '<h3>',
				'after_title'   => '</h3>',
			),
			array(
				'title' => $atts['title'],
			)
		);
		return (string) ob_get_clean();
	}
}
