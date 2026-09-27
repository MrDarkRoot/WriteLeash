<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lower-level UPDATE row-event engine. Trusted DDL is separate from the
 * restricted writer; transaction ownership and immediate rollback belong to #56.
 */
final class Update_Engine {
	private const STATE = 'commitcap_v01_state';
	private const COMMENT = 'CommitCap V0.1 cooperative UPDATE state';
	private const MAX_BUDGET = '2147483647';
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

	private static function policy_id( string $table ): string {
		return hash( 'sha256', $table );
	}

	private static function trigger_name( string $table ): string {
		return 'commitcap_v01_' . substr( self::policy_id( $table ), 0, 16 );
	}

	private function execute( string $sql ): void {
		if ( false === $this->db->query( $sql ) ) {
			throw new \RuntimeException( 'CommitCap database operation failed: ' . $this->db->last_error );
		}
	}

	private function rows( string $sql ): array {
		$rows = $this->db->get_results( $sql );
		if ( null === $rows ) {
			throw new \RuntimeException( 'CommitCap metadata query failed: ' . $this->db->last_error );
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
		}
		throw new \InvalidArgumentException( 'Unknown routine signature.' );
	}

	private static function routine_params( string $name ): string {
		$policy = 'IN p_policy CHAR(64) CHARACTER SET ascii COLLATE ascii_bin';
		switch ( $name ) {
			case 'commitcap_v01_open':
			case 'commitcap_v01_close':
				return '(' . $policy . ')';
			case 'commitcap_v01_count':
				return '(' . $policy . ', OUT p_count BIGINT UNSIGNED)';
			case 'commitcap_v01_policy':
				return '(IN p_table VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin, IN p_trigger VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin)';
		}
		throw new \InvalidArgumentException( 'Unknown routine declaration.' );
	}

