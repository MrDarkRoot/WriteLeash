<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deterministic, reviewable operator provisioning plan renderer and executor.
 *
 * Implements Gate #84: install, add target, remove target, rotate credential,
 * verify and uninstall for one shared restricted runtime account per environment.
 * All plans are inspectable offline before execution, enforce conflict refusal,
 * preserve application tables and their data rows, and avoid retaining installer
 * credentials in normal web PHP. Failure paths never echo credential SQL.
 */
final class Provisioning_Plan {
	public const ACTION_INSTALL           = 'install';
	public const ACTION_ADD_TARGET        = 'add_target';
	public const ACTION_REMOVE_TARGET     = 'remove_target';
	public const ACTION_ROTATE_CREDENTIAL = 'rotate_credential';
	public const ACTION_UNINSTALL         = 'uninstall';
	public const ACTION_DRAIN             = 'drain';

	public const REDACTED_SECRET = '[REDACTED_SECRET]';

	/** @var string */
	private $action;

	/** @var array<string, mixed> */
	private $params;

	/** @var array<int, array<string, mixed>> */
	private $steps;

	/** @var string|null */
	private $raw_secret;

	private function __construct( string $action, array $params, array $steps, ?string $raw_secret = null ) {
		$this->action     = $action;
		$this->params     = $params;
		$this->steps      = $steps;
		$this->raw_secret = $raw_secret;
	}

