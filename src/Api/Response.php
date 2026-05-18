<?php
/**
 * Value object representing the result of a sendsms.ro API call.
 *
 * @package SendSMS\Dashboard\Api
 */

namespace SendSMS\Dashboard\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Represents the outcome of a sendsms.ro API request.
 *
 * Holds success status, response data, error details, HTTP status, and raw response body.
 * Always returned (never thrown); callers branch on is_success().
 */
final class Response {

	/**
	 * Whether the API call succeeded.
	 *
	 * @var bool
	 */
	private $success;

	/**
	 * The decoded response data (only populated on success).
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Error message (only populated on failure).
	 *
	 * @var string
	 */
	private $error_message;

	/**
	 * HTTP status code from the API response.
	 *
	 * @var int
	 */
	private $http_status;

	/**
	 * The raw response body.
	 *
	 * @var string
	 */
	private $raw_body;

	/**
	 * Constructor.
	 *
	 * @param bool   $success       Whether the API call succeeded.
	 * @param array  $data          Response data (empty array if not success).
	 * @param string $error_message Error message (empty string if success).
	 * @param int    $http_status   HTTP status code.
	 * @param string $raw_body      Raw response body.
	 */
	public function __construct( bool $success, array $data, string $error_message, int $http_status, string $raw_body ) {
		$this->success       = $success;
		$this->data          = $data;
		$this->error_message = $error_message;
		$this->http_status   = $http_status;
		$this->raw_body      = $raw_body;
	}

	/**
	 * Factory for successful responses.
	 *
	 * @param array  $data        Response data.
	 * @param int    $http_status HTTP status code.
	 * @param string $raw_body    Raw response body.
	 *
	 * @return self
	 */
	public static function success( array $data, int $http_status, string $raw_body ): self {
		return new self( true, $data, '', $http_status, $raw_body );
	}

	/**
	 * Factory for failed responses.
	 *
	 * @param string $error_message Error message.
	 * @param int    $http_status   HTTP status code (optional, defaults to 0).
	 * @param string $raw_body      Raw response body (optional, defaults to empty string).
	 *
	 * @return self
	 */
	public static function failure( string $error_message, int $http_status = 0, string $raw_body = '' ): self {
		return new self( false, array(), $error_message, $http_status, $raw_body );
	}

	/**
	 * Returns whether the API call succeeded.
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return $this->success;
	}

	/**
	 * Returns the response data.
	 *
	 * @return array
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Returns the error message.
	 *
	 * @return string
	 */
	public function error_message(): string {
		return $this->error_message;
	}

	/**
	 * Returns the HTTP status code.
	 *
	 * @return int
	 */
	public function http_status(): int {
		return $this->http_status;
	}

	/**
	 * Returns the raw response body.
	 *
	 * @return string
	 */
	public function raw_body(): string {
		return $this->raw_body;
	}
}
