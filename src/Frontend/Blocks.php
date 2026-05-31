<?php
/**
 * Gutenberg block registration for the subscribe / unsubscribe forms.
 *
 * Dynamic (server-rendered) blocks: the editor uses `wp.serverSideRender` to
 * preview the block via the WordPress REST API, and the front end is rendered
 * by PHP — delegating to {@see Shortcodes::render_subscribe()} /
 * {@see Shortcodes::render_unsubscribe()} so the widget, shortcode, and block
 * always produce identical markup.
 *
 * No build step is required: the edit-time scripts are plain JavaScript that
 * reads from the `window.wp.*` globals shipped by WordPress core.
 *
 * @package Rosendsms\Dashboard\Frontend
 */

namespace Rosendsms\Dashboard\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the two `sendsms-dashboard/*` blocks.
 */
final class Blocks {

	/**
	 * Subscribe form renderer (reused for the block's render_callback).
	 *
	 * @var Shortcodes
	 */
	private $shortcodes;

	/**
	 * Constructor.
	 *
	 * @param Shortcodes $shortcodes Shortcode renderer (reused so widget,
	 *                               shortcode and block all share one path).
	 */
	public function __construct( Shortcodes $shortcodes ) {
		$this->shortcodes = $shortcodes;
	}

	/**
	 * Hook the block registration into `init`.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Register the editor scripts and the two block types.
	 *
	 * Fires on `init`. Safe to call before WordPress has rendered any output.
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$deps = array(
			'wp-blocks',
			'wp-element',
			'wp-block-editor',
			'wp-components',
			'wp-i18n',
			'wp-server-side-render',
		);

		wp_register_script(
			'rosendsms-dash-block-subscribe',
			ROSENDSMS_DASH_URL . 'blocks/subscribe/edit.js',
			$deps,
			ROSENDSMS_DASH_VERSION,
			true
		);

		wp_register_script(
			'rosendsms-dash-block-unsubscribe',
			ROSENDSMS_DASH_URL . 'blocks/unsubscribe/edit.js',
			$deps,
			ROSENDSMS_DASH_VERSION,
			true
		);

		register_block_type(
			ROSENDSMS_DASH_DIR . 'blocks/subscribe',
			array(
				'render_callback' => array( $this, 'render_subscribe' ),
			)
		);

		register_block_type(
			ROSENDSMS_DASH_DIR . 'blocks/unsubscribe',
			array(
				'render_callback' => array( $this, 'render_unsubscribe' ),
			)
		);
	}

	/**
	 * Render callback for `sendsms-dashboard/subscribe`.
	 *
	 * @param array $attributes Block attributes (title, gdpr_link).
	 * @return string
	 */
	public function render_subscribe( $attributes ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();
		return $this->shortcodes->render_subscribe(
			array(
				'title'     => isset( $attributes['title'] ) ? (string) $attributes['title'] : '',
				'gdpr_link' => isset( $attributes['gdpr_link'] ) ? (string) $attributes['gdpr_link'] : '',
			)
		);
	}

	/**
	 * Render callback for `sendsms-dashboard/unsubscribe`.
	 *
	 * @param array $attributes Block attributes (title).
	 * @return string
	 */
	public function render_unsubscribe( $attributes ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();
		return $this->shortcodes->render_unsubscribe(
			array(
				'title' => isset( $attributes['title'] ) ? (string) $attributes['title'] : '',
			)
		);
	}
}
