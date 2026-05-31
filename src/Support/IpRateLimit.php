<?php
/**
 * IP-based request rate limiter.
 *
 * Wraps the rate-limit algorithm from v1.x {@see SendSMSFunctions::too_many_requests()}
 * and exposes it as a typed service. Persistence is delegated to
 * {@see \Rosendsms\Dashboard\Storage\IpRepository}; the `ip_limit` setting is
 * read from {@see \Rosendsms\Dashboard\Storage\Settings}.
 *
 * @package Rosendsms\Dashboard\Support
 */

namespace Rosendsms\Dashboard\Support;

use Rosendsms\Dashboard\Storage\IpRepository;
use Rosendsms\Dashboard\Storage\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Determines whether a given IP address has exceeded the configured request
 * rate limit within the current counting window.
 *
 * The `ip_limit` setting is formatted as "<max>/<minutes>". A window value of
 * -1 or a max value less than 0 disables rate limiting entirely. An unparseable
 * setting also disables limiting (fail-open). These semantics match v1.x
 * exactly.
 *
 * Side effect: each call to {@see IpRateLimit::is_too_many()} may insert,
 * increment, or reset the IP row in the database — matching the v1.x callers
 * that called add_ip_address_db / get_ip_address_db themselves. New IPs are
 * auto-registered with request_no = 1 on first encounter.
 */
final class IpRateLimit {

	/**
	 * Plugin settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * IP address row persistence layer.
	 *
	 * @var IpRepository
	 */
	private $repo;

	/**
	 * Inject dependencies.
	 *
	 * @param Settings     $settings Plugin settings store.
	 * @param IpRepository $repo     IP address table CRUD.
	 */
	public function __construct( Settings $settings, IpRepository $repo ) {
		$this->settings = $settings;
		$this->repo     = $repo;
	}

	/**
	 * Whether the given IP has exceeded the configured rate limit.
	 *
	 * Mirrors v1.x too_many_requests exactly. The `ip_limit` setting format is
	 * "<max>/<minutes>". Special cases:
	 *  - window == -1  → limit disabled, always returns false.
	 *  - max < 0       → limit disabled, always returns false.
	 *  - unparseable   → limit disabled, always returns false.
	 *
	 * Side effect: when called, this also increments or resets the IP's cycle
	 * counter (matching v1.x behaviour). The first call for an unregistered IP
	 * registers it with request_no = 1 and returns false.
	 *
	 * @param string $ip Client IP address.
	 * @return bool True if the IP has exceeded the limit; false otherwise.
	 */
	public function is_too_many( string $ip ): bool {
		$raw   = (string) $this->settings->get( 'ip_limit', '' );
		$parts = explode( '/', $raw );
		if ( 2 !== count( $parts ) || ! is_numeric( $parts[0] ) || ! is_numeric( $parts[1] ) ) {
			return false;
		}

		$max    = (int) $parts[0];
		$window = (int) $parts[1];
		if ( -1 === $window || $max < 0 ) {
			return false;
		}

		if ( ! $this->repo->is_registered( $ip ) ) {
			$this->repo->register( $ip );
			return false;
		}

		$row = $this->repo->find( $ip );
		if ( null === $row ) {
			$this->repo->register( $ip );
			return false;
		}

		$cycle_start = isset( $row['date_cycle_start'] ) ? $row['date_cycle_start'] : current_time( 'mysql' );
		$attempts    = isset( $row['request_no'] ) ? (int) $row['request_no'] : 0;

		try {
			$start = new \DateTime( $cycle_start );
			$now   = new \DateTime( current_time( 'mysql' ) );
		} catch ( \Exception $e ) {
			return false;
		}

		$minutes_passed = abs( $now->getTimestamp() - $start->getTimestamp() ) / 60;

		if ( $minutes_passed < $window ) {
			if ( $attempts >= $max ) {
				return true;
			}
			$this->repo->increment( $ip );
			return false;
		}

		$this->repo->reset( $ip );
		return false;
	}
}
