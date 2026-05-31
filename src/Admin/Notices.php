<?php
/**
 * Admin notices queue and renderer.
 *
 * Accumulates pending admin notices in an option and renders them once on
 * the admin_notices hook, then clears the queue so notices don't repeat.
 *
 * @package Rosendsms\Dashboard\Admin
 */

namespace Rosendsms\Dashboard\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Notices helper.
 *
 * Manages queuing and rendering of admin notices that persist across page loads.
 */
final class Notices {

	/**
	 * Option key for pending notices.
	 *
	 * @var string
	 */
	private const OPTION = 'rosendsms_dash_pending_notices';

	/**
	 * Attach the renderer to admin_notices.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Queue a notice for the next admin_notices fire.
	 *
	 * @param string $level   One of: error, warning, info, success.
	 * @param string $message Notice text (will be rendered through wp_kses_post).
	 * @return void
	 */
	public static function queue( string $level, string $message ): void {
		$pending   = (array) get_option( self::OPTION, array() );
		$pending[] = array(
			'level'   => in_array( $level, array( 'error', 'warning', 'info', 'success' ), true ) ? $level : 'info',
			'message' => $message,
		);
		update_option( self::OPTION, $pending, false );
	}

	/**
	 * Render and clear all pending notices.
	 *
	 * @return void
	 */
	public function render(): void {
		$pending = (array) get_option( self::OPTION, array() );
		if ( ! $pending ) {
			return;
		}
		delete_option( self::OPTION );
		foreach ( $pending as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$level   = isset( $row['level'] ) ? (string) $row['level'] : 'info';
			$message = isset( $row['message'] ) ? (string) $row['message'] : '';
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $level ),
				wp_kses_post( $message )
			);
		}
	}
}
