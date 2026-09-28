<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable descriptor for the one Free V0.1 certified operation (#78):
 *
 *   Redirection 5.5.2
 *   Redirects -> select all matching -> Bulk Actions -> Disable
 *
 * This is the smallest authoritative code representation of the operation
 * proven by #87. Every security-sensitive fact (plugin slug/version, REST
 * route/shape, target table suffix, mutation, required target grants, adapter
 * class and physical ceiling) is reviewed code here and is never stored in
 * WordPress options or supplied by an Admin. The only mutable product state is
 * `enabled` and `logical_budget` in Operation_Config.
 *
 * The class is final with a private constructor and no setters: one shared
 * instance per known operation, looked up by exact ID. `find()` never derives
 * a PHP class name or instantiates anything from caller input; unknown IDs
 * return null.
 */
final class Certified_Operation {
	public const DESCRIPTOR_VERSION = '1';

	public const REDIRECTION_BULK_DISABLE_ID = 'redirection-5.5.2-bulk-disable-global';

	/** @var array<string, mixed> */
	private $facts;

	/**
	 * @param array<string, mixed> $facts Reviewed, code-known facts only.
	 */
	private function __construct( array $facts ) {
		$this->facts = $facts;
	}

	/** The single certified Free V0.1 operation. */
	public static function redirection_5_5_2_bulk_disable(): self {
		static $operation = null;
		if ( null === $operation ) {
			$operation = new self( array(
				'id'                     => self::REDIRECTION_BULK_DISABLE_ID,
				'display_name'           => 'Redirection: disable all matching redirects',
				'plugin_slug'            => 'redirection',
				'operation_kind'         => 'bulk_status_update',
				'rest_route'             => '/redirection/v1/bulk/redirect/disable',
				'rest_method'            => 'POST',
				'rest_bulk_action'       => 'disable',
				'table_suffix'           => 'redirection_items',
				'mutation'               => 'UPDATE',
				'target_privileges'      => array( 'SELECT', 'UPDATE' ),
				'physical_ceiling'       => 2000,
				'logical_budget_min'     => 0,
				'logical_budget_max'     => 2000,
			) );
		}
		return $operation;
	}

	/**
	 * Exact reviewed lookup. Unknown, empty or non-string IDs return null; no
	 * dynamic class/table/callback resolution is ever derived from the ID.
	 */
	public static function find( $id ): ?self {
		if ( ! is_string( $id ) || self::REDIRECTION_BULK_DISABLE_ID !== $id ) {
			return null;
		}
		return self::redirection_5_5_2_bulk_disable();
	}

	public function id(): string {
		return (string) $this->facts['id'];
	}

	public function descriptor_version(): string {
		return self::DESCRIPTOR_VERSION;
	}

	public function display_name(): string {
		return (string) $this->facts['display_name'];
	}

	public function plugin_slug(): string {
		return (string) $this->facts['plugin_slug'];
	}

	/**
	 * The one certified plugin version. The literal lives in the adapter's
	 * SUPPORTED_VERSION constant so descriptor, adapter and REST gate share a
	 * single canonical value.
	 */
	public function plugin_version(): string {
		return Redirection_Bulk_Disable::SUPPORTED_VERSION;
	}

	public function operation_kind(): string {
		return (string) $this->facts['operation_kind'];
	}

	public function rest_route(): string {
		return (string) $this->facts['rest_route'];
	}

	public function rest_method(): string {
		return (string) $this->facts['rest_method'];
	}

	/** Exact `bulk` action the certified route performs. */
	public function rest_bulk_action(): string {
		return (string) $this->facts['rest_bulk_action'];
	}

	/** Registered-route prefix, derived from the reviewed concrete route. */
	public function rest_route_prefix(): string {
		$route = $this->rest_route();
		$slash = strrpos( $route, '/' );
		return false === $slash ? $route : substr( $route, 0, $slash + 1 );
	}

	public function matches_rest( string $route, string $method ): bool {
		return $this->rest_route() === $route && 0 === strcasecmp( $this->rest_method(), $method );
	}

	/** Immutable table suffix; resolved against the active prefix by callers. */
	public function table_suffix(): string {
		return (string) $this->facts['table_suffix'];
	}

	public function mutation(): string {
		return (string) $this->facts['mutation'];
	}

	/** @return array<int, string> */
	public function target_privileges(): array {
		return array_values( $this->facts['target_privileges'] );
	}

	/** Code-known adapter class for this operation. */
	public function adapter_class(): string {
		return Redirection_Bulk_Disable::class;
	}

	public function physical_ceiling(): int {
		return (int) $this->facts['physical_ceiling'];
	}

	public function logical_budget_min(): int {
		return (int) $this->facts['logical_budget_min'];
	}

	public function logical_budget_max(): int {
		return (int) $this->facts['logical_budget_max'];
	}

	public function supports_logical_budget( $budget ): bool {
		return is_int( $budget ) && $budget >= $this->logical_budget_min() && $budget <= $this->logical_budget_max();
	}
}
