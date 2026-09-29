<?php
namespace WriteLeash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lower-level UPDATE row-event engine. Trusted DDL is separate from the
 * restricted writer; transaction ownership and immediate rollback belong to #56.
 */
final class Update_Engine {
	public const STATE = 'commitcap_v01_state';
	// #97 owns the low-level database-object rename. Until then the canonical
	// helper table, its TABLE_COMMENT and the routine/trigger family keep the
	// pre-#96 names exactly, so the attestation shape stays unchanged.
	public const COMMENT = 'CommitCap V0.1 cooperative UPDATE state';
	private const MAX_BUDGET = '2147483647';
	private const ROUTINE_NAMES = array(
		'commitcap_v01_open', 'commitcap_v01_close', 'commitcap_v01_count',
		'commitcap_v01_policy', 'commitcap_v01_attest',
	);
	private $db;

	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	public static function table( $name ): string {
		if ( ! is_string( $name ) || strlen( $name ) > 64 ||
			! preg_match( '/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name ) ) {
			throw new \InvalidArgumentException( 'Expected one simple unqualified ASCII table identifier.' );
		}
		return $name;
	}

	public static function budget( $budget ): int {
		if ( ! is_string( $budget ) && ! is_int( $budget ) ) {
			throw new \InvalidArgumentException( 'Budget must be a canonical nonnegative integer.' );
		}
		$text = (string) $budget;
		if ( ! preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $text ) ||
			strlen( $text ) > strlen( self::MAX_BUDGET ) ||
			( strlen( $text ) === strlen( self::MAX_BUDGET ) && strcmp( $text, self::MAX_BUDGET ) > 0 ) ) {
			throw new \InvalidArgumentException( 'Budget outside canonical 0..2147483647 range.' );
		}
		return (int) $text;
	}

	/** Target identification only. #57 owns tested/untested compatibility status. */
	public static function target_family( $version ): string {
		if ( ! is_string( $version ) ) {
			throw new \RuntimeException( 'Database version is unavailable.' );
		}
		if ( preg_match( '/\A(?:5\.5\.5-)?(\d+)\.(\d+)\.\d+-MariaDB(?:-[A-Za-z0-9.]+)?\z/i', $version, $parts ) ) {
			if ( (int) $parts[1] > 10 || ( 10 === (int) $parts[1] && (int) $parts[2] >= 11 ) ) {
				return 'MariaDB';
			}
		} elseif ( preg_match( '/\A(\d+)\.(\d+)\.\d+\z/', $version, $parts ) ) {
			if ( (int) $parts[1] > 8 || ( 8 === (int) $parts[1] && (int) $parts[2] >= 0 ) ) {
				return 'MySQL';
			}
		}
		throw new \RuntimeException( 'Unsupported or unrecognized database family/version.' );
	}

	private function require_target_server(): void {
		self::target_family( $this->db->get_var( 'SELECT VERSION()' ) );
	}

	public static function policy_id( string $table ): string {
		return hash( 'sha256', $table );
	}

	public static function trigger_name( string $table ): string {
		return 'commitcap_v01_' . substr( self::policy_id( $table ), 0, 16 );
	}

	private function execute( string $sql ): void {
		if ( false === $this->db->query( $sql ) ) {
			throw new \RuntimeException( 'WriteLeash database operation failed: ' . $this->db->last_error );
		}
	}

	private function rows( string $sql ): array {
		$rows = $this->db->get_results( $sql );
		if ( null === $rows ) {
			throw new \RuntimeException( 'WriteLeash metadata query failed: ' . $this->db->last_error );
		}
		return $rows;
	}

