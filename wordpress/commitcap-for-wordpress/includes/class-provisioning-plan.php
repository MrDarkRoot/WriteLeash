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
 * credentials in normal web PHP.
 */
final class Provisioning_Plan {
	public const ACTION_INSTALL           = 'install';
	public const ACTION_ADD_TARGET        = 'add_target';
	public const ACTION_REMOVE_TARGET     = 'remove_target';
	public const ACTION_ROTATE_CREDENTIAL = 'rotate_credential';
	public const ACTION_UNINSTALL         = 'uninstall';

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
		if ( ! is_string( $user ) || strlen( $user ) > 32 ||
			! preg_match( '/\A[A-Za-z0-9_.\-]+\z/D', $user ) ) {
			throw new \InvalidArgumentException( 'Invalid database username.' );
		}
		return $user;
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

		// Steps 3-6: Four routines with SQL SECURITY DEFINER
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

		// Step 7: Revoke all accidental or inherited broad privileges from runtime user
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
		);

		// Steps 8-11: Grant EXECUTE on exactly the four routines
		foreach ( array_keys( Update_Engine::routines() ) as $rname ) {
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
		$trigger_body = Update_Engine::trigger_body( $table_name, $ceiling );

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
	 * Render plan to rotate runtime credentials.
	 *
	 * @param string $runtime_user Restricted DB username.
	 * @param string $runtime_host Host restriction.
	 * @param string|null $new_secret New password. Redacted in plan output.
	 * @return self
	 */
	public static function rotate_credential(
		string $runtime_user,
		string $runtime_host = 'localhost',
		?string $new_secret = null
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

		return new self(
			self::ACTION_ROTATE_CREDENTIAL,
			array(
				'runtime_user' => $runtime_user,
				'runtime_host' => $runtime_host,
			),
			$steps,
			$new_secret
		);
	}

	/**
	 * Render plan to clean up all CommitCap infrastructure, triggers, and runtime account.
	 * Application tables and rows are NEVER dropped.
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

		// Drop the four DEFINER routines
		foreach ( array_keys( Update_Engine::routines() ) as $rname ) {
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
	 * @return array{success: bool, executed_steps: int, details: array<int, string>}
	 */
	public function apply( \wpdb $installer, ?string $raw_secret = null ): array {
		if ( ! $installer->ready || ! $installer->dbh instanceof \mysqli ) {
			throw new \RuntimeException( 'Trusted installer connection is not ready.' );
		}

		$secret = $raw_secret ?? $this->raw_secret;

		// Conflict refusal for add_target: verify target table exists, is ordinary InnoDB,
		// and has NO conflicting unowned trigger.
		if ( self::ACTION_ADD_TARGET === $this->action ) {
			$table = $this->params['table'];
			$engine = new Update_Engine( $installer );
			$info = $engine->inspect_table( $table );
			$expected_trigger = Update_Engine::trigger_name( $table );
			foreach ( $info['triggers'] as $trig ) {
				if ( $trig->TRIGGER_NAME !== $expected_trigger ) {
					throw new \RuntimeException( "Refusing add_target: table $table has existing unreviewed trigger: " . $trig->TRIGGER_NAME );
				}
			}
		}

		$details = array();
		$executed = 0;

		foreach ( $this->steps as $step ) {
			$statements = $step['raw_statements'];

			foreach ( $statements as $query ) {
				if ( null !== $secret && false !== strpos( $query, self::REDACTED_SECRET ) ) {
					$query = str_replace( self::escape_literal( self::REDACTED_SECRET ), self::escape_literal( $secret ), $query );
				}

				$query = trim( $query );
				if ( '' === $query ) {
					continue;
				}

				$res = $installer->query( $query );
				if ( false === $res ) {
					throw new \RuntimeException( sprintf(
						"Failed applying step '%s' (%s): %s (SQL: %s)",
						$step['id'],
						$step['description'],
						$installer->last_error,
						$query
					) );
				}
			}

			$executed++;
			$details[] = sprintf( "Executed [%s] %s", $step['kind'], $step['description'] );
		}

		return array(
			'success'        => true,
			'executed_steps' => $executed,
			'details'        => $details,
		);
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