	public static function validate_identifier( $name, string $label = 'identifier' ): string {
		if ( ! is_string( $name ) || strlen( $name ) > 64 ||
			! preg_match( '/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name ) ) {
			throw new \InvalidArgumentException( "Invalid simple unqualified ASCII $label: " . ( is_string( $name ) ? $name : gettype( $name ) ) );
		}
		return $name;
	}

	public static function validate_user( $user ): string {
		return Update_Engine::runtime_user( $user );
	}

	public static function validate_host( $host ): string {
		if ( ! is_string( $host ) || strlen( $host ) > 255 ||
			! preg_match( '/\A[A-Za-z0-9_.\-%]+\z/D', $host ) ) {
			throw new \InvalidArgumentException( 'Invalid database host specifier.' );
		}
		return $host;
	}

	private static function escape_literal( ?string $value ): string {
		if ( null === $value ) {
			return "''";
		}
		return "'" . addcslashes( $value, "\0..\37'\\" ) . "'";
	}

	/** Trusted-only durable marker, visible to Doctor/Guard via existing helper SELECT. */
	private static function rotation_marker( string $user, string $host ): string {
		return hash( 'sha256', 'commitcap_v01_rotation:' . $user . '@' . $host );
	}

	private static function mark_rotation( \wpdb $installer, string $user, string $host, bool $unsafe ): void {
		$marker = self::rotation_marker( $user, $host );
		$query = $unsafe
			? $installer->prepare( 'INSERT INTO commitcap_v01_state (connection_id, policy_id, consumed) VALUES (0, %s, 1) ON DUPLICATE KEY UPDATE consumed = 1', $marker )
			: $installer->prepare( 'DELETE FROM commitcap_v01_state WHERE connection_id = 0 AND policy_id = %s', $marker );
		if ( false === $installer->query( $query ) ) {
			throw new \RuntimeException( 'Trusted rotation safety marker could not be updated; protected enablement remains unverified.' );
		}
	}

	/**
	 * Render plan to install initial infrastructure and one restricted runtime account.
	 *
	 * @param string $schema Target database schema name.
	 * @param string $runtime_user Restricted DB username to create.
	 * @param string $runtime_host Host restriction (default 'localhost').
	 * @param string|null $secret Runtime password. Redacted in plan output.
	 * @param array<string, int> $targets Optional initial targets as array( 'table_name' => physical_ceiling ).
	 * @return self
	 */
	public static function install(
		string $schema,
		string $runtime_user,
		string $runtime_host = 'localhost',
		?string $secret = null,
		array $targets = array()
	): self {
		$schema       = self::validate_identifier( $schema, 'schema' );
		$runtime_user = self::validate_user( $runtime_user );
		$runtime_host = self::validate_host( $runtime_host );
		$user_id      = "'$runtime_user'@'$runtime_host'";

		$steps = array();

		// Step 1: Create restricted runtime account
		$sql_user_redacted = "CREATE USER IF NOT EXISTS $user_id IDENTIFIED BY " . self::escape_literal( self::REDACTED_SECRET );
		$sql_user_raw      = "CREATE USER IF NOT EXISTS $user_id IDENTIFIED BY " . self::escape_literal( $secret ?? '' );
		$steps[] = array(
			'id'             => 'create_runtime_user',
			'kind'           => 'DCL',
			'description'    => "Create restricted runtime database user $user_id",
			'sql'            => $sql_user_redacted,
			'raw_sql'        => $sql_user_raw,
			'statements'     => array( $sql_user_redacted ),
			'raw_statements' => array( $sql_user_raw ),
			'grant_delta'    => '+USER',
			'reversible'     => true,
		);

		// Step 2: Helper table
		$state_table = Update_Engine::STATE;
		$comment     = Update_Engine::COMMENT;
		$sql_helper  = "CREATE TABLE IF NOT EXISTS `$schema`.`$state_table` (connection_id BIGINT UNSIGNED NOT NULL, policy_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, consumed BIGINT UNSIGNED NOT NULL, PRIMARY KEY (connection_id, policy_id)) ENGINE=InnoDB COMMENT=" . self::escape_literal( $comment );
		$steps[]     = array(
			'id'             => 'create_helper_table',
			'kind'           => 'DDL',
			'description'    => "Create CommitCap helper state table `$schema`.`$state_table`",
			'sql'            => $sql_helper,
			'raw_sql'        => $sql_helper,
			'statements'     => array( $sql_helper ),
			'raw_statements' => array( $sql_helper ),
			'grant_delta'    => 'NONE',
			'reversible'     => true,
		);

		// Steps 3+: Five routines with SQL SECURITY DEFINER
		foreach ( Update_Engine::routines() as $rname => $body ) {
			$params   = Update_Engine::routine_params( $rname );
			$drop_sql = "DROP PROCEDURE IF EXISTS `$schema`.`$rname`";
			$create_sql = "CREATE PROCEDURE `$schema`.`$rname` $params SQL SECURITY DEFINER $body";
			$steps[]  = array(
				'id'             => "create_routine_$rname",
				'kind'           => 'DDL',
				'description'    => "Create DEFINER routine `$schema`.`$rname`",
				'sql'            => "$drop_sql; $create_sql",
				'raw_sql'        => "$drop_sql; $create_sql",
				'statements'     => array( $drop_sql, $create_sql ),
				'raw_statements' => array( $drop_sql, $create_sql ),
				'grant_delta'    => 'NONE',
				'reversible'     => true,
			);
		}

		// Revoke all accidental or inherited broad privileges from runtime user
		$sql_revoke = "REVOKE ALL PRIVILEGES, GRANT OPTION FROM $user_id";
		$steps[] = array(
			'id'             => 'revoke_broad_grants',
			'kind'           => 'DCL',
			'description'    => "Revoke accidental broad privileges from $user_id",
			'sql'            => $sql_revoke,
			'raw_sql'        => $sql_revoke,
			'statements'     => array( $sql_revoke ),
			'raw_statements' => array( $sql_revoke ),
			'grant_delta'    => '-ALL',
			'reversible'     => true,
			'tolerate_errno' => array( 1141 ),
		);

		// Grant EXECUTE on exactly the reviewed routines
		foreach ( Update_Engine::routine_names() as $rname ) {
			$sql_exec = "GRANT EXECUTE ON PROCEDURE `$schema`.`$rname` TO $user_id";
			$steps[]  = array(
				'id'             => "grant_execute_$rname",
				'kind'           => 'DCL',
				'description'    => "Grant EXECUTE on `$schema`.`$rname` to $user_id",
				'sql'            => $sql_exec,
				'raw_sql'        => $sql_exec,
				'statements'     => array( $sql_exec ),
				'raw_statements' => array( $sql_exec ),
				'grant_delta'    => "+EXECUTE($rname)",
				'reversible'     => true,
			);
		}

		// Reviewed unmediated helper-state read for the runtime evidence probes.
		$sql_state_select = "GRANT SELECT ON `$schema`.`$state_table` TO $user_id";
		$steps[] = array(
			'id'             => 'grant_helper_select',
			'kind'           => 'DCL',
			'description'    => "Grant reviewed SELECT on `$schema`.`$state_table` to $user_id",
			'sql'            => $sql_state_select,
			'raw_sql'        => $sql_state_select,
			'statements'     => array( $sql_state_select ),
			'raw_statements' => array( $sql_state_select ),
			'grant_delta'    => '+SELECT(commitcap_v01_state)',
			'reversible'     => true,
		);

		// Optional initial target policies
		foreach ( $targets as $tbl => $ceiling ) {
			$sub = self::add_target( $schema, $runtime_user, $runtime_host, $tbl, (int) $ceiling );
			foreach ( $sub->steps as $s ) {
				$steps[] = $s;
			}
		}

		return new self(
			self::ACTION_INSTALL,
			array(
				'schema'       => $schema,
				'runtime_user' => $runtime_user,
				'runtime_host' => $runtime_host,
				'targets'      => $targets,
			),
			$steps,
			$secret
		);
	}

	/**
	 * Render plan to add an exact target table policy to the shared runtime.
	 *
	 * @param string $schema Database schema name.
	 * @param string $runtime_user Restricted DB username.
	 * @param string $runtime_host Host restriction.
	 * @param string $table Target table name.
	 * @param int $physical_ceiling Physical ceiling row event limit P > 0.
	 * @return self
	 */
	public static function add_target(
		string $schema,
		string $runtime_user,
		string $runtime_host,
		string $table,
		int $physical_ceiling
	): self {
		$schema       = self::validate_identifier( $schema, 'schema' );
		$runtime_user = self::validate_user( $runtime_user );
		$runtime_host = self::validate_host( $runtime_host );
		$table_name   = Update_Engine::table( $table );
		$ceiling      = Update_Engine::budget( $physical_ceiling );
		if ( $ceiling < 1 ) {
			throw new \InvalidArgumentException( 'Physical ceiling must be positive.' );
		}
		$user_id      = "'$runtime_user'@'$runtime_host'";
		$trigger_name = Update_Engine::trigger_name( $table_name );
		$trigger_body = Update_Engine::trigger_body( $table_name, $ceiling, $runtime_user );

		$sql_grant = "GRANT SELECT, UPDATE ON `$schema`.`$table_name` TO $user_id";
		$sql_drop_trig = "DROP TRIGGER IF EXISTS `$schema`.`$trigger_name`";
		$sql_create_trig = "CREATE TRIGGER `$schema`.`$trigger_name` BEFORE UPDATE ON `$schema`.`$table_name` FOR EACH ROW $trigger_body";

		$steps = array(
			array(
				'id'             => "grant_target_{$table_name}",
				'kind'           => 'DCL',
				'description'    => "Grant SELECT, UPDATE on `$schema`.`$table_name` to $user_id",
				'sql'            => $sql_grant,
				'raw_sql'        => $sql_grant,
				'statements'     => array( $sql_grant ),
				'raw_statements' => array( $sql_grant ),
				'grant_delta'    => "+SELECT,UPDATE($table_name)",
				'reversible'     => true,
			),
			array(
				'id'             => "create_trigger_{$table_name}",
				'kind'           => 'DDL',
				'description'    => "Create BEFORE UPDATE trigger `$schema`.`$trigger_name` on `$table_name` (ceiling $ceiling)",
				'sql'            => "$sql_drop_trig; $sql_create_trig",
				'raw_sql'        => "$sql_drop_trig; $sql_create_trig",
				'statements'     => array( $sql_drop_trig, $sql_create_trig ),
				'raw_statements' => array( $sql_drop_trig, $sql_create_trig ),
				'grant_delta'    => 'NONE',
				'reversible'     => true,
			),
		);

		return new self(
			self::ACTION_ADD_TARGET,
			array(
				'schema'           => $schema,
				'runtime_user'     => $runtime_user,
				'runtime_host'     => $runtime_host,
				'table'            => $table_name,
				'physical_ceiling' => $ceiling,
			),
			$steps
		);
	}

	/**
	 * Render plan to remove a target table policy while preserving the table and rows.
	 *
	 * @param string $schema Database schema name.
	 * @param string $runtime_user Restricted DB username.
	 * @param string $runtime_host Host restriction.
	 * @param string $table Target table name.
	 * @param int $physical_ceiling Physical ceiling for reference.
	 * @return self
	 */
	public static function remove_target(
		string $schema,
		string $runtime_user,
		string $runtime_host,
		string $table,
		int $physical_ceiling = 0
	): self {
		$schema       = self::validate_identifier( $schema, 'schema' );
		$runtime_user = self::validate_user( $runtime_user );
		$runtime_host = self::validate_host( $runtime_host );
		$table_name   = Update_Engine::table( $table );
		$user_id      = "'$runtime_user'@'$runtime_host'";
		$trigger_name = Update_Engine::trigger_name( $table_name );

		$sql_drop_trig = "DROP TRIGGER IF EXISTS `$schema`.`$trigger_name`";
		$sql_revoke    = "REVOKE SELECT, UPDATE ON `$schema`.`$table_name` FROM $user_id";

		$steps = array(
			array(
				'id'              => "drop_trigger_{$table_name}",
				'kind'            => 'DDL',
				'description'     => "Drop trigger `$schema`.`$trigger_name` on `$table_name` (table and data rows survive)",
				'sql'             => $sql_drop_trig,
				'raw_sql'         => $sql_drop_trig,
				'statements'      => array( $sql_drop_trig ),
				'raw_statements'  => array( $sql_drop_trig ),
				'grant_delta'     => 'NONE',
				'reversible'      => false,
				'drops_app_table' => false,
			),
			array(
				'id'              => "revoke_target_{$table_name}",
				'kind'            => 'DCL',
				'description'     => "Revoke SELECT, UPDATE on `$schema`.`$table_name` from $user_id",
				'sql'             => $sql_revoke,
				'raw_sql'         => $sql_revoke,
				'statements'      => array( $sql_revoke ),
				'raw_statements'  => array( $sql_revoke ),
				'grant_delta'     => "-SELECT,UPDATE($table_name)",
				'reversible'      => true,
				'drops_app_table' => false,
				'tolerate_errno'  => array( 1141 ),
			),
		);

		return new self(
			self::ACTION_REMOVE_TARGET,
			array(
				'schema'           => $schema,
				'runtime_user'     => $runtime_user,
				'runtime_host'     => $runtime_host,
				'table'            => $table_name,
				'physical_ceiling' => $physical_ceiling,
			),
			$steps
		);
	}

	/**
	 * Render plan to rotate runtime credentials and deterministically drain
	 * surviving authenticated sessions.
	 *
	 * The drain step terminates every remaining session of the runtime account
	 * on the trusted installer connection. The trusted operator needs
	 * global PROCESS for visibility AND global CONNECTION_ADMIN/SUPER (MySQL)
	 * or CONNECTION ADMIN/SUPER (MariaDB) for other-user KILL. Rotation alone does
	 * not terminate already-authenticated sessions on either pinned engine.
	 *
	 * @param string $runtime_user Restricted DB username.
	 * @param string $runtime_host Host restriction.
	 * @param string|null $new_secret New password. Redacted in plan output.
	 * @param bool $drain Include the supported session drain step.
	 * @return self
	 */
	public static function rotate_credential(
		string $runtime_user,
		string $runtime_host = 'localhost',
		?string $new_secret = null,
		bool $drain = true
	): self {
		$runtime_user = self::validate_user( $runtime_user );
		$runtime_host = self::validate_host( $runtime_host );
		$user_id      = "'$runtime_user'@'$runtime_host'";

		$sql_redacted = "ALTER USER $user_id IDENTIFIED BY " . self::escape_literal( self::REDACTED_SECRET );
		$sql_raw      = "ALTER USER $user_id IDENTIFIED BY " . self::escape_literal( $new_secret ?? '' );

		$steps = array(
			array(
				'id'             => 'alter_user_password',
				'kind'           => 'DCL',
				'description'    => "Rotate password for runtime user $user_id",
				'sql'            => $sql_redacted,
				'raw_sql'        => $sql_raw,
				'statements'     => array( $sql_redacted ),
				'raw_statements' => array( $sql_raw ),
				'grant_delta'    => 'CREDENTIAL_ROTATED',
				'reversible'     => true,
			),
		);

		if ( $drain ) {
			$steps[] = self::drain_step( $runtime_user, $runtime_host );
		}

		return new self(
			self::ACTION_ROTATE_CREDENTIAL,
			array(
				'runtime_user' => $runtime_user,
				'runtime_host' => $runtime_host,
				'drain'        => $drain,
			),
			$steps,
			$new_secret
		);
	}

	/** Render a standalone supported session-drain plan. */
	public static function drain( string $runtime_user, string $runtime_host = 'localhost' ): self {
		$runtime_user = self::validate_user( $runtime_user );
		$runtime_host = self::validate_host( $runtime_host );
		return new self(
			self::ACTION_DRAIN,
			array( 'runtime_user' => $runtime_user, 'runtime_host' => $runtime_host, 'drain' => true ),
			array( self::drain_step( $runtime_user, $runtime_host ) )
		);
	}

	private static function drain_step( string $runtime_user, string $runtime_host ): array {
		$user_id = "'$runtime_user'@'$runtime_host'";
		return array(
			'id'             => 'drain_runtime_sessions',
			'kind'           => 'DRAIN',
			'description'    => "Terminate every surviving authenticated session for $user_id so only rotated authority remains",
			'sql'            => "SELECT ID, USER, HOST, DB, COMMAND, TIME FROM information_schema.PROCESSLIST WHERE USER = " . self::escape_literal( $runtime_user ),
			'raw_sql'        => "SELECT ID, USER, HOST, DB, COMMAND, TIME FROM information_schema.PROCESSLIST WHERE USER = " . self::escape_literal( $runtime_user ),
			'statements'     => array(
				'-- PRECHECK before ALTER USER: global PROCESS plus global other-user KILL authority; mysql.user must contain exactly one row with this username and requested host',
				"SELECT ID, USER, HOST, DB, COMMAND, TIME FROM information_schema.PROCESSLIST WHERE USER = " . self::escape_literal( $runtime_user ),
				'-- PROCESSLIST.HOST is the client origin, not the account host. Apply KILLs only after unique-account precheck and verifies zero remaining.',
			),
			'raw_statements' => array(
				'-- PRECHECK before ALTER USER: global PROCESS plus global other-user KILL authority; mysql.user must contain exactly one row with this username and requested host',
				"SELECT ID, USER, HOST, DB, COMMAND, TIME FROM information_schema.PROCESSLIST WHERE USER = " . self::escape_literal( $runtime_user ),
				'-- PROCESSLIST.HOST is the client origin, not the account host. Apply KILLs only after unique-account precheck and verifies zero remaining.',
			),
			'grant_delta'    => 'SESSION_DRAIN',
			'reversible'     => false,
		);
	}

	/**
	 * Render plan to clean up all CommitCap infrastructure, triggers, and runtime account.
	 * Application tables and rows are NEVER dropped. Foreign-bodied CommitCap-named
	 * objects are refused, not overwritten or destroyed.
	 *
	 * @param string $schema Database schema name.
	 * @param string $runtime_user Restricted DB username.
	 * @param string $runtime_host Host restriction.
	 * @param array<int, string>|array<string, int> $targets Active targets to clean up triggers for.
	 * @return self
	 */
	public static function uninstall(
		string $schema,
		string $runtime_user,
		string $runtime_host = 'localhost',
		array $targets = array()
	): self {
		$schema       = self::validate_identifier( $schema, 'schema' );
		$runtime_user = self::validate_user( $runtime_user );
		$runtime_host = self::validate_host( $runtime_host );
		$user_id      = "'$runtime_user'@'$runtime_host'";

		$steps = array();

		// Drop triggers on all active target tables
		foreach ( $targets as $key => $val ) {
			$tname = is_string( $key ) ? $key : $val;
			$table = Update_Engine::table( $tname );
			$trig  = Update_Engine::trigger_name( $table );
			$sql_drop = "DROP TRIGGER IF EXISTS `$schema`.`$trig`";
			$steps[] = array(
				'id'              => "drop_trigger_{$table}",
				'kind'            => 'DDL',
				'description'     => "Drop trigger `$schema`.`$trig` on `$table` (application table and data rows survive)",
				'sql'             => $sql_drop,
				'raw_sql'         => $sql_drop,
				'statements'      => array( $sql_drop ),
				'raw_statements'  => array( $sql_drop ),
				'grant_delta'     => 'NONE',
				'reversible'      => false,
				'drops_app_table' => false,
			);
		}

		// Drop the reviewed DEFINER routines
		foreach ( Update_Engine::routine_names() as $rname ) {
			$sql_drop_r = "DROP PROCEDURE IF EXISTS `$schema`.`$rname`";
			$steps[] = array(
				'id'             => "drop_routine_$rname",
				'kind'           => 'DDL',
				'description'    => "Drop CommitCap routine `$schema`.`$rname`",
				'sql'            => $sql_drop_r,
				'raw_sql'        => $sql_drop_r,
				'statements'     => array( $sql_drop_r ),
				'raw_statements' => array( $sql_drop_r ),
				'grant_delta'    => 'NONE',
				'reversible'     => false,
			);
		}

		// Drop helper state table
		$state_table = Update_Engine::STATE;
		$sql_drop_st = "DROP TABLE IF EXISTS `$schema`.`$state_table`";
		$steps[]     = array(
			'id'              => 'drop_helper_table',
			'kind'            => 'DDL',
			'description'     => "Drop CommitCap helper table `$schema`.`$state_table`",
			'sql'             => $sql_drop_st,
			'raw_sql'         => $sql_drop_st,
			'statements'      => array( $sql_drop_st ),
			'raw_statements'  => array( $sql_drop_st ),
			'grant_delta'     => 'NONE',
			'reversible'      => false,
			'drops_app_table' => false,
		);

		// Revoke all remaining privileges from runtime user
		$sql_rev_all = "REVOKE ALL PRIVILEGES, GRANT OPTION FROM $user_id";
		$steps[] = array(
			'id'             => 'revoke_all_runtime_grants',
			'kind'           => 'DCL',
			'description'    => "Revoke all privileges from $user_id",
			'sql'            => $sql_rev_all,
			'raw_sql'        => $sql_rev_all,
			'statements'     => array( $sql_rev_all ),
			'raw_statements' => array( $sql_rev_all ),
			'grant_delta'    => '-ALL',
			'reversible'     => false,
			'tolerate_errno' => array( 1141 ),
		);

		// Drop runtime user
		$sql_drop_u = "DROP USER IF EXISTS $user_id";
		$steps[] = array(
			'id'             => 'drop_runtime_user',
			'kind'           => 'DCL',
			'description'    => "Drop runtime user $user_id",
			'sql'            => $sql_drop_u,
			'raw_sql'        => $sql_drop_u,
			'statements'     => array( $sql_drop_u ),
			'raw_statements' => array( $sql_drop_u ),
			'grant_delta'    => '-USER',
			'reversible'     => false,
		);

		return new self(
			self::ACTION_UNINSTALL,
			array(
				'schema'       => $schema,
				'runtime_user' => $runtime_user,
				'runtime_host' => $runtime_host,
				'targets'      => $targets,
			),
			$steps
		);
	}

	public function get_action(): string {
		return $this->action;
	}

	public function get_params(): array {
		return $this->params;
	}

	/**
	 * Return reviewable step definitions.
	 *
	 * @param bool $redact_secrets True to mask raw secret passwords.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_steps( bool $redact_secrets = true ): array {
		if ( ! $redact_secrets ) {
			return $this->steps;
		}
		$safe = array();
		foreach ( $this->steps as $step ) {
			$copy                  = $step;
			$copy['raw_sql']        = $copy['sql'];
			$copy['raw_statements'] = $copy['statements'];
			$safe[]                = $copy;
		}
		return $safe;
	}

	/**
	 * Render inspectable, executable SQL plan with comments and instructions.
	 *
	 * @param bool $redact_secrets True to mask raw secret passwords.
	 * @return string Formatted SQL script.
	 */
	public function render_sql( bool $redact_secrets = true ): string {
		$out = array();
		$out[] = "-- ===========================================================================";
		$out[] = "-- CommitCap for WordPress Provisioning Plan";
		$out[] = "-- Action: " . strtoupper( $this->action );
		$out[] = "-- Generated: " . gmdate( 'Y-m-d H:i:s' ) . " UTC";
		$out[] = "-- Notice: Execute as trusted database administrator/installer only.";
		$out[] = "-- Never provide installer credentials to normal web requests.";
		$out[] = "-- Application tables and existing data rows are never dropped.";
		if ( in_array( $this->action, array( self::ACTION_ROTATE_CREDENTIAL, self::ACTION_DRAIN ), true ) ) {
			$out[] = "-- Apply uses trusted helper-state DML: reserve connection_id=0 for a durable ROTATED_UNSAFE marker before ALTER; clear it only after zero-session drain verification.";
			$out[] = "-- This SQL-only rendering is NOT a substitute for apply(): it does not implement account-collision/privilege preflight, marker state, KILL loop or verification.";
		}
		$out[] = "-- ===========================================================================";
		$out[] = "";

		$step_num = 1;
		foreach ( $this->steps as $step ) {
			$out[] = sprintf( "-- Step %d: %s [%s] (%s)", $step_num, $step['description'], $step['kind'], $step['grant_delta'] );
			$statements = $redact_secrets ? $step['statements'] : $step['raw_statements'];
			foreach ( $statements as $st ) {
				$t = trim( $st );
				if ( '' !== $t ) {
					$out[] = $t . ';';
				}
			}
			$out[] = "";
			$step_num++;
		}

		return implode( "\n", $out );
	}

	/**
	 * Render exact snippet for wp-config.php configuration.
	 */
	public static function render_wp_config_snippet(
		string $runtime_user,
		string $runtime_host,
		string $database,
		?string $secret = null
	): string {
		$sec = null !== $secret ? self::escape_literal( $secret ) : "'<STRONG_GENERATED_PASSWORD_HERE>'";
		return implode( "\n", array(
			"// ---------------------------------------------------------------------------",
			"// CommitCap Shared Restricted Runtime Database Configuration",
			"// ---------------------------------------------------------------------------",
			"define( 'COMMITCAP_DB_USER', " . self::escape_literal( $runtime_user ) . " );",
			"define( 'COMMITCAP_DB_PASSWORD', " . $sec . " );",
			"define( 'COMMITCAP_DB_HOST', " . self::escape_literal( $runtime_host ) . " );",
			"define( 'COMMITCAP_DB_NAME', " . self::escape_literal( $database ) . " );",
		) );
	}

	/**
	 * Execute the plan using a trusted installer connection with conflict checks.
	 *
	 * @param \wpdb $installer Trusted installer connection with administrative privileges.
	 * @param string|null $raw_secret Override secret if plan was rendered redacted.
	 * @return array{success: bool, state: string, executed_steps: int, details: array<int, string>}
	 */
	public function apply( \wpdb $installer, ?string $raw_secret = null ): array {
		if ( ! $installer->ready || ! $installer->dbh instanceof \mysqli ) {
			throw new \RuntimeException( 'Trusted installer connection is not ready.' );
		}

		$secret = $raw_secret ?? $this->raw_secret;
		$engine = new Update_Engine( $installer );

		// Conflict refusal runs before any DDL/DCL so a foreign object is never
		// overwritten merely because a CommitCap name collides.
		$this->assert_preconditions( $engine, $installer );

		$details  = array();
		$executed = 0;

		$rotated = false;
		$marked = false;
		try {
		foreach ( $this->steps as $step ) {
			if ( 'DRAIN' === $step['kind'] ) {
				$this->drain_sessions( $installer, (string) $this->params['runtime_user'], (string) $this->params['runtime_host'] );
				self::mark_rotation( $installer, (string) $this->params['runtime_user'], (string) $this->params['runtime_host'], false );
				$executed++;
				$details[] = sprintf( "Executed [%s] %s", $step['kind'], $step['description'] );
				continue;
			}

			foreach ( $step['raw_statements'] as $query ) {
				if ( self::ACTION_ROTATE_CREDENTIAL === $this->action && 'alter_user_password' === $step['id'] && ! $marked ) {
					self::mark_rotation( $installer, (string) $this->params['runtime_user'], (string) $this->params['runtime_host'], true );
					$marked = true;
				}
				$query = $this->substitute_secret( $query, $secret );

				$query = trim( $query );
				if ( '' === $query ) {
					continue;
				}

				// Credential statements must never reach host error logging.
				$was_shown = false;
				if ( 'DCL' === $step['kind'] ) {
					$was_shown = (bool) $installer->suppress_errors( true );
				}
				try {
					$res = $installer->query( $query );
				} finally {
					if ( 'DCL' === $step['kind'] ) {
						$installer->suppress_errors( ! $was_shown );
					}
				}
				if ( false === $res ) {
					$errno = $installer->dbh instanceof \mysqli ? (int) $installer->dbh->errno : 0;
					if ( isset( $step['tolerate_errno'] ) && in_array( $errno, (array) $step['tolerate_errno'], true ) ) {
						continue;
					}
					throw new \RuntimeException( $this->failure_message( $step, (string) $installer->last_error, $query, $secret ) );
				}
			}

			$executed++;
			$details[] = sprintf( "Executed [%s] %s", $step['kind'], $step['description'] );
			if ( self::ACTION_ROTATE_CREDENTIAL === $this->action && 'alter_user_password' === $step['id'] ) {
				$rotated = true;
			}
		}
		} catch ( \Throwable $error ) {
			if ( $rotated ) {
				throw new \RuntimeException( 'ROTATED_UNSAFE: credential changed but drain was not verified; old authenticated sessions may still exist. Disable protected enablement, retain V2 secret and re-run the standalone drain with a qualified operator during a connection-free maintenance window; verify zero sessions and V2 Doctor/Guard before enabling. Cause: ' . $error->getMessage(), 0, $error );
			}
			if ( $marked ) {
				// ALTER failed: no credentials changed. Clear the preparatory marker;
				// if cleanup fails, keep the safer blocked state and report it.
				self::mark_rotation( $installer, (string) $this->params['runtime_user'], (string) $this->params['runtime_host'], false );
			}
			throw $error;
		}

		return array(
			'success'        => self::ACTION_ROTATE_CREDENTIAL !== $this->action || ! empty( $this->params['drain'] ),
			'state'          => self::ACTION_ROTATE_CREDENTIAL === $this->action && empty( $this->params['drain'] ) ? 'ROTATED_UNSAFE' : ( in_array( $this->action, array( self::ACTION_ROTATE_CREDENTIAL, self::ACTION_DRAIN ), true ) ? 'DRAINED' : 'APPLIED' ),
			'executed_steps' => $executed,
			'details'        => $details,
		);
	}

	/**
	 * Secret-safe failure text. Credential statements and any query containing
	 * the raw secret are withheld entirely; the raw secret is never echoed.
	 */
	private function failure_message( array $step, string $db_error, string $query, ?string $secret ): string {
		$masked_error = ( null !== $secret && '' !== $secret ) ? str_replace( $secret, self::REDACTED_SECRET, $db_error ) : $db_error;
		$message = sprintf(
			"Failed applying step '%s' (%s): %s",
			$step['id'],
			$step['description'],
			$masked_error
		);
		if ( 'DCL' === $step['kind'] || ( null !== $secret && '' !== $secret && false !== strpos( $query, $secret ) ) ) {
			return $message . ' [SQL withheld: credential statement]';
		}
		return $message . ' (SQL: ' . str_replace( self::REDACTED_SECRET, '[REDACTED_SECRET]', $query ) . ')';
	}

	private function substitute_secret( string $query, ?string $secret ): string {
		if ( null !== $secret && false !== strpos( $query, self::REDACTED_SECRET ) ) {
			return str_replace( self::escape_literal( self::REDACTED_SECRET ), self::escape_literal( $secret ), $query );
		}
		return $query;
	}

	/** Pre-flight conflict refusal for the plan action. Never overwrites foreign objects. */
	private function assert_preconditions( Update_Engine $engine, \wpdb $installer ): void {
		switch ( $this->action ) {
			case self::ACTION_INSTALL:
				self::assert_installable_runtime_account( $installer, (string) $this->params['runtime_user'], (string) $this->params['runtime_host'] );
				if ( $this->helper_exists( $installer ) ) {
					$engine->assert_canonical_helper();
				}
				foreach ( Update_Engine::routine_names() as $name ) {
					$engine->assert_canonical_routine( $name );
				}
				$this->assert_managed_user_or_absent( $installer );
				foreach ( (array) $this->params['targets'] as $tbl => $ceiling ) {
					if ( $this->table_exists( $installer, (string) $tbl ) ) {
						$engine->assert_canonical_trigger( (string) $tbl, true, (string) $this->params['runtime_user'] );
					}
				}
				break;
			case self::ACTION_ADD_TARGET:
			case self::ACTION_REMOVE_TARGET:
				self::assert_unique_runtime_account( $installer, (string) $this->params['runtime_user'], (string) $this->params['runtime_host'] );
				if ( $this->table_exists( $installer, (string) $this->params['table'] ) ) {
					$engine->assert_canonical_trigger( (string) $this->params['table'], true, (string) $this->params['runtime_user'] );
				}
				break;
			case self::ACTION_UNINSTALL:
				self::assert_unique_runtime_account( $installer, (string) $this->params['runtime_user'], (string) $this->params['runtime_host'] );
				foreach ( Update_Engine::routine_names() as $name ) {
					$engine->assert_canonical_routine( $name );
				}
				if ( $this->helper_exists( $installer ) ) {
					$engine->assert_canonical_helper();
				}
				foreach ( (array) $this->params['targets'] as $key => $val ) {
					$tbl = is_string( $key ) ? $key : $val;
					if ( $this->table_exists( $installer, (string) $tbl ) ) {
						$engine->assert_canonical_trigger( (string) $tbl, true, (string) $this->params['runtime_user'] );
					}
				}
				$this->assert_managed_user_or_absent( $installer );
				break;
			case self::ACTION_ROTATE_CREDENTIAL:
				if ( ! empty( $this->params['drain'] ) ) {
					self::assert_drain_capability( $installer );
					self::assert_unique_runtime_account( $installer, (string) $this->params['runtime_user'], (string) $this->params['runtime_host'] );
				}
				break;
			case self::ACTION_DRAIN:
				self::assert_drain_capability( $installer );
				self::assert_unique_runtime_account( $installer, (string) $this->params['runtime_user'], (string) $this->params['runtime_host'] );
				break;
		}
	}

	private function helper_exists( \wpdb $installer ): bool {
		$count = $installer->get_var( $installer->prepare(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			Update_Engine::STATE
		) );
		if ( null === $count || '' !== (string) $installer->last_error ) {
			throw new \RuntimeException( 'Unable to inspect the CommitCap helper table; refusing to continue.' );
		}
		return 0 < (int) $count;
	}

	private function table_exists( \wpdb $installer, string $table ): bool {
		$count = $installer->get_var( $installer->prepare(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			Update_Engine::table( $table )
		) );
		if ( null === $count || '' !== (string) $installer->last_error ) {
			throw new \RuntimeException( 'Unable to inspect target table; refusing to continue.' );
		}
		return 0 < (int) $count;
	}

	/**
	 * A pre-existing runtime account may be adopted only when its grants are
	 * exactly the reviewed managed surface (so a partial install can replay) or
	 * when it has no object grants yet. Unknown privileges refuse the plan.
	 */
	private function assert_managed_user_or_absent( \wpdb $installer ): void {
		$user = (string) $this->params['runtime_user'];
		$host = (string) $this->params['runtime_host'];
		$rows = $installer->get_results( $installer->prepare( "SHOW GRANTS FOR '%s'@'%s'", $user, $host ), ARRAY_N );
		if ( ! is_array( $rows ) || '' !== (string) $installer->last_error ) {
			$errno = $installer->dbh instanceof \mysqli ? (int) $installer->dbh->errno : 0;
			if ( in_array( $errno, array( 1141, 1109 ), true ) ) {
				return; // No such user and no such grant: safe to create.
			}
			throw new \RuntimeException( 'Unable to inspect existing runtime account grants; refusing to continue.' );
		}
		$schema = (string) $this->params['schema'];
		foreach ( $rows as $row ) {
			$statement = is_array( $row ) && isset( $row[0] ) ? (string) $row[0] : '';
			if ( ! $this->grant_is_managed( $statement, $schema ) ) {
				throw new \RuntimeException( 'Pre-existing runtime account has grants outside the reviewed surface; refusing to reuse or modify it: ' . $this->redact_grant( $statement ) );
			}
		}
	}

	private function grant_is_managed( string $statement, string $schema ): bool {
		if ( preg_match( '/\AGRANT USAGE ON \*\.\* TO /iD', $statement ) ) {
			return true;
		}
		if ( preg_match( '/\AGRANT SELECT ON `' . preg_quote( str_replace( '`', '``', $schema ), '/' ) . '`\.`commitcap_v01_state` TO /iD', $statement ) ) {
			return true;
		}
		if ( preg_match( '/\AGRANT EXECUTE ON PROCEDURE `' . preg_quote( str_replace( '`', '``', $schema ), '/' ) . '`\.`commitcap_v01_(?:open|close|count|policy|attest)` TO /iD', $statement ) ) {
			return true;
		}
		$targets = array();
		foreach ( (array) $this->params['targets'] as $key => $val ) {
			$targets[] = is_string( $key ) ? $key : (string) $val;
		}
		if ( isset( $this->params['table'] ) ) {
			$targets[] = (string) $this->params['table'];
		}
		foreach ( $targets as $table ) {
			if ( '' === $table ) {
				continue;
			}
			if ( preg_match( '/\AGRANT SELECT, UPDATE ON `' . preg_quote( str_replace( '`', '``', $schema ), '/' ) . '`\.`' . preg_quote( str_replace( '`', '``', Update_Engine::table( $table ) ), '/' ) . '` TO /iD', $statement ) ) {
				return true;
			}
		}
		return false;
	}

	/** Never return a secret hash if a host includes one in SHOW GRANTS. */
	private function redact_grant( string $statement ): string {
		return preg_replace( '/IDENTIFIED BY (?:PASSWORD )?\'[^\']*\'/i', 'IDENTIFIED BY ' . self::REDACTED_SECRET, $statement ) ?? '[unprintable grant]';
	}

	/**
	 * Deterministic supported drain: terminate every remaining session of the
	 * runtime account and verify none remain. Fails closed when the connection
	 * cannot see other sessions or cannot kill them.
	 *
	 * @return array{drained: int, remaining: int}
	 */
	public static function drain_sessions( \wpdb $installer, string $runtime_user, string $runtime_host ): array {
		$runtime_user = self::validate_user( $runtime_user );
		$runtime_host = self::validate_host( $runtime_host );
		self::assert_drain_capability( $installer );
		self::assert_unique_runtime_account( $installer, $runtime_user, $runtime_host );

		$drained = 0;
		for ( $attempt = 0; $attempt < 10; ++$attempt ) {
			self::assert_unique_runtime_account( $installer, $runtime_user, $runtime_host );
			$rows = $installer->get_col( $installer->prepare(
				'SELECT ID FROM information_schema.PROCESSLIST WHERE USER = %s AND ID <> CONNECTION_ID()',
				$runtime_user
			) );
			if ( ! is_array( $rows ) || '' !== (string) $installer->last_error ) {
				throw new \RuntimeException( 'Unable to inspect runtime sessions for draining.' );
			}
			if ( ! $rows ) {
				return array( 'drained' => $drained, 'remaining' => 0 );
			}
			foreach ( $rows as $id ) {
				self::assert_unique_runtime_account( $installer, $runtime_user, $runtime_host );
				$id = (int) $id;
				if ( $id <= 0 ) {
					continue;
				}
				if ( false === $installer->query( 'KILL ' . $id ) ) {
					$errno = $installer->dbh instanceof \mysqli ? (int) $installer->dbh->errno : 0;
					if ( 1094 === $errno ) {
						continue; // Session already gone between inspection and kill.
					}
					throw new \RuntimeException( 'Failed to terminate runtime session; draining is not complete.' );
				}
				$drained++;
			}
			usleep( 50000 );
		}

		self::assert_unique_runtime_account( $installer, $runtime_user, $runtime_host );
		$rows = $installer->get_col( $installer->prepare(
			'SELECT ID FROM information_schema.PROCESSLIST WHERE USER = %s AND ID <> CONNECTION_ID()',
			$runtime_user
		) );
		if ( ! is_array( $rows ) || '' !== (string) $installer->last_error || $rows ) {
			throw new \RuntimeException( 'Runtime sessions remain after draining; draining is not complete.' );
		}
		return array( 'drained' => $drained, 'remaining' => 0 );
	}

	/**
	 * One certified runtime username must map to exactly one mysql.user Host
	 * row. The physical trigger is scoped by username (`USER()` without host),
	 * so a second account with the same username would silently fall into the
	 * same enforcement bucket. PROCESSLIST.HOST is the client endpoint, not the
	 * matched mysql.user.Host.
	 *
	 * @return array<int, string> Host rows for the username (empty when absent).
	 */
	private static function runtime_account_hosts( \wpdb $installer, string $user ): array {
		$rows = $installer->get_col( $installer->prepare( 'SELECT Host FROM mysql.user WHERE User = %s', $user ) );
		if ( ! is_array( $rows ) || '' !== (string) $installer->last_error ) {
			throw new \RuntimeException( 'Unable to inspect the runtime account identity; refusing to continue.' );
		}
		return array_map( 'strval', $rows );
	}

	/** The account must exist exactly once, with the plan's expected host. */
	private static function assert_unique_runtime_account( \wpdb $installer, string $user, string $host ): void {
		$hosts = self::runtime_account_hosts( $installer, $user );
		if ( 1 !== count( $hosts ) || $host !== $hosts[0] ) {
			throw new \RuntimeException( 'Runtime identity is ambiguous: the certified username must have exactly one account row with the expected host; refusing before any policy trigger change.' );
		}
	}

	/** Install/reuse may create the account, but never when the username is ambiguous. */
	private static function assert_installable_runtime_account( \wpdb $installer, string $user, string $host ): void {
		$hosts = self::runtime_account_hosts( $installer, $user );
		if ( array() === $hosts ) {
			return;
		}
		if ( 1 !== count( $hosts ) || $host !== $hosts[0] ) {
			throw new \RuntimeException( 'Runtime identity is ambiguous: the certified username must have exactly one account row with the expected host; refusing before any policy trigger change.' );
		}
	}

	/** Both global visibility and other-user KILL authority are required. */
	public static function assert_drain_capability( \wpdb $installer ): void {
		$rows = $installer->get_results( 'SHOW GRANTS', ARRAY_N );
		if ( ! is_array( $rows ) || ! $rows || '' !== (string) $installer->last_error ) {
			throw new \RuntimeException( 'Cannot read installer grants; session draining cannot be certified.' );
		}
		$version = $installer->get_var( 'SELECT VERSION()' );
		if ( ! is_string( $version ) || '' !== (string) $installer->last_error ||
			! in_array( $version, array( '8.0.44', '10.11.15-MariaDB-ubu2204' ), true ) ) {
			throw new \RuntimeException( 'Drain privilege model is verified only on pinned MySQL 8.0.44 and MariaDB 10.11.15.' );
		}
		$mysql = '8.0.44' === $version;
		$process = false;
		$kill = false;
		foreach ( $rows as $row ) {
			$statement = is_array( $row ) && isset( $row[0] ) ? (string) $row[0] : '';
			if ( ! preg_match( '/\AGRANT (.+?) ON \*\.\* TO /i', $statement, $match ) ) {
				continue;
			}
			$privileges = array_map( 'trim', explode( ',', strtoupper( $match[1] ) ) );
			$all = in_array( 'ALL PRIVILEGES', $privileges, true );
			$process = $process || $all || in_array( 'PROCESS', $privileges, true );
			$kill = $kill || $all || in_array( 'SUPER', $privileges, true ) || in_array( $mysql ? 'CONNECTION_ADMIN' : 'CONNECTION ADMIN', $privileges, true );
		}
		if ( $process && $kill ) {
			return;
		}
		throw new \RuntimeException( 'Draining requires global PROCESS visibility AND global other-user KILL authority (CONNECTION_ADMIN or SUPER on MySQL; CONNECTION ADMIN or SUPER on MariaDB); refusing before credential mutation.' );
	}

	/**
	 * Verify point-in-time runtime readiness using Doctor without installer credentials.
	 *
	 * @param array<string, int|null> $policies Declared policies array( 'table' => budget ).
	 * @param \wpdb $runtime_db Restricted runtime connection.
	 * @return array
	 */
	public static function verify( array $policies, \wpdb $runtime_db ): array {
		return Compatibility_Doctor::runtime( $policies, $runtime_db );
	}
}