	/** Only fold formatting whitespace OUTSIDE quoted SQL tokens. Case is exact. */
	private static function normalize_sql( string $sql ): ?string {
		$result = '';
		$quote = null;
		$space = false;
		$length = strlen( $sql );
		for ( $i = 0; $i < $length; ++$i ) {
			$char = $sql[ $i ];
			if ( null !== $quote ) {
				$result .= $char;
				if ( '\\' === $char && $i + 1 < $length ) {
					$result .= $sql[ ++$i ];
				} elseif ( $char === $quote ) {
					if ( $i + 1 < $length && $sql[ $i + 1 ] === $quote ) {
						$result .= $sql[ ++$i ];
					} else {
						$quote = null;
					}
				}
				continue;
			}
			if ( ctype_space( $char ) ) {
				$space = true;
				continue;
			}
			if ( $space && '' !== $result ) {
				$result .= ' ';
			}
			$space = false;
			$result .= $char;
			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$quote = $char;
			}
		}
		return null === $quote ? $result : null;
	}

	private static function same_sql( string $left, string $right ): bool {
		$expected = self::normalize_sql( $left );
		return null !== $expected && $expected === self::normalize_sql( $right );
	}

	private static function signature( string $name ): array {
		$policy = array( 'p_policy', 'IN', 'char(64)', 'ascii', 'ascii_bin' );
		switch ( $name ) {
			case 'commitcap_v01_open':
			case 'commitcap_v01_close':
				return array( $policy );
			case 'commitcap_v01_count':
				return array( $policy, array( 'p_count', 'OUT', 'bigint unsigned', null, null ) );
			case 'commitcap_v01_policy':
				return array(
					array( 'p_table', 'IN', 'varchar(64)', 'ascii', 'ascii_bin' ),
					array( 'p_trigger', 'IN', 'varchar(64)', 'ascii', 'ascii_bin' ),
				);
			case 'commitcap_v01_attest':
				return array();
		}
		throw new \InvalidArgumentException( 'Unknown routine signature.' );
	}

	public static function routine_params( string $name ): string {
		$policy = 'IN p_policy CHAR(64) CHARACTER SET ascii COLLATE ascii_bin';
		switch ( $name ) {
			case 'commitcap_v01_open':
			case 'commitcap_v01_close':
				return '(' . $policy . ')';
			case 'commitcap_v01_count':
				return '(' . $policy . ', OUT p_count BIGINT UNSIGNED)';
			case 'commitcap_v01_policy':
				return '(IN p_table VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin, IN p_trigger VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin)';
			case 'commitcap_v01_attest':
				return '()';
		}
		throw new \InvalidArgumentException( 'Unknown routine declaration.' );
	}

	/** Canonical object names in install/verify/remove order. */
	public static function routine_names(): array {
		return self::ROUTINE_NAMES;
	}

	public static function routines(): array {
		return array(
			'commitcap_v01_open' => 'BEGIN INSERT INTO commitcap_v01_state (connection_id, policy_id, consumed) VALUES (CONNECTION_ID(), p_policy, 0); END',
			'commitcap_v01_close' => "BEGIN IF COALESCE(@commitcap_v01_denied, 1) != 0 THEN SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 1644, MESSAGE_TEXT = 'CC54_DENIED_PRECOMMIT'; END IF; DELETE FROM commitcap_v01_state WHERE connection_id = CONNECTION_ID() AND policy_id = p_policy; IF ROW_COUNT() != 1 THEN SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 1644, MESSAGE_TEXT = 'CC54_STATE_MISSING'; END IF; END",
			'commitcap_v01_count' => 'BEGIN SELECT consumed INTO p_count FROM commitcap_v01_state WHERE connection_id = CONNECTION_ID() AND policy_id = p_policy; END',
			'commitcap_v01_policy' => "BEGIN IF p_trigger != '' THEN SELECT t.TRIGGER_NAME, t.ACTION_STATEMENT, t.ACTION_TIMING, t.EVENT_MANIPULATION, t.DEFINER = CURRENT_USER() AS TRUSTED_DEFINER, (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE (TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = p_table) OR (p_table LIKE '%.%' AND TRIGGER_SCHEMA = SUBSTRING_INDEX(p_table, '.', 1) AND EVENT_OBJECT_TABLE = SUBSTRING_INDEX(p_table, '.', -1))) AS TRIGGER_COUNT, (SELECT ENGINE FROM information_schema.TABLES WHERE (TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND TABLE_TYPE = 'BASE TABLE') OR (p_table LIKE '%.%' AND TABLE_SCHEMA = SUBSTRING_INDEX(p_table, '.', 1) AND TABLE_NAME = SUBSTRING_INDEX(p_table, '.', -1) AND TABLE_TYPE = 'BASE TABLE')) AS TABLE_ENGINE, (SELECT COUNT(*) FROM information_schema.PARTITIONS WHERE (TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND PARTITION_NAME IS NOT NULL) OR (p_table LIKE '%.%' AND TABLE_SCHEMA = SUBSTRING_INDEX(p_table, '.', 1) AND TABLE_NAME = SUBSTRING_INDEX(p_table, '.', -1) AND PARTITION_NAME IS NOT NULL)) AS PARTITION_COUNT, (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME IS NOT NULL AND (((TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table) OR (REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = p_table)) OR (p_table LIKE '%.%' AND ((TABLE_SCHEMA = SUBSTRING_INDEX(p_table, '.', 1) AND TABLE_NAME = SUBSTRING_INDEX(p_table, '.', -1)) OR (REFERENCED_TABLE_SCHEMA = SUBSTRING_INDEX(p_table, '.', 1) AND REFERENCED_TABLE_NAME = SUBSTRING_INDEX(p_table, '.', -1)))))) AS FOREIGN_KEY_COUNT FROM information_schema.TRIGGERS t WHERE ((t.TRIGGER_SCHEMA = DATABASE() AND t.EVENT_OBJECT_TABLE = p_table) OR (p_table LIKE '%.%' AND t.TRIGGER_SCHEMA = SUBSTRING_INDEX(p_table, '.', 1) AND t.EVENT_OBJECT_TABLE = SUBSTRING_INDEX(p_table, '.', -1))) AND t.TRIGGER_NAME = p_trigger; ELSEIF p_table != '' THEN SELECT '' AS TRIGGER_NAME, '' AS ACTION_STATEMENT, '' AS ACTION_TIMING, '' AS EVENT_MANIPULATION, 1 AS TRUSTED_DEFINER, (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE (TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = p_table) OR (p_table LIKE '%.%' AND TRIGGER_SCHEMA = SUBSTRING_INDEX(p_table, '.', 1) AND EVENT_OBJECT_TABLE = SUBSTRING_INDEX(p_table, '.', -1))) AS TRIGGER_COUNT, (SELECT ENGINE FROM information_schema.TABLES WHERE (TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND TABLE_TYPE = 'BASE TABLE') OR (p_table LIKE '%.%' AND TABLE_SCHEMA = SUBSTRING_INDEX(p_table, '.', 1) AND TABLE_NAME = SUBSTRING_INDEX(p_table, '.', -1) AND TABLE_TYPE = 'BASE TABLE')) AS TABLE_ENGINE, 0 AS PARTITION_COUNT, 0 AS FOREIGN_KEY_COUNT; ELSE SELECT (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME IN ('commitcap_v01_open','commitcap_v01_close','commitcap_v01_count','commitcap_v01_policy','commitcap_v01_attest') AND DEFINER = CURRENT_USER() AND SECURITY_TYPE = 'DEFINER') AS ROUTINE_COUNT, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commitcap_v01_state' AND TABLE_TYPE = 'BASE TABLE' AND ENGINE = 'InnoDB') AS HELPER_COUNT, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commitcap_v01_state') AS COLUMN_COUNT, (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'commitcap_v01_state') AS HELPER_TRIGGER_COUNT, (SELECT COUNT(*) FROM information_schema.PARAMETERS WHERE SPECIFIC_SCHEMA = DATABASE() AND SPECIFIC_NAME IN ('commitcap_v01_open','commitcap_v01_close','commitcap_v01_count','commitcap_v01_policy','commitcap_v01_attest')) AS PARAMETER_COUNT, (SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'commitcap_v01_open') AS OPEN_DEFINITION, (SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'commitcap_v01_close') AS CLOSE_DEFINITION, (SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'commitcap_v01_count') AS COUNT_DEFINITION, (SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'commitcap_v01_policy') AS POLICY_DEFINITION, (SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'commitcap_v01_attest') AS ATTEST_DEFINITION; END IF; END",
			'commitcap_v01_attest' => "BEGIN SELECT r.ROUTINE_NAME, r.ROUTINE_DEFINITION, r.SECURITY_TYPE, r.DEFINER, r.CREATED, r.LAST_ALTERED FROM information_schema.ROUTINES r WHERE r.ROUTINE_SCHEMA = DATABASE() AND r.ROUTINE_NAME IN ('commitcap_v01_open','commitcap_v01_close','commitcap_v01_count','commitcap_v01_policy','commitcap_v01_attest') ORDER BY r.ROUTINE_NAME; END",
		);
	}

	/** Explicit trusted install; never invoked by plugin activation. */
	public function install_infrastructure(): void {
		$this->require_target_server();
		$existing = $this->rows( $this->db->prepare(
			'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', self::STATE
		) );
		if ( ! $existing ) {
			$this->execute( 'CREATE TABLE `commitcap_v01_state` (connection_id BIGINT UNSIGNED NOT NULL, policy_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, consumed BIGINT UNSIGNED NOT NULL, PRIMARY KEY (connection_id, policy_id)) ENGINE=InnoDB COMMENT=' . $this->db->prepare( '%s', self::COMMENT ) );
		}
		$this->verify_infrastructure_table();
		foreach ( self::routines() as $name => $body ) {
			$found = $this->routine( $name );
			if ( null === $found ) {
				$params = self::routine_params( $name );
				$this->execute( "CREATE PROCEDURE `$name` $params SQL SECURITY DEFINER $body" );
			}
			$this->verify_routine( $name, $body );
		}
	}

	private function verify_infrastructure_table(): void {
		$tables = $this->rows( $this->db->prepare(
			'SELECT TABLE_TYPE, ENGINE, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', self::STATE
		) );
		if ( 1 !== count( $tables ) || 'BASE TABLE' !== $tables[0]->TABLE_TYPE ||
			'InnoDB' !== $tables[0]->ENGINE || self::COMMENT !== $tables[0]->TABLE_COMMENT ) {
			throw new \RuntimeException( 'Unknown/conflicting managed helper object.' );
		}
		$columns = $this->rows( $this->db->prepare(
			'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION', self::STATE
		) );
		if ( 3 !== count( $columns ) ||
			array( 'connection_id', 'policy_id', 'consumed' ) !== array_column( $columns, 'COLUMN_NAME' ) ||
			! preg_match( '/\Abigint(?:\(20\))? unsigned\z/i', $columns[0]->COLUMN_TYPE ) ||
			'char(64)' !== strtolower( $columns[1]->COLUMN_TYPE ) ||
			! preg_match( '/\Abigint(?:\(20\))? unsigned\z/i', $columns[2]->COLUMN_TYPE ) ||
			'NO' !== $columns[0]->IS_NULLABLE || 'NO' !== $columns[1]->IS_NULLABLE || 'NO' !== $columns[2]->IS_NULLABLE ) {
			throw new \RuntimeException( 'Unknown managed helper column shape.' );
		}
		$indexes = $this->rows( $this->db->prepare(
			'SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY INDEX_NAME, SEQ_IN_INDEX', self::STATE
		) );
		if ( 2 !== count( $indexes ) || 'PRIMARY' !== $indexes[0]->INDEX_NAME ||
			'connection_id' !== $indexes[0]->COLUMN_NAME || 'PRIMARY' !== $indexes[1]->INDEX_NAME ||
			'policy_id' !== $indexes[1]->COLUMN_NAME || $this->triggers( self::STATE ) ) {
			throw new \RuntimeException( 'Unknown managed helper key or trigger.' );
		}
	}

	private function routine( string $name ) {
		$rows = $this->rows( $this->db->prepare(
			'SELECT ROUTINE_DEFINITION, SECURITY_TYPE, DEFINER FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = %s', $name
		) );
		return $rows ? $rows[0] : null;
	}

	private function verify_routine( string $name, string $body ): void {
		$entry = $this->routine( $name );
		if ( null === $entry || ! is_string( $entry->ROUTINE_DEFINITION ) ||
			! self::same_sql( $entry->ROUTINE_DEFINITION, $body ) || 'DEFINER' !== $entry->SECURITY_TYPE ||
			$entry->DEFINER !== $this->db->get_var( 'SELECT CURRENT_USER()' ) ) {
			throw new \RuntimeException( 'Unknown/conflicting managed routine: ' . $name );
		}
		$params = $this->rows( $this->db->prepare(
			'SELECT ORDINAL_POSITION, PARAMETER_NAME, PARAMETER_MODE, DTD_IDENTIFIER, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.PARAMETERS WHERE SPECIFIC_SCHEMA = DATABASE() AND SPECIFIC_NAME = %s ORDER BY ORDINAL_POSITION', $name
		) );
		$expected = self::signature( $name );
		if ( count( $params ) !== count( $expected ) ) {
			throw new \RuntimeException( 'Unknown managed routine signature: ' . $name );
		}
		foreach ( $expected as $index => $spec ) {
			$param = $params[ $index ];
			$dtd = strtolower( $param->DTD_IDENTIFIER );
			if ( $index + 1 !== (int) $param->ORDINAL_POSITION ||
				$spec[0] !== $param->PARAMETER_NAME || $spec[1] !== $param->PARAMETER_MODE ||
				( 'bigint unsigned' === $spec[2]
					? ! preg_match( '/\Abigint(?:\(20\))? unsigned\z/', $dtd )
					: $spec[2] !== $dtd ) ||
				$spec[3] !== $param->CHARACTER_SET_NAME || $spec[4] !== $param->COLLATION_NAME ) {
				throw new \RuntimeException( 'Unknown managed routine signature: ' . $name );
			}
		}
	}

	private function verify_infrastructure(): void {
		$this->verify_infrastructure_table();
		foreach ( self::routines() as $name => $body ) {
			$this->verify_routine( $name, $body );
		}
	}

	/** Trusted, read-only structural verification shared with the doctor. */
	public function verify_infrastructure_objects(): void {
		$this->verify_infrastructure();
	}

	public function inspect_table( $table ): array {
		$name = self::table( $table );
		$this->require_target_server();
		$entries = $this->rows( $this->db->prepare(
			'SELECT TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $name
		) );
		if ( 1 !== count( $entries ) || 'BASE TABLE' !== $entries[0]->TABLE_TYPE || 'InnoDB' !== $entries[0]->ENGINE ) {
			throw new \RuntimeException( 'Table missing or not an ordinary InnoDB table.' );
		}
		$partitions = $this->rows( $this->db->prepare(
			'SELECT PARTITION_NAME FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND PARTITION_NAME IS NOT NULL', $name
		) );
		if ( $partitions ) {
			throw new \RuntimeException( 'Partitioned tables are unsupported.' );
		}
		$foreign_keys = $this->rows( $this->db->prepare(
			'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME IS NOT NULL AND ((TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s) OR (REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = %s))', $name, $name
		) );
		if ( $foreign_keys ) {
			throw new \RuntimeException( 'Foreign-key relationships are unsupported.' );
		}
		return array( 'table' => $name, 'triggers' => $this->triggers( $name ) );
	}

	private function triggers( string $table ): array {
		return $this->rows( $this->db->prepare(
			'SELECT TRIGGER_NAME, ACTION_STATEMENT, ACTION_TIMING, EVENT_MANIPULATION, DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = %s', $table
		) );
	}

	/** Canonical runtime username grammar shared by provisioning and verification. */
	public static function runtime_user( $name ): string {
		if ( ! is_string( $name ) || strlen( $name ) > 32 ||
			! preg_match( '/\A[A-Za-z0-9_.\-]+\z/D', $name ) ) {
			throw new \InvalidArgumentException( 'Invalid database username.' );
		}
		return $name;
	}

	/**
	 * The authenticated username of this connection, with no host part.
	 *
	 * USER() is session state set by the server at authentication time; a
	 * trigger's CURRENT_USER() instead reports the trigger DEFINER (proved on
	 * both pinned engines), so USER() is the reviewed invocation identity.
	 */
	public function session_user_name(): string {
		$name = $this->db->get_var( "SELECT SUBSTRING_INDEX(USER(), '@', 1)" );
		if ( ! is_string( $name ) || '' === $name || '' !== (string) $this->db->last_error ) {
			throw new \RuntimeException( 'WriteLeash could not read the authenticated runtime username.' );
		}
		return self::runtime_user( $name );
	}

	private static function trigger_prefix( string $policy_id, string $runtime_user ): string {
		return "BEGIN IF LOWER(SUBSTRING_INDEX(USER(), '@', 1)) = LOWER('" . $runtime_user . "') THEN UPDATE commitcap_v01_state SET consumed = consumed + 1 WHERE connection_id = CONNECTION_ID() AND policy_id = '$policy_id' AND consumed < ";
	}

	private static function trigger_suffix(): string {
		return "; IF ROW_COUNT() != 1 THEN SET @commitcap_v01_denied = 1; SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 1644, MESSAGE_TEXT = 'CC54_DENIED'; END IF; END IF; END";
	}

	/**
	 * Canonical BEFORE UPDATE trigger for one table and one restricted runtime
	 * account.
	 *
	 * The identity condition scopes physical enforcement to the certified
	 * runtime username: normal WordPress/plugin writers keep their ordinary
	 * table behavior, while the restricted runtime is enforced (and denied when
	 * no Guard accounting row exists). LOWER() keeps any case variant of the
	 * runtime username inside enforcement (fail closed). The whole condition is
	 * part of the verified canonical body; Doctor rejects any variant.
	 */
	public static function trigger_body( string $table, int $budget, string $runtime_user ): string {
		$id      = self::policy_id( $table );
		$runtime = self::runtime_user( $runtime_user );
		return self::trigger_prefix( $id, $runtime ) . $budget . self::trigger_suffix();
	}

	public function install_policy( $table, $budget, $runtime_user ): void {
		$name    = self::table( $table );
		$limit   = self::budget( $budget );
		$runtime = self::runtime_user( $runtime_user );
		$this->verify_infrastructure();
		$info = $this->inspect_table( $name );
		if ( $info['triggers'] ) {
			throw new \RuntimeException( 'Existing trigger/policy conflict; no overwrite.' );
		}
		$trigger = self::trigger_name( $name );
		$this->execute( "CREATE TRIGGER `$trigger` BEFORE UPDATE ON `$name` FOR EACH ROW " . self::trigger_body( $name, $limit, $runtime ) );
		$this->verify_policy( $name, $limit, $runtime );
	}

	public function verify_policy( $table, $budget, $runtime_user ): void {
		$name    = self::table( $table );
		$limit   = self::budget( $budget );
		$runtime = self::runtime_user( $runtime_user );
		$this->verify_infrastructure();
		$info = $this->inspect_table( $name );
		$triggers = $info['triggers'];
		if ( 1 !== count( $triggers ) || self::trigger_name( $name ) !== $triggers[0]->TRIGGER_NAME ||
			'BEFORE' !== $triggers[0]->ACTION_TIMING || 'UPDATE' !== $triggers[0]->EVENT_MANIPULATION ||
			! self::same_sql( self::trigger_body( $name, $limit, $runtime ), $triggers[0]->ACTION_STATEMENT ) ||
			$triggers[0]->DEFINER !== $this->db->get_var( 'SELECT CURRENT_USER()' ) ) {
			throw new \RuntimeException( 'WriteLeash policy absent, conflicting or malformed.' );
		}
	}

	/** Restricted writer: extract and verify physical ceiling from canonical trigger. */
	public function runtime_ceiling( string $table ): int {
		$name = self::table( $table );
		$this->require_target_server();
		$trigger = self::trigger_name( $name );
		$found = $this->rows( $this->db->prepare(
			'CALL commitcap_v01_policy(%s, %s)', $name, $trigger
		) );
		if ( 1 !== count( $found ) || $trigger !== $found[0]->TRIGGER_NAME ||
			'BEFORE' !== $found[0]->ACTION_TIMING || 'UPDATE' !== $found[0]->EVENT_MANIPULATION ||
			'InnoDB' !== $found[0]->TABLE_ENGINE || 1 !== (int) $found[0]->TRIGGER_COUNT ||
			0 !== (int) $found[0]->PARTITION_COUNT || 0 !== (int) $found[0]->FOREIGN_KEY_COUNT ||
			1 !== (int) $found[0]->TRUSTED_DEFINER ) {
			throw new \RuntimeException( 'WriteLeash runtime policy absent, conflicting or altered.' );
		}
		$statement = $found[0]->ACTION_STATEMENT;
		if ( ! is_string( $statement ) ) {
			throw new \RuntimeException( 'WriteLeash runtime policy statement is unavailable.' );
		}
		$normalized = self::normalize_sql( $statement );
		if ( null === $normalized ) {
			throw new \RuntimeException( 'WriteLeash runtime policy statement is malformed.' );
		}
		$runtime   = $this->session_user_name();
		$policy_id = self::policy_id( $name );
		$prefix    = self::trigger_prefix( $policy_id, $runtime );
		$suffix    = self::trigger_suffix();
		if ( 0 !== strpos( $normalized, $prefix ) || substr( $normalized, -strlen( $suffix ) ) !== $suffix ) {
			throw new \RuntimeException( 'WriteLeash runtime policy structure does not match canonical trigger template.' );
		}
		$ceiling_str = substr( $normalized, strlen( $prefix ), strlen( $normalized ) - strlen( $prefix ) - strlen( $suffix ) );
		try {
			$ceiling = self::budget( $ceiling_str );
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'WriteLeash runtime policy contains invalid physical ceiling: ' . $ceiling_str );
		}
		if ( ! self::same_sql( self::trigger_body( $name, $ceiling, $runtime ), $statement ) ) {
			throw new \RuntimeException( 'WriteLeash runtime policy statement mismatch.' );
		}
		return $ceiling;
	}

	/** Verify that installed physical ceiling matches exact expected budget. */
	public function verify_runtime_policy( $table, $budget ): void {
		$name = self::table( $table );
		$limit = self::budget( $budget );
		$ceiling = $this->runtime_ceiling( $name );
		if ( $ceiling !== $limit ) {
			throw new \RuntimeException( 'WriteLeash runtime policy absent or altered.' );
		}
	}

	/** Verify that table has verified policy and logical budget L satisfies 0 <= L <= P. */
	public function verify_runtime_budget( $table, $budget ): int {
		$name = self::table( $table );
		$limit = self::budget( $budget );
		$ceiling = $this->runtime_ceiling( $name );
		if ( $limit > $ceiling ) {
			throw new \RuntimeException(
				'WriteLeash logical budget ' . $limit . ' exceeds installed physical ceiling ' . $ceiling . '.'
			);
		}
		return $ceiling;
	}

	/**
	 * Restricted writer: full runtime evidence verification with no installer secret.
	 *
	 * Trust model (see DOCTOR.md, "Runtime evidence trust root"):
	 *  1. Cross-attested DEFINER routines: commitcap_v01_policy and
	 *     commitcap_v01_attest each report the live body of all five routines.
	 *     A single replaced body is reported by the other canonical routine.
	 *  2. Unmediated metadata the restricted account reads itself:
	 *     information_schema.ROUTINES (DEFINER/SECURITY_TYPE/CREATED) and SHOW GRANTS.
	 *  3. Behavioral probes that execute the real objects through unmediated
	 *     helper-state reads.
	 * The assumptions and the installer-equivalent residual are documented.
	 */
	public function runtime_inspect_infrastructure(): void {
		$this->runtime_attestation();
		$this->runtime_helper_shape();
		$this->runtime_probe_routines();
	}

	/** Batch tolerance for routine CREATED times created by one install sequence. */
	private const CREATED_TOLERANCE_SECONDS = 10;

	/**
	 * Cross-attested routine definitions plus unmediated metadata cross-checks.
	 *
	 * @return array{definer: string, created_min: int, created_max: int}
	 */
	public function runtime_attestation(): array {
		$this->require_target_server();
		$names     = self::ROUTINE_NAMES;
		$canonical = self::routines();

		$attested = $this->rows( 'CALL commitcap_v01_attest()' );
		if ( count( $attested ) !== count( $names ) ) {
			throw new \RuntimeException( 'WriteLeash runtime attestation did not return every reviewed routine.' );
		}
		$by_name = array();
		foreach ( $attested as $row ) {
			if ( ! isset( $row->ROUTINE_NAME, $row->ROUTINE_DEFINITION, $row->SECURITY_TYPE, $row->DEFINER ) ||
				! is_string( $row->ROUTINE_NAME ) || ! is_string( $row->ROUTINE_DEFINITION ) ||
				! in_array( $row->ROUTINE_NAME, $names, true ) || isset( $by_name[ $row->ROUTINE_NAME ] ) ) {
				throw new \RuntimeException( 'WriteLeash runtime attestation returned an unknown, duplicate or malformed routine row.' );
			}
			$by_name[ $row->ROUTINE_NAME ] = $row;
		}

		$infrastructure = $this->rows( "CALL commitcap_v01_policy('', '')" );
		if ( 1 !== count( $infrastructure ) ||
			5 !== (int) $infrastructure[0]->ROUTINE_COUNT ||
			1 !== (int) $infrastructure[0]->HELPER_COUNT ||
			3 !== (int) $infrastructure[0]->COLUMN_COUNT ||
			0 !== (int) $infrastructure[0]->HELPER_TRIGGER_COUNT ||
			6 !== (int) $infrastructure[0]->PARAMETER_COUNT ) {
			throw new \RuntimeException( 'WriteLeash runtime infrastructure invalid, conflicting or altered.' );
		}

		$definers = array();
		foreach ( $names as $name ) {
			$attested_body = $by_name[ $name ]->ROUTINE_DEFINITION;
			$reported = $infrastructure[0]->{ strtoupper( str_replace( 'commitcap_v01_', '', $name ) ) . '_DEFINITION' } ?? null;
			if ( ! is_string( $reported ) || ! self::same_sql( $attested_body, $reported ) ) {
				throw new \RuntimeException( 'WriteLeash runtime evidence cross-attestation mismatch for ' . $name . '.' );
			}
			if ( ! self::same_sql( $attested_body, $canonical[ $name ] ) ) {
				throw new \RuntimeException( 'WriteLeash runtime routine body does not match the reviewed canonical body: ' . $name . '.' );
			}
			if ( 'DEFINER' !== $by_name[ $name ]->SECURITY_TYPE ) {
				throw new \RuntimeException( 'WriteLeash runtime routine is not SQL SECURITY DEFINER: ' . $name . '.' );
			}
			$definers[ (string) $by_name[ $name ]->DEFINER ] = true;
		}
		if ( 1 !== count( $definers ) ) {
			throw new \RuntimeException( 'WriteLeash runtime routines do not share one trusted DEFINER.' );
		}
		$definer = (string) array_key_first( $definers );

		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$metadata     = $this->rows( $this->db->prepare(
			'SELECT ROUTINE_NAME, SECURITY_TYPE, DEFINER, CREATED, LAST_ALTERED FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME IN (' . $placeholders . ')',
			$names
		) );
		if ( count( $metadata ) !== count( $names ) ) {
			throw new \RuntimeException( 'WriteLeash runtime routine metadata is incomplete.' );
		}
		$created = array();
		foreach ( $metadata as $row ) {
			if ( 'DEFINER' !== $row->SECURITY_TYPE || $definer !== (string) $row->DEFINER ||
				! in_array( $row->ROUTINE_NAME, $names, true ) ) {
				throw new \RuntimeException( 'WriteLeash runtime routine metadata conflicts with trusted evidence: ' . $row->ROUTINE_NAME . '.' );
			}
			$timestamp = strtotime( (string) $row->CREATED );
			if ( false === $timestamp ) {
				throw new \RuntimeException( 'WriteLeash runtime routine creation time is unavailable: ' . $row->ROUTINE_NAME . '.' );
			}
			$created[] = $timestamp;
		}
		$min = min( $created );
		$max = max( $created );
		if ( $max - $min > self::CREATED_TOLERANCE_SECONDS ) {
			throw new \RuntimeException( 'WriteLeash runtime routine creation times are outside one install batch; an object was replaced after installation.' );
		}
		return array( 'definer' => $definer, 'created_min' => $min, 'created_max' => $max );
	}

	/** Restricted writer: canonical helper table shape, read without any evidence routine. */
	public function runtime_helper_shape(): void {
		$this->require_target_server();
		$tables = $this->rows( $this->db->prepare(
			'SELECT TABLE_TYPE, ENGINE, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', self::STATE
		) );
		if ( 1 !== count( $tables ) || 'BASE TABLE' !== $tables[0]->TABLE_TYPE ||
			'InnoDB' !== $tables[0]->ENGINE || self::COMMENT !== $tables[0]->TABLE_COMMENT ) {
			throw new \RuntimeException( 'Managed runtime helper object is missing or conflicting.' );
		}
		$columns = $this->rows( $this->db->prepare(
			'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION', self::STATE
		) );
		if ( 3 !== count( $columns ) ||
			array( 'connection_id', 'policy_id', 'consumed' ) !== array_column( $columns, 'COLUMN_NAME' ) ||
			! preg_match( '/\Abigint(?:\(20\))? unsigned\z/i', $columns[0]->COLUMN_TYPE ) ||
			'char(64)' !== strtolower( $columns[1]->COLUMN_TYPE ) ||
			! preg_match( '/\Abigint(?:\(20\))? unsigned\z/i', $columns[2]->COLUMN_TYPE ) ||
			'NO' !== $columns[0]->IS_NULLABLE || 'NO' !== $columns[1]->IS_NULLABLE || 'NO' !== $columns[2]->IS_NULLABLE ) {
			throw new \RuntimeException( 'Managed runtime helper column shape is unknown.' );
		}
		$indexes = $this->rows( $this->db->prepare(
			'SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY INDEX_NAME, SEQ_IN_INDEX', self::STATE
		) );
		if ( 2 !== count( $indexes ) || 'PRIMARY' !== $indexes[0]->INDEX_NAME ||
			'connection_id' !== $indexes[0]->COLUMN_NAME || 'PRIMARY' !== $indexes[1]->INDEX_NAME ||
			'policy_id' !== $indexes[1]->COLUMN_NAME || $this->triggers( self::STATE ) ) {
			throw new \RuntimeException( 'Managed runtime helper key or trigger shape is unknown.' );
		}
	}

	/**
	 * Restricted writer: behavioral probe of open/count/close through direct helper reads.
	 * Any body tamper that changes the observable lifecycle is detected here even if
	 * every evidence routine lied, because the helper state is read without mediation.
	 */
	public function runtime_probe_routines(): void {
		$this->require_target_server();
		$nonce = bin2hex( random_bytes( 32 ) );
		$out   = '@cc_runtime_probe_count';
		try {
			$this->execute( $this->db->prepare( 'CALL commitcap_v01_open(%s)', $nonce ) );
			if ( 0 !== $this->state_consumed_by_policy( $nonce ) ) {
				throw new \RuntimeException( 'WriteLeash runtime open probe did not create clean accounting state.' );
			}
			$this->execute( $this->db->prepare( 'CALL commitcap_v01_count(%s, ' . $out . ')', $nonce ) );
			$reported = $this->db->get_var( 'SELECT ' . $out );
			if ( null === $reported || 0 !== (int) $reported ) {
				throw new \RuntimeException( 'WriteLeash runtime count probe disagrees with direct helper state.' );
			}
			$this->execute( 'SET @commitcap_v01_denied = 1' );
			$denied = $this->db->query( $this->db->prepare( 'CALL commitcap_v01_close(%s)', $nonce ) );
			if ( false !== $denied ) {
				throw new \RuntimeException( 'WriteLeash runtime close probe did not enforce the session denial signal.' );
			}
			$this->db->query( 'SELECT 1' );
			$this->execute( 'SET @commitcap_v01_denied = 0' );
			$this->execute( $this->db->prepare( 'CALL commitcap_v01_close(%s)', $nonce ) );
			if ( null !== $this->state_consumed_by_policy( $nonce ) ) {
				throw new \RuntimeException( 'WriteLeash runtime close probe left accounting state behind.' );
			}
		} catch ( \Throwable $error ) {
			$this->cleanup_probe_state( $nonce );
			throw $error;
		}
	}

	private function cleanup_probe_state( string $nonce ): void {
		$this->db->query( 'SELECT 1' );
		$this->db->query( 'SET @commitcap_v01_denied = 0' );
		$this->db->query( $this->db->prepare( 'CALL commitcap_v01_close(%s)', $nonce ) );
	}

	/**
	 * Restricted writer: behavioral trigger accounting probe for one target.
	 *
	 * Opens accounting, issues one data-preserving no-op UPDATE that still fires
	 * the BEFORE UPDATE trigger on both pinned engines, and requires the direct
	 * helper read and commitcap_v01_count to both report exactly one event.
	 * The whole probe is rolled back; `col = col` changes no application value.
	 *
	 * @return string 'probed' or 'empty_table'
	 */
	public function runtime_trigger_probe( string $table ): string {
		$name = self::table( $table );
		$this->require_target_server();
		$column = $this->db->get_var( $this->db->prepare(
			"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND (EXTRA IS NULL OR EXTRA NOT LIKE '%%GENERATED%%') ORDER BY ORDINAL_POSITION LIMIT 1",
			$name
		) );
		if ( ! is_string( $column ) || '' === $column || '' !== (string) $this->db->last_error ) {
			throw new \RuntimeException( 'WriteLeash runtime trigger probe cannot identify a writable target column.' );
		}
		$has_row = $this->db->get_var( 'SELECT 1 FROM `' . $name . '` LIMIT 1' );
		if ( null === $has_row && '' === (string) $this->db->last_error ) {
			return 'empty_table';
		}
		if ( '' !== (string) $this->db->last_error ) {
			throw new \RuntimeException( 'WriteLeash runtime trigger probe cannot read the target table.' );
		}

		$policy = self::policy_id( $name );
		$this->db->query( 'SELECT 1' );
		try {
			if ( false === $this->db->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'WriteLeash runtime trigger probe could not start a probe transaction.' );
			}
			$this->execute( 'SET @commitcap_v01_denied = 0' );
			$this->execute( $this->db->prepare( 'CALL commitcap_v01_open(%s)', $policy ) );
			$updated = $this->db->query( 'UPDATE `' . $name . '` SET `' . $column . '` = `' . $column . '` LIMIT 1' );
			if ( false === $updated ) {
				throw new \RuntimeException( 'WriteLeash runtime trigger probe no-op UPDATE failed.' );
			}
			if ( 1 !== $this->state_consumed_by_policy( $policy ) ) {
				throw new \RuntimeException( 'WriteLeash runtime trigger probe observed incorrect physical accounting for one row event.' );
			}
			$this->execute( $this->db->prepare( 'CALL commitcap_v01_count(%s, @cc_trigger_probe_count)', $policy ) );
			$reported = $this->db->get_var( 'SELECT @cc_trigger_probe_count' );
			if ( null === $reported || 1 !== (int) $reported ) {
				throw new \RuntimeException( 'WriteLeash runtime trigger probe count routine disagrees with direct accounting.' );
			}
			$this->execute( $this->db->prepare( 'CALL commitcap_v01_close(%s)', $policy ) );
			$this->db->query( 'ROLLBACK' );
			if ( null !== $this->state_consumed_by_policy( $policy ) ) {
				throw new \RuntimeException( 'WriteLeash runtime trigger probe left accounting state behind.' );
			}
			return 'probed';
		} catch ( \Throwable $error ) {
			$this->db->query( 'ROLLBACK' );
			$this->db->query( 'SELECT 1' );
			throw $error;
		}
	}

	/** Unmediated direct helper-state read. Throws when the helper is unreadable. */
	public function state_consumed( $table ): ?int {
		$name = self::table( $table );
		return $this->state_consumed_by_policy( self::policy_id( $name ) );
	}

	/** Any trusted rotation with an unverified drain blocks ALL shared policies. */
	public function rotation_unsafe(): bool {
		$value = $this->db->get_var( 'SELECT COUNT(*) FROM commitcap_v01_state WHERE connection_id = 0' );
		if ( null === $value || '' !== (string) $this->db->last_error ) {
			throw new \RuntimeException( 'Cannot inspect trusted rotation safety state.' );
		}
		return (int) $value > 0;
	}

	private function state_consumed_by_policy( string $policy_id ): ?int {
		$value = $this->db->get_var( $this->db->prepare(
			'SELECT consumed FROM commitcap_v01_state WHERE connection_id = CONNECTION_ID() AND policy_id = %s', $policy_id
		) );
		if ( '' !== (string) $this->db->last_error ) {
			throw new \RuntimeException( 'WriteLeash direct helper state read failed: ' . $this->db->last_error );
		}
		return null === $value ? null : (int) $value;
	}

	/** Trusted structural check reusable by provisioning preflight (helper must exist). */
	public function assert_canonical_helper(): void {
		$this->verify_infrastructure_table();
	}

	/** Trusted structural check for one existing routine, refusing foreign bodies. */
	public function assert_canonical_routine( string $name ): void {
		if ( ! in_array( $name, self::ROUTINE_NAMES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown managed routine name.' );
		}
		$entry = $this->routine( $name );
		if ( null === $entry ) {
			return;
		}
		$canonical = self::routines()[ $name ];
		if ( ! is_string( $entry->ROUTINE_DEFINITION ) || ! self::same_sql( $entry->ROUTINE_DEFINITION, $canonical ) ) {
			throw new \RuntimeException( 'Existing managed runtime routine has a foreign body; refusing to replace it: ' . $name . '.' );
		}
	}

	/**
	 * Trusted structural check for an existing target trigger.
	 * Absent trigger = true; canonical managed trigger = true; foreign = throw.
	 */
	public function assert_canonical_trigger( string $table, bool $allow_absent, string $runtime_user ): bool {
		$name    = self::table( $table );
		$runtime = self::runtime_user( $runtime_user );
		$info    = $this->inspect_table( $name );
		if ( ! $info['triggers'] ) {
			if ( $allow_absent ) {
				return false;
			}
			throw new \RuntimeException( 'WriteLeash policy trigger is absent for ' . $name . '.' );
		}
		if ( 1 !== count( $info['triggers'] ) || self::trigger_name( $name ) !== $info['triggers'][0]->TRIGGER_NAME ) {
			throw new \RuntimeException( 'Refusing to replace: table ' . $name . ' has an existing unreviewed trigger ' . $info['triggers'][0]->TRIGGER_NAME . '.' );
		}
		$statement = (string) $info['triggers'][0]->ACTION_STATEMENT;
		if ( ! self::statement_is_canonical_trigger( $name, $statement, $runtime ) ) {
			throw new \RuntimeException( 'Refusing to replace a managed runtime trigger with a foreign body on ' . $name . '.' );
		}
		return true;
	}

	/** Structural canonical trigger template match, ceiling extracted, no PHP re-derivation. */
	public static function statement_is_canonical_trigger( string $table, string $statement, string $runtime_user ): bool {
		$name    = self::table( $table );
		$runtime = self::runtime_user( $runtime_user );
		$normalized = self::normalize_sql( $statement );
		if ( null === $normalized ) {
			return false;
		}
		$policy_id = self::policy_id( $name );
		$prefix    = self::trigger_prefix( $policy_id, $runtime );
		$suffix    = self::trigger_suffix();
		if ( 0 !== strpos( $normalized, $prefix ) || substr( $normalized, -strlen( $suffix ) ) !== $suffix ) {
			return false;
		}
		$ceiling = substr( $normalized, strlen( $prefix ), strlen( $normalized ) - strlen( $prefix ) - strlen( $suffix ) );
		try {
			self::budget( $ceiling );
		} catch ( \Throwable $error ) {
			return false;
		}
		return self::same_sql( self::trigger_body( $name, (int) $ceiling, $runtime ), $statement );
	}

	/** Restricted writer: check trigger count on non-target or foreign table via trusted procedure. */
	public function runtime_table_triggers( string $table ): int {
		$this->require_target_server();
		$rows = $this->rows( $this->db->prepare( "CALL commitcap_v01_policy(%s, '')", $table ) );
		if ( 1 !== count( $rows ) ) {
			throw new \RuntimeException( 'WriteLeash runtime trigger inspection failed for table: ' . $table );
		}
		return (int) $rows[0]->TRIGGER_COUNT;
	}

	/** Refuse unknown objects: no DROP TABLE and no wildcard trigger cleanup. */
	public function remove_owned_policy( $table, $budget, $runtime_user ): void {
		$name = self::table( $table );
		$this->verify_policy( $name, $budget, $runtime_user );
		$trigger = self::trigger_name( $name );
		$this->execute( "DROP TRIGGER `$trigger`" );
	}

	/** #56 must invoke ONCE after START TRANSACTION, before any protected SQL. */
	public function begin_guard_state(): void {
		// Nontransactional on purpose: survives SIGNAL and savepoint recovery.
		$this->execute( 'SET @commitcap_v01_denied = 0' );
	}

	public function denial_seen(): bool {
		$value = $this->db->get_var( 'SELECT @commitcap_v01_denied' );
		if ( null === $value ) {
			throw new \RuntimeException( 'Unknown WriteLeash session denial state.' );
		}
		return 0 !== (int) $value;
	}

	/** Must be called only after the PHP guard starts its explicit transaction. */
	public function begin_accounting( $table, $budget = null ): void {
		$name = self::table( $table );
		if ( null !== $budget ) {
			$this->verify_runtime_budget( $name, $budget );
		} else {
			$this->runtime_ceiling( $name );
		}
		$this->execute( $this->db->prepare( 'CALL commitcap_v01_open(%s)', self::policy_id( $name ) ) );
	}

	/** Call before the PHP guard's final COMMIT; never after it. */
	public function end_accounting( $table ): void {
		$name = self::table( $table );
		$this->execute( $this->db->prepare( 'CALL commitcap_v01_close(%s)', self::policy_id( $name ) ) );
	}

	public function consumed( $table ): ?int {
		$name = self::table( $table );
		$this->execute( $this->db->prepare( 'CALL commitcap_v01_count(%s, @commitcap_v01_consumed)', self::policy_id( $name ) ) );
		$value = $this->db->get_var( 'SELECT @commitcap_v01_consumed' );
		return null === $value ? null : (int) $value;
	}

	/** Snapshot the original failed statement BEFORE any later database query. */
	private function budget_error(): ?array {
		$link = $this->db->dbh;
		if ( ! $link instanceof \mysqli ) {
			return null;
		}
		$errno = $link->errno;
		$sqlstate = $link->sqlstate;
		$message = $this->db->last_error;
		if ( 1644 !== $errno || '45000' !== $sqlstate || 'CC54_DENIED' !== $message ) {
			return null;
		}
		return array( 'errno' => $errno, 'sqlstate' => $sqlstate );
	}

	public function is_budget_denial(): bool {
		return null !== $this->budget_error();
	}

	/** Read immediately after a failed UPDATE, before another query resets errno. */
	public function denial_details( $table, $budget, ?int $ceiling = null ): ?array {
		$name = self::table( $table );
		$limit = self::budget( $budget );
		$error = $this->budget_error();
		if ( null === $error ) {
			return null;
		}
		try {
			// Unmediated helper read: denial attribution must not depend on any
			// routine body that an adversarial tamper could replace.
			$count = $this->state_consumed( $name );
		} catch ( \Throwable $error ) {
			$count = null;
		}
		$details = array(
			'table'     => $name,
			'budget'    => $limit,
			// The failed statement rolls back its own helper increments. If a
			// counter row exists, the trigger must have reached the literal limit
			// before SIGNAL, including on a broad multi-row UPDATE.
			'consumed'  => null === $count ? null : $limit,
			'attempted' => null === $count ? null : $limit + 1,
			'returned_count' => $count,
			'reason'    => null === $count ? 'no_active_accounting' : 'budget_exceeded',
			'sqlstate'  => $error['sqlstate'],
			'errno'     => $error['errno'],
		);
		if ( null !== $ceiling ) {
			$details['physical_ceiling'] = $ceiling;
		}
		return $details;
	}
}
