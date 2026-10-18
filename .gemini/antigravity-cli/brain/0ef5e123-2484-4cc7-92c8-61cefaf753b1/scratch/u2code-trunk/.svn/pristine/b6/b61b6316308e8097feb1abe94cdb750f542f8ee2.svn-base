<?php namespace BulkPriceEditor\History;

/**
 * A single bulk update (or revert) run — one row of the runs table.
 */
class Run {

	const TYPE_UPDATE = 'update';
	const TYPE_REVERT = 'revert';

	const STATUS_SCHEDULED = 'scheduled';
	const STATUS_RUNNING = 'running';
	const STATUS_COMPLETED = 'completed';
	const STATUS_STOPPED = 'stopped';
	const STATUS_CANCELED = 'canceled';
	const STATUS_FAILED = 'failed';

	protected array $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function getId(): int {
		return (int) ( $this->data['id'] ?? 0 );
	}

	public function getType(): string {
		return (string) ( $this->data['type'] ?? self::TYPE_UPDATE );
	}

	public function isRevert(): bool {
		return self::TYPE_REVERT === $this->getType();
	}

	public function getStatus(): string {
		return (string) ( $this->data['status'] ?? '' );
	}

	public function getParentId(): int {
		return (int) ( $this->data['parent_id'] ?? 0 );
	}

	public function getUserId(): int {
		return (int) ( $this->data['user_id'] ?? 0 );
	}

	public function getModifierType(): string {
		return (string) ( $this->data['modifier_type'] ?? '' );
	}

	public function getModifierArgs(): array {
		return $this->decodeJson( $this->data['modifier_args'] ?? '' );
	}

	public function getFilters(): array {
		return $this->decodeJson( $this->data['filters'] ?? '' );
	}

	public function getTotal(): int {
		return (int) ( $this->data['total'] ?? 0 );
	}

	public function getProcessed(): int {
		return (int) ( $this->data['processed'] ?? 0 );
	}

	public function isReverted(): bool {
		return ! empty( $this->data['reverted_at'] );
	}

	/**
	 * UTC datetime columns as Unix timestamps (null when not set).
	 */
	public function getTimestamp( string $column ): ?int {

		$value = $this->data[ $column ] ?? null;

		if ( empty( $value ) || '0000-00-00 00:00:00' === $value ) {
			return null;
		}

		$timestamp = strtotime( $value . ' UTC' );

		return $timestamp ? $timestamp : null;
	}

	public function getRaw( string $key ) {
		return $this->data[ $key ] ?? null;
	}

	public function toArray(): array {
		return $this->data;
	}

	protected function decodeJson( $value ): array {

		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}

		return (array) json_decode( $value, true );
	}
}
