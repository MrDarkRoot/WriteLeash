<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Conservative interpretation of SHOW GRANTS for the authenticated account. */
final class Compatibility_Grants {
	private const ROUTINES = array(
		'commitcap_v01_open', 'commitcap_v01_close',
		'commitcap_v01_count', 'commitcap_v01_policy',
	);
	private const DDL = array(
		'CREATE', 'ALTER', 'DROP', 'TRIGGER', 'CREATE ROUTINE',
		'ALTER ROUTINE', 'SUPER', 'SYSTEM_USER', 'SET_USER_ID',
		'SYSTEM_VARIABLES_ADMIN', 'SESSION_VARIABLES_ADMIN',
		'PERSIST_RO_VARIABLES_ADMIN', 'BINLOG ADMIN', 'SET USER',
		'ROLE_ADMIN', 'READ_ONLY ADMIN', 'CREATE USER',
		'CREATE TEMPORARY TABLES', 'GRANT OPTION', 'PROXY', 'FILE',
	);
	private $grants;
	private $schema;
	private $complete;

	private function __construct( array $grants, string $schema, bool $complete = true ) {
		$this->grants = $grants;
		$this->schema = $schema;
		$this->complete = $complete;
	}

	/** A failed, truncated or unfamiliar grant format is never proof of absence. */
	public static function read( \wpdb $db, string $schema ): ?self {
		$rows = $db->get_results( 'SHOW GRANTS', ARRAY_N );
		if ( ! is_array( $rows ) || ! $rows || '' !== (string) $db->last_error ) {
			return null;
		}
		$grants = array();
		$complete = true;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || 1 !== count( $row ) || ! is_string( $row[0] ) ) {
				return null;
			}
			$parsed = self::parse( $row[0] );
			if ( null === $parsed ) {
				$complete = false;
				continue;
			}
			$grants[] = $parsed;
		}
		return new self( $grants, $schema, $complete );
	}

	/** Also used by tests of unfamiliar/role grant fail-closed behavior. */
	public static function from_statements( array $statements, string $schema ): ?self {
		$grants = array();
		foreach ( $statements as $statement ) {
			$parsed = is_string( $statement ) ? self::parse( $statement ) : null;
			if ( null === $parsed ) {
				return null;
			}
			$grants[] = $parsed;
		}
		return $grants ? new self( $grants, $schema ) : null;
	}

	private static function parse( string $statement ): ?array {
		// Role inheritance, partial revokes and alternate grant syntax require an
		// effective-privilege expansion we cannot safely infer from these rows.
		if ( preg_match( '/\AGRANT PROXY ON .+ TO .+\z/iD', $statement ) ) {
			return array( 'privileges' => array( 'PROXY' ), 'database' => '*', 'object' => '*', 'kind' => '' );
		}
		if ( ! preg_match( '/\AGRANT (.+?) ON (?:(PROCEDURE|FUNCTION) )?(.+?) TO (.+)\z/iD', $statement, $parts ) ||
			stripos( $statement, ' REVOKE ' ) !== false ) {
			return null;
		}
		$scope = $parts[3];
		if ( '*.*' === $scope ) {
			$database = '*';
			$object = '*';
		} elseif ( preg_match( '/\A(?:`((?:``|[^`])+)`|([A-Za-z0-9_]+))\.(?:`((?:``|[^`])+)`|([A-Za-z0-9_*]+))\z/D', $scope, $names ) ) {
			$database = str_replace( '``', '`', isset( $names[1] ) && '' !== $names[1] ? $names[1] : $names[2] );
			$object = str_replace( '``', '`', isset( $names[3] ) && '' !== $names[3] ? $names[3] : $names[4] );
		} else {
			return null;
		}
		$privileges = array_map( 'trim', explode( ',', strtoupper( $parts[1] ) ) );
		foreach ( $privileges as $privilege ) {
			if ( ! preg_match( '/\A[A-Z_ ]+\z/D', $privilege ) ) {
				return null;
			}
		}
		if ( stripos( $parts[4], 'WITH GRANT OPTION' ) !== false ) {
			$privileges[] = 'GRANT OPTION';
		}
		return array(
			'privileges' => $privileges,
			'database' => $database,
			'object' => $object,
			'kind' => isset( $parts[2] ) ? strtoupper( $parts[2] ) : '',
		);
	}

	private function applies( array $grant ): bool {
		return '*' === $grant['database'] || $this->schema === $grant['database'];
	}

	/** Return a machine status and a secret-free explanation. */
	public function runtime(): array {
		$reviewed = array();
		$ambiguous_scope = false;
		foreach ( $this->grants as $grant ) {
			if ( '*' !== $grant['database'] && $this->schema !== $grant['database'] &&
				strpbrk( $grant['database'], '%_\\' ) !== false ) {
				$ambiguous_scope = true; // MySQL database grant patterns may match this schema.
			}
			$privileges = $grant['privileges'];
			if ( in_array( 'ALL', $privileges, true ) || in_array( 'ALL PRIVILEGES', $privileges, true ) ) {
				return array( 'FAIL', 'ALL privileges includes unreviewed EXECUTE or object-tampering authority.' );
			}
			foreach ( $privileges as $privilege ) {
				if ( 'EXECUTE' === $privilege ) {
					if ( 'PROCEDURE' !== $grant['kind'] || $this->schema !== $grant['database'] ||
						! in_array( $grant['object'], self::ROUTINES, true ) ) {
						return array( 'FAIL', 'EXECUTE extends beyond the four reviewed CommitCap procedures.' );
					}
					$reviewed[ $grant['object'] ] = true;
				}
				// TRIGGER is code-creation authority on any schema: a trigger body
				// executes inside the guarded connection but is invisible to the
				// lexical Guard monitor. The runtime account never needs it.
				if ( 'TRIGGER' === $privilege ) {
					return array( 'FAIL', 'Runtime account holds TRIGGER authority; server-side trigger code can reset authority outside the Guard monitor.' );
				}
				if ( ( $this->applies( $grant ) || in_array( $privilege, array(
					'CREATE ROUTINE', 'ALTER ROUTINE', 'SUPER', 'SYSTEM_USER', 'SET_USER_ID',
					'SYSTEM_VARIABLES_ADMIN', 'SESSION_VARIABLES_ADMIN',
					'PERSIST_RO_VARIABLES_ADMIN', 'BINLOG ADMIN', 'SET USER',
					'ROLE_ADMIN', 'READ_ONLY ADMIN', 'CREATE USER', 'GRANT OPTION', 'PROXY',
				), true ) ) && in_array( $privilege, self::DDL, true ) ) {
					return array( 'FAIL', 'Runtime account can modify enforcement objects or delegate authority.' );
				}
				if ( $this->applies( $grant ) && 'commitcap_v01_state' === $grant['object'] &&
					in_array( $privilege, array( 'INSERT', 'UPDATE', 'DELETE', 'REFERENCES' ), true ) ) {
					return array( 'FAIL', 'Runtime account can modify the helper table.' );
				}
				if ( $this->applies( $grant ) && '*' === $grant['object'] &&
					in_array( $privilege, array( 'INSERT', 'UPDATE', 'DELETE', 'REFERENCES' ), true ) ) {
					return array( 'FAIL', 'Schema-wide write privilege includes the CommitCap helper table.' );
				}
			}
		}
		if ( ! $this->complete || $ambiguous_scope ) {
			return array( 'UNKNOWN', 'Unexpanded role or unrecognized grant syntax; effective privilege boundary cannot be established.' );
		}
		if ( count( $reviewed ) !== count( self::ROUTINES ) ) {
			return array( 'FAIL', 'Runtime account lacks explicit EXECUTE on all four reviewed procedures.' );
		}
		return array( 'PASS', 'Direct grants restrict EXECUTE to four reviewed procedures and exclude helper writes and enforcement DDL.' );
	}

	/**
	 * Runtime write scopes that can fire triggers outside the verified target(s).
	 *
	 * The Guard monitor sees statement text only. A trigger fired by an ordinary
	 * INSERT/UPDATE/DELETE on a writable object is opaque server-side execution,
	 * so every non-target write scope must have its trigger graph inspected
	 * before the environment can PASS. Global/schema-wide and helper writes are
	 * already FAILed by runtime() and are not repeated here.
	 *
	 * @param string|array<int, string>|null $target Known target or sibling policy table(s).
	 * @return array{0: bool, 1: array<int, array{database: string, object: string}>}
	 *         The first element is true when incomplete or pattern grants leave
	 *         the effective write surface unprovable.
	 */
	public function trigger_write_scopes( $target = null ): array {
		if ( ! $this->complete ) {
			return array( true, array() );
		}
		$targets = is_array( $target ) ? $target : ( null !== $target && '' !== $target ? array( $target ) : array() );
		$ambiguous = false;
		$scopes    = array();
		foreach ( $this->grants as $grant ) {
			if ( '' !== $grant['kind'] || ! array_intersect( array( 'INSERT', 'UPDATE', 'DELETE' ), $grant['privileges'] ) ) {
				continue;
			}
			$database = $grant['database'];
			$object   = $grant['object'];
			if ( '*' === $database || ( $this->applies( $grant ) && '*' === $object ) ) {
				continue; // Global/schema-wide writes already FAIL runtime().
			}
			if ( $this->applies( $grant ) && 'commitcap_v01_state' === $object ) {
				continue; // Helper writes already FAIL runtime().
			}
			if ( $this->applies( $grant ) && in_array( $object, $targets, true ) ) {
				continue; // The target policy is verified by target_table/target_access.
			}
			if ( '*' !== $database && $this->schema !== $database && strpbrk( $database, '%_\\' ) !== false ) {
				$ambiguous = true; // Database grant patterns may match other schemas.
				continue;
			}
			$scopes[ $database . "\0" . $object ] = array( 'database' => $database, 'object' => $object );
		}
		return array( $ambiguous, array_values( $scopes ) );
	}

	public function target_access( string $table ): array {
		if ( ! $this->complete ) {
			return array( 'UNKNOWN', 'Cannot establish target-table rights from incomplete grants.' );
		}
		$found = array( 'SELECT' => false, 'UPDATE' => false );
		foreach ( $this->grants as $grant ) {
			if ( ! $this->applies( $grant ) || ( '*' !== $grant['object'] && $table !== $grant['object'] ) ) {
				continue;
			}
			foreach ( $found as $privilege => $present ) {
				if ( in_array( $privilege, $grant['privileges'], true ) || in_array( 'ALL PRIVILEGES', $grant['privileges'], true ) ) {
					$found[ $privilege ] = true;
				}
			}
		}
		return in_array( false, $found, true )
			? array( 'FAIL', 'Runtime writer needs SELECT and UPDATE on this target table.' )
			: array( 'PASS', 'Runtime writer has SELECT and UPDATE on this target table.' );
	}

	public function installer( ?string $table ): array {
		if ( ! $this->complete ) {
			return array( 'UNKNOWN', 'Installer grant listing includes unexpanded roles or unrecognized syntax.' );
		}
		$needed = array( 'CREATE' => false, 'CREATE ROUTINE' => false, 'TRIGGER' => false );
		foreach ( $this->grants as $grant ) {
			if ( ! $this->applies( $grant ) ) {
				continue;
			}
			foreach ( $needed as $privilege => $present ) {
				if ( ! in_array( $privilege, $grant['privileges'], true ) &&
					! in_array( 'ALL PRIVILEGES', $grant['privileges'], true ) ) {
					continue;
				}
				if ( '*' === $grant['object'] || ( 'TRIGGER' === $privilege && null !== $table && $table === $grant['object'] ) ) {
					$needed[ $privilege ] = true;
				}
			}
		}
		return in_array( false, $needed, true )
			? array( 'FAIL', 'Installer lacks schema CREATE, schema CREATE ROUTINE or applicable TRIGGER privilege.' )
			: array( 'PASS', 'Separate trusted installer has CREATE, CREATE ROUTINE and applicable TRIGGER privileges.' );
	}
}
