<?php
namespace WriteLeash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for structured WriteLeash guard failures.
 *
 * A caller can distinguish:
 * - WriteLeash\Budget_Denied: the #54 UPDATE budget denied an event;
 * - WriteLeash\Unsupported_Transaction_State: the guard refused before running;
 * - WriteLeash\Guard_Error: any other guard failure (callback, DB, commit).
 */
class Guard_Error extends \RuntimeException {
	private $reason;

	public function __construct( $reason, $message = '', ?\Throwable $previous = null ) {
		parent::__construct( (string) $message, 0, $previous );
		$this->reason = (string) $reason;
	}

	/** Short machine-readable failure reason, for example 'database_error'. */
	public function reason(): string {
		return $this->reason;
	}
}
