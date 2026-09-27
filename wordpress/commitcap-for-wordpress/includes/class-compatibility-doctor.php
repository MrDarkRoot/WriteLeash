<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Read-only, point-in-time evidence for the cooperative #54/#56 path. */
final class Compatibility_Doctor {
	private $checks = array();

	private function check( string $id, string $status, bool $required, string $summary, string $detail ): void {
		$this->checks[] = array(
			'id' => $id, 'status' => $status, 'required' => $required,
			'summary' => $summary, 'detail' => $detail,
		);
	}

	private function result(): array {
		$overall = 'PASS';
		foreach ( $this->checks as $check ) {
			if ( $check['required'] && 'PASS' !== $check['status'] ) {
				$overall = 'FAIL' === $check['status'] ? 'FAIL' : ( 'PASS' === $overall ? 'UNKNOWN' : $overall );
			}
		}
		return array( 'overall' => $overall, 'checks' => $this->checks );
	}

	/**
	 * Optional table/budget inspects an existing policy; table without budget
	 * inspects readiness for a new policy. A distinct trusted installer connection
	 * is needed to inspect protected object bodies and installation privileges.
	 */
	public static function run( $table = null, $budget = null, ?\wpdb $db = null, ?\wpdb $installer = null ): array {
		$doctor = new self();
		$wp = self::wordpress_status( Environment::wordpress_version() );
		$doctor->check( 'wordpress', $wp[0], true, 'WordPress version', $wp[1] );
		$php = Environment::php_version();
		$doctor->check( 'php', version_compare( $php, '7.4', '>=' ) ? 'PASS' : 'FAIL', true,
			'PHP version', 'PHP ' . $php . '; plugin minimum is 7.4.' );
		$multisite = function_exists( 'is_multisite' ) ? is_multisite() : null;
		$network = false === $multisite ? false : ( function_exists( 'is_plugin_active_for_network' ) ? is_plugin_active_for_network( 'commitcap-for-wordpress/commitcap-for-wordpress.php' ) : null );
		$doctor->check( 'multisite', self::site_status( $multisite, $network ), true,
			'Site activation scope', true === $multisite || true === $network ? 'Multisite/network activation is not validated.' : 'Single-site activation only; network status must be observable.' );
		if ( null === $db ) {
			$db = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		}
		$ready = $db instanceof \wpdb && $db->ready && $db->dbh instanceof \mysqli;
		$doctor->check( 'wpdb', $ready ? 'PASS' : 'FAIL', true,
			'Guard database connection', $ready ? 'Ready mysqli-backed wpdb connection.' : 'A ready mysqli-backed wpdb connection is required.' );
		if ( ! $ready ) {
			foreach ( array(
				'database' => 'Database identity', 'db_user' => 'Authenticated DB identity',
				'schema' => 'Current database', 'transaction' => 'Guard transaction preconditions',
				'connection_state' => 'Connection error state', 'runtime_grants' => 'Runtime privileges',
				'runtime_trigger_surface' => 'Runtime opaque trigger surface',
				'installer_connection' => 'Trusted installer evidence', 'installer_grants' => 'Trusted installation requirements',
				'objects' => 'CommitCap infrastructure',
			) as $id => $summary ) {
				$doctor->check( $id, 'UNKNOWN', true, $summary, 'Cannot inspect without a ready mysqli-backed wpdb.' );
			}
			$doctor->check( 'default_engine', 'UNKNOWN', false, 'Default table engine', 'Cannot inspect without wpdb.' );
			$doctor->check( 'target_table', 'UNKNOWN', null !== $table, 'Target table/policy', 'Cannot inspect without wpdb.' );
			if ( null !== $table ) {
				$doctor->check( 'target_access', 'UNKNOWN', true, 'Runtime target-table rights', 'Cannot inspect without wpdb.' );
			}
			return $doctor->result();
		}

		$initial_error = '' !== (string) $db->last_error;
		$version = $db->get_var( 'SELECT VERSION()' );
		$family = null;
		if ( ! is_string( $version ) || '' === $version || '' !== (string) $db->last_error ) {
			$doctor->check( 'database', 'UNKNOWN', true, 'Database identity', 'SELECT VERSION() failed; exact database version unknown.' );
		} else {
			$classification = self::version_status( $version );
			$family = $classification[2];
			$doctor->check( 'database', $classification[0], true, 'Database identity', $classification[1] );
		}
		$schema = $db->get_var( 'SELECT DATABASE()' );
		$schema = is_string( $schema ) && '' !== $schema && '' === (string) $db->last_error ? $schema : null;
		$current = $db->get_var( 'SELECT CURRENT_USER()' );
		$login = $db->get_var( 'SELECT USER()' );
		$identity = is_string( $current ) && '' !== $current && is_string( $login ) && '' !== $login && '' === (string) $db->last_error;
		$doctor->check( 'db_user', $identity ? 'PASS' : 'UNKNOWN', true, 'Authenticated DB identity',
			$identity ? 'CURRENT_USER(): ' . $current . '; USER(): ' . $login . '. CURRENT_USER() is the matched account whose direct grants are inspected; USER() is the connecting identity.' : 'Could not confirm matched and connecting DB identities.' );
		$doctor->check( 'schema', null === $schema ? 'UNKNOWN' : 'PASS', true, 'Current database',
			null === $schema ? 'Current schema unreadable.' : 'Active schema: ' . $schema . '.' );

		$default = $db->get_var( 'SELECT @@default_storage_engine' );
		$doctor->check( 'default_engine', is_string( $default ) && '' !== $default && '' === (string) $db->last_error ? 'PASS' : 'UNKNOWN', false,
			'Default table engine', is_string( $default ) && '' !== $default ? 'Default: ' . $default . '; only explicitly verified InnoDB target tables are supported.' : 'Default engine unreadable; does not substitute for target-table verification.' );
		$autocommit = $db->get_var( 'SELECT @@autocommit' );
		$state = null;
		if ( '1' === (string) $autocommit && '' === (string) $db->last_error ) {
			try {
				$state = ( new Guard_Transaction( $db ) )->active() ? 'FAIL' : 'PASS';
			} catch ( \Throwable $error ) {
				$state = 'UNKNOWN';
			}
		} elseif ( null !== $autocommit && '' === (string) $db->last_error ) {
			$state = 'FAIL';
		}
		$doctor->check( 'transaction', null === $state ? 'UNKNOWN' : $state, true,
			'Guard transaction preconditions', 'Requires autocommit=1, no pre-existing transaction and exclusive Guard ownership; no transaction was started or ended by the doctor.' );
		$doctor->check( 'connection_state', ! $initial_error && '' === (string) $db->last_error ? 'PASS' : 'FAIL', true,
			'Connection error state', 'Guard requires a clean wpdb error state and a stable mysqli connection for the entire callback.' );

		$runtime_grants = null !== $schema && $identity ? Compatibility_Grants::read( $db, $schema ) : null;
		$runtime = null === $runtime_grants ? array( 'UNKNOWN', 'SHOW GRANTS unavailable, incomplete or contains unexpanded roles/unknown syntax; absence of a grant is not proven.' ) : $runtime_grants->runtime();
		$doctor->check( 'runtime_grants', $runtime[0], true, 'Restricted runtime EXECUTE, helper and DDL boundary', $runtime[1] );

		$trusted = $installer instanceof \wpdb && $installer !== $db && $installer->ready && $installer->dbh instanceof \mysqli;
		if ( $trusted ) {
			$trusted = $schema === $installer->get_var( 'SELECT DATABASE()' ) &&
				$version === $installer->get_var( 'SELECT VERSION()' ) &&
				$db->dbhost === $installer->dbhost &&
				$db->get_var( 'SELECT @@hostname' ) === $installer->get_var( 'SELECT @@hostname' ) &&
				$db->get_var( 'SELECT @@port' ) === $installer->get_var( 'SELECT @@port' ) &&
				'' === (string) $installer->last_error;
		}
		$doctor->check( 'installer_connection', $trusted ? 'PASS' : 'UNKNOWN', true,
			'Trusted installer evidence', $trusted ? 'Distinct trusted connection to the same observed database endpoint.' : 'No independently verified trusted installer connection; object definitions and installer grants cannot be proven.' );
		$install_grants = $trusted ? Compatibility_Grants::read( $installer, $schema ) : null;
		$installer_status = $install_grants ? $install_grants->installer( is_string( $table ) ? $table : null ) : array( 'UNKNOWN', 'Installer grant evidence unavailable or incomplete.' );
		$doctor->check( 'installer_grants', $installer_status[0], true, 'Trusted installation requirements', $installer_status[1] . ' #54 requires CREATE, CREATE ROUTINE and TRIGGER; removal requires TRIGGER, not DROP.' );

		$doctor->trigger_surface( $runtime_grants, is_string( $table ) ? $table : null, $installer, $trusted );

		if ( ! $trusted ) {
			$doctor->check( 'objects', 'UNKNOWN', true, 'CommitCap infrastructure', 'Cannot verify helper shape, routine bodies, signatures and trusted definers from the restricted runtime connection.' );
		} else {
			$engine = new Update_Engine( $installer );
			$present = $installer->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commitcap_v01_state'" );
			$routines = $installer->get_var( "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME IN ('commitcap_v01_open','commitcap_v01_close','commitcap_v01_count','commitcap_v01_policy')" );
			if ( null === $present || null === $routines || '' !== (string) $installer->last_error ) {
				$doctor->check( 'objects', 'UNKNOWN', true, 'CommitCap infrastructure', 'Object inventory unavailable.' );
			} elseif ( '0' === (string) $present && '0' === (string) $routines ) {
				$doctor->check( 'objects', 'FAIL', true, 'CommitCap infrastructure', 'Infrastructure absent; trusted explicit installation is required.' );
			} else {
				try {
					$engine->verify_infrastructure_objects();
					$doctor->check( 'objects', 'PASS', true, 'CommitCap infrastructure', 'Existing helper and all four routines passed the #54 structural verifier.' );
				} catch ( \Throwable $error ) {
					$doctor->check( 'objects', 'FAIL', true, 'CommitCap infrastructure', 'Existing objects conflict with #54 structural verification; nothing was replaced.' );
				}
			}
		}
		if ( null === $table ) {
			$doctor->check( 'target_table', 'UNKNOWN', false, 'Target table/policy', 'No target supplied; inspect the actual table and policy before protecting a job. Explicit ENGINE=InnoDB is required.' );
		} else {
			$access = null === $runtime_grants ? array( 'UNKNOWN', 'Runtime target-table grants could not be proven.' ) : $runtime_grants->target_access( is_string( $table ) ? $table : '' );
			$doctor->check( 'target_access', $access[0], true, 'Runtime target-table rights', $access[1] );
			$doctor->inspect_target( $table, $budget, $db, $trusted ? $installer : null, $family );
		}
		return $doctor->result();
	}

