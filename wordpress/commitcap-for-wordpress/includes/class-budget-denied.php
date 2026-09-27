<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The #54 UPDATE row-event budget denied an event and the guard rolled back. */
final class Budget_Denied extends Guard_Error {
	private $details;

	public function __construct( array $details, ?\Throwable $previous = null ) {
		$this->details = $details;
		$message       = sprintf(
			'CommitCap denied the guarded UPDATE budget for %s (budget %s, consumed %s, attempted %s).',
			isset( $details['table'] ) ? (string) $details['table'] : '?',
			isset( $details['budget'] ) ? (string) $details['budget'] : '?',
			isset( $details['consumed'] ) ? (string) $details['consumed'] : '?',
			isset( $details['attempted'] ) ? (string) $details['attempted'] : '?'
		);
		parent::__construct( 'budget_denied', $message, $previous );
	}

	/** Structured denial facts: table, budget, consumed, attempted, reason, sqlstate, errno. */
	public function details(): array {
		return $this->details;
	}
}