	private static function routines(): array {
		return array(
			'commitcap_v01_open' => 'BEGIN INSERT INTO commitcap_v01_state (connection_id, policy_id, consumed) VALUES (CONNECTION_ID(), p_policy, 0); END',
			'commitcap_v01_close' => "BEGIN IF COALESCE(@commitcap_v01_denied, 1) != 0 THEN SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 1644, MESSAGE_TEXT = 'CC54_DENIED_PRECOMMIT'; END IF; DELETE FROM commitcap_v01_state WHERE connection_id = CONNECTION_ID() AND policy_id = p_policy; IF ROW_COUNT() != 1 THEN SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 1644, MESSAGE_TEXT = 'CC54_STATE_MISSING'; END IF; END",
			'commitcap_v01_count' => 'BEGIN SELECT consumed INTO p_count FROM commitcap_v01_state WHERE connection_id = CONNECTION_ID() AND policy_id = p_policy; END',
			'commitcap_v01_policy' => "BEGIN SELECT t.TRIGGER_NAME, t.ACTION_STATEMENT, t.ACTION_TIMING, t.EVENT_MANIPULATION, t.DEFINER = CURRENT_USER() AS TRUSTED_DEFINER, (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = p_table) AS TRIGGER_COUNT, (SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND TABLE_TYPE = 'BASE TABLE') AS TABLE_ENGINE, (SELECT COUNT(*) FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND PARTITION_NAME IS NOT NULL) AS PARTITION_COUNT, (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME IS NOT NULL AND ((TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table) OR (REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = p_table))) AS FOREIGN_KEY_COUNT FROM information_schema.TRIGGERS t WHERE t.TRIGGER_SCHEMA = DATABASE() AND t.TRIGGER_NAME = p_trigger AND t.EVENT_OBJECT_TABLE = p_table; END",
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
			throw new \RuntimeException( 'Unknown/conflicting CommitCap helper object.' );
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
			throw new \RuntimeException( 'Unknown CommitCap helper column shape.' );
		}
		$indexes = $this->rows( $this->db->prepare(
			'SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY INDEX_NAME, SEQ_IN_INDEX', self::STATE
		) );
		if ( 2 !== count( $indexes ) || 'PRIMARY' !== $indexes[0]->INDEX_NAME ||
			'connection_id' !== $indexes[0]->COLUMN_NAME || 'PRIMARY' !== $indexes[1]->INDEX_NAME ||
			'policy_id' !== $indexes[1]->COLUMN_NAME || $this->triggers( self::STATE ) ) {
			throw new \RuntimeException( 'Unknown CommitCap helper key or trigger.' );
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
			throw new \RuntimeException( 'Unknown/conflicting CommitCap routine: ' . $name );
		}
		$params = $this->rows( $this->db->prepare(
			'SELECT ORDINAL_POSITION, PARAMETER_NAME, PARAMETER_MODE, DTD_IDENTIFIER, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.PARAMETERS WHERE SPECIFIC_SCHEMA = DATABASE() AND SPECIFIC_NAME = %s ORDER BY ORDINAL_POSITION', $name
		) );
		$expected = self::signature( $name );
		if ( count( $params ) !== count( $expected ) ) {
			throw new \RuntimeException( 'Unknown CommitCap routine signature: ' . $name );
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
				throw new \RuntimeException( 'Unknown CommitCap routine signature: ' . $name );
			}
		}
	}

	private function verify_infrastructure(): void {
		$this->verify_infrastructure_table();
		foreach ( self::routines() as $name => $body ) {
			$this->verify_routine( $name, $body );
		}
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

	private static function trigger_body( string $table, int $budget ): string {
		$id = self::policy_id( $table );
		return "BEGIN UPDATE commitcap_v01_state SET consumed = consumed + 1 WHERE connection_id = CONNECTION_ID() AND policy_id = '$id' AND consumed < $budget; IF ROW_COUNT() != 1 THEN SET @commitcap_v01_denied = 1; SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 1644, MESSAGE_TEXT = 'CC54_DENIED'; END IF; END";
	}

	public function install_policy( $table, $budget ): void {
		$name = self::table( $table );
		$limit = self::budget( $budget );
		$this->verify_infrastructure();
		$info = $this->inspect_table( $name );
		if ( $info['triggers'] ) {
			throw new \RuntimeException( 'Existing trigger/policy conflict; no overwrite.' );
		}
		$trigger = self::trigger_name( $name );
		$this->execute( "CREATE TRIGGER `$trigger` BEFORE UPDATE ON `$name` FOR EACH ROW " . self::trigger_body( $name, $limit ) );
		$this->verify_policy( $name, $limit );
	}

	public function verify_policy( $table, $budget ): void {
		$name = self::table( $table );
		$limit = self::budget( $budget );
		$this->verify_infrastructure();
		$info = $this->inspect_table( $name );
		$triggers = $info['triggers'];
		if ( 1 !== count( $triggers ) || self::trigger_name( $name ) !== $triggers[0]->TRIGGER_NAME ||
			'BEFORE' !== $triggers[0]->ACTION_TIMING || 'UPDATE' !== $triggers[0]->EVENT_MANIPULATION ||
			! self::same_sql( self::trigger_body( $name, $limit ), $triggers[0]->ACTION_STATEMENT ) ||
			$triggers[0]->DEFINER !== $this->db->get_var( 'SELECT CURRENT_USER()' ) ) {
			throw new \RuntimeException( 'CommitCap policy absent, conflicting or malformed.' );
		}
	}

	/** Restricted writer: read-only metadata snapshot through a trusted definer. */
	public function verify_runtime_policy( $table, $budget ): void {
		$name = self::table( $table );
		$limit = self::budget( $budget );
		$this->require_target_server();
		$found = $this->rows( $this->db->prepare(
			'CALL commitcap_v01_policy(%s, %s)', $name, self::trigger_name( $name )
		) );
		if ( 1 !== count( $found ) || self::trigger_name( $name ) !== $found[0]->TRIGGER_NAME ||
			'BEFORE' !== $found[0]->ACTION_TIMING || 'UPDATE' !== $found[0]->EVENT_MANIPULATION ||
			'InnoDB' !== $found[0]->TABLE_ENGINE || 1 !== (int) $found[0]->TRIGGER_COUNT ||
			0 !== (int) $found[0]->PARTITION_COUNT || 0 !== (int) $found[0]->FOREIGN_KEY_COUNT ||
			1 !== (int) $found[0]->TRUSTED_DEFINER ||
			! self::same_sql( self::trigger_body( $name, $limit ), $found[0]->ACTION_STATEMENT ) ) {
			throw new \RuntimeException( 'CommitCap runtime policy absent or altered.' );
		}
	}

	/** Refuse unknown objects: no DROP TABLE and no wildcard trigger cleanup. */
	public function remove_owned_policy( $table, $budget ): void {
		$name = self::table( $table );
		$this->verify_policy( $name, $budget );
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
			throw new \RuntimeException( 'Unknown CommitCap session denial state.' );
		}
		return 0 !== (int) $value;
	}

	/** Must be called only after the PHP guard starts its explicit transaction. */
	public function begin_accounting( $table, $budget ): void {
		$name = self::table( $table );
		$this->verify_runtime_policy( $name, $budget );
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
	public function denial_details( $table, $budget ): ?array {
		$name = self::table( $table );
		$limit = self::budget( $budget );
		$error = $this->budget_error();
		if ( null === $error ) {
			return null;
		}
		$count = $this->consumed( $name );
		return array(
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
	}
}