	/** Explicit network/site classification; network activation was refused by #55. */
	public static function site_status( $multisite, $network ): string {
		if ( true === $multisite || true === $network ) {
			return 'FAIL';
		}
		return false === $multisite && false === $network ? 'PASS' : 'UNKNOWN';
	}

	/**
	 * Only the exact integrated fixture (WordPress 6.8.3) is TESTED. Observing
	 * a version string is not compatibility evidence: any other version stays
	 * UNKNOWN until #62 establishes a wider matrix. Untested is not FAIL.
	 */
	public static function wordpress_status( string $version ): array {
		if ( '' === $version ) {
			return array( 'UNKNOWN', 'WordPress version unavailable; required compatibility cannot be established.' );
		}
		if ( '6.8.3' === $version ) {
			return array( 'PASS', 'WordPress ' . $version . ': TESTED exact fixture.' );
		}
		return array( 'UNKNOWN', 'WordPress ' . $version . ': OTHER VERSION BUT UNTESTED; only 6.8.3 is a TESTED exact fixture and no wider supported range is claimed.' );
	}

	/**
	 * Runtime write grants can fire triggers that the lexical Guard monitor
	 * cannot see. TRIGGER authority itself is FAILed by runtime(); a
	 * non-target writable object whose trigger graph is uninspected, or that
	 * currently has any trigger, cannot be certified PASS.
	 */
	private function trigger_surface( ?Compatibility_Grants $grants, ?string $target, ?\wpdb $installer, bool $trusted ): void {
		if ( null === $grants ) {
			$this->check( 'runtime_trigger_surface', 'UNKNOWN', true, 'Runtime opaque trigger surface', 'Runtime grant evidence is unavailable or incomplete; unreviewed trigger surfaces cannot be ruled out.' );
			return;
		}
		list( $ambiguous, $scopes ) = $grants->trigger_write_scopes( $target );
		if ( $ambiguous ) {
			$this->check( 'runtime_trigger_surface', 'UNKNOWN', true, 'Runtime opaque trigger surface', 'Unexpanded roles or pattern grant scopes leave runtime-writable trigger surfaces unprovable.' );
			return;
		}
		if ( ! $scopes ) {
			$this->check( 'runtime_trigger_surface', 'PASS', true, 'Runtime opaque trigger surface', 'No non-target runtime write grant can fire an unreviewed trigger.' );
			return;
		}
		if ( ! $trusted || ! $installer instanceof \wpdb ) {
			$this->check( 'runtime_trigger_surface', 'UNKNOWN', true, 'Runtime opaque trigger surface', 'Non-target runtime write grants exist and no trusted installer evidence can inspect their trigger graph.' );
			return;
		}
		$found = 0;
		foreach ( $scopes as $scope ) {
			if ( '*' === $scope['object'] ) {
				$count = $installer->get_var( $installer->prepare(
					'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = %s', $scope['database']
				) );
			} else {
				$count = $installer->get_var( $installer->prepare(
					'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = %s AND EVENT_OBJECT_TABLE = %s',
					$scope['database'], $scope['object']
				) );
			}
			if ( null === $count || '' !== (string) $installer->last_error ) {
				$this->check( 'runtime_trigger_surface', 'UNKNOWN', true, 'Runtime opaque trigger surface', 'Runtime-writable trigger graph could not be read; opaque server-side execution is not disproven.' );
				return;
			}
			$found += (int) $count;
		}
		$this->check(
			'runtime_trigger_surface',
			$found > 0 ? 'UNKNOWN' : 'PASS',
			true,
			'Runtime opaque trigger surface',
			$found > 0
				? $found . ' unreviewed trigger(s) exist on runtime-writable objects outside the verified target; opaque server-side execution is not proven safe.'
				: 'No triggers exist on non-target runtime-writable objects.'
		);
	}

