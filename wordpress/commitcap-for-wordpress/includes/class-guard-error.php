<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for structured CommitCap guard failures.
 *
 * A caller can distinguish:
 * - CommitCap\Budget_Denied: the #54 UPDATE budget denied an event;
 * - CommitCap\Unsupported_Transaction_State: the guard refused before running;
 * - CommitCap\Guard_Error: any other guard failure (callback, DB, commit).
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