	/** #54 defines which server strings are admissible; this labels exact evidence. */
	public static function version_status( string $version ): array {
		try {
			$family = Update_Engine::target_family( $version );
			$tested = 'MySQL' === $family ? '8.0.44' === $version : '10.11.15-MariaDB-ubu2204' === $version;
			return array( 'PASS', $family . ' ' . $version . ': ' . ( $tested ? 'TESTED exact fixture.' : 'WITHIN TARGET RANGE BUT UNTESTED exact version.' ), $family );
		} catch ( \Throwable $error ) {
			return array( 'FAIL', 'UNSUPPORTED or unrecognized database family/version: ' . $version . '.', null );
		}
	}

	private function inspect_target( $table, $budget, \wpdb $db, ?\wpdb $installer, ?string $family ): void {
		try {
			$name = Update_Engine::table( $table );
			if ( null !== $budget ) {
				Update_Engine::budget( $budget );
			}
		} catch ( \Throwable $error ) {
			$this->check( 'target_table', 'FAIL', true, 'Target table/policy', 'Invalid target table identifier or budget.' );
			return;
		}
		$reader = $installer ?: $db;
		$rows = $reader->get_results( $reader->prepare(
			'SELECT TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $name
		) );
		if ( null === $rows || '' !== (string) $reader->last_error ) {
			$this->check( 'target_table', 'UNKNOWN', true, 'Target table/policy', 'Target table engine could not be read.' );
			return;
		}
		if ( 1 !== count( $rows ) ) {
			$this->check( 'target_table', 'UNKNOWN', true, 'Target table/policy', 'Target table absent or not visible; cannot establish its engine.' );
			return;
		}
		if ( 'BASE TABLE' !== $rows[0]->TABLE_TYPE || 'InnoDB' !== $rows[0]->ENGINE ) {
			$this->check( 'target_table', 'FAIL', true, 'Target table/policy', 'UNSUPPORTED target: only ordinary InnoDB tables are supported; no engine was changed.' );
			return;
		}
		if ( null === $installer || null === $family ) {
			$this->check( 'target_table', 'UNKNOWN', true, 'Target table/policy', 'InnoDB observed; trusted trigger, FK, partition and policy verification unavailable.' );
			return;
		}
		try {
			$engine = new Update_Engine( $installer );
			$info = $engine->inspect_table( $name );
			if ( null === $budget && $info['triggers'] ) {
				$expected = 'commitcap_v01_' . substr( hash( 'sha256', $name ), 0, 16 );
				$status = 1 === count( $info['triggers'] ) && $expected === $info['triggers'][0]->TRIGGER_NAME ? 'UNKNOWN' : 'FAIL';
				$this->check( 'target_table', $status, true, 'Target table/policy', 'Existing trigger or policy; provide the expected budget to verify an installed policy. Unrelated triggers conflict.' );
				return;
			}
			if ( null !== $budget ) {
				$engine->verify_policy( $name, $budget );
				( new Update_Engine( $db ) )->verify_runtime_policy( $name, $budget );
			}
			$this->check( 'target_table', 'PASS', true, 'Target table/policy', null === $budget
				? 'Ordinary nonpartitioned InnoDB table without FK relationships or trigger conflicts; ready for trusted policy installation.'
				: 'Existing target policy passed both #54 trusted structural and restricted runtime verification.' );
		} catch ( \Throwable $error ) {
			$this->check( 'target_table', 'FAIL', true, 'Target table/policy', 'Unsupported table shape or malformed, conflicting or absent target policy.' );
		}
	}
}
