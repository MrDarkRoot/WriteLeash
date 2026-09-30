<?php
namespace WriteLeash;

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
	public static function run( $table = null, $budget = null, ?\wpdb $db = null, ?\wpdb $installer = null, array $known_policies = array() ): array {
		$doctor = new self();
		$wp = self::wordpress_status( Environment::wordpress_version() );
		$doctor->check( 'wordpress', $wp[0], true, 'WordPress version', $wp[1] );
		$php = Environment::php_version();
		$doctor->check( 'php', version_compare( $php, '7.4', '>=' ) ? 'PASS' : 'FAIL', true,
			'PHP version', 'PHP ' . $php . '; plugin minimum is 7.4.' );
		$multisite = function_exists( 'is_multisite' ) ? is_multisite() : null;
		$network = false === $multisite ? false : ( function_exists( 'is_plugin_active_for_network' ) ? is_plugin_active_for_network( 'writeleash/writeleash.php' ) : null );
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
				'objects' => 'WriteLeash infrastructure',
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
		try {
			$rotation_safe = ! ( new Update_Engine( $db ) )->rotation_unsafe();
			$doctor->check( 'rotation_drain', $rotation_safe ? 'PASS' : 'FAIL', true, 'Credential rotation drain',
				$rotation_safe ? 'No trusted unsafe-rotation marker exists.' : 'ROTATED_UNSAFE: drain not verified; do not enable protected operations.' );
		} catch ( \Throwable $error ) {
			$doctor->check( 'rotation_drain', 'UNKNOWN', true, 'Credential rotation drain', 'Cannot inspect trusted rotation safety marker.' );
		}

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

		$known_targets = is_string( $table ) ? array_merge( array( $table ), array_keys( $known_policies ) ) : ( $known_policies ? array_keys( $known_policies ) : null );
		$doctor->trigger_surface( $runtime_grants, $known_targets, $installer, $trusted );

		if ( ! $trusted ) {
			$doctor->check( 'objects', 'UNKNOWN', true, 'WriteLeash infrastructure', 'Cannot verify helper shape, routine bodies, signatures and trusted definers from the restricted runtime connection.' );
		} else {
			$engine = new Update_Engine( $installer );
			$present = $installer->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'writeleash_v01_state'" );
			$routines = $installer->get_var( "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME IN ('writeleash_v01_open','writeleash_v01_close','writeleash_v01_count','writeleash_v01_policy','writeleash_v01_attest')" );
			if ( null === $present || null === $routines || '' !== (string) $installer->last_error ) {
				$doctor->check( 'objects', 'UNKNOWN', true, 'WriteLeash infrastructure', 'Object inventory unavailable.' );
			} elseif ( '0' === (string) $present && '0' === (string) $routines ) {
				$doctor->check( 'objects', 'FAIL', true, 'WriteLeash infrastructure', 'Infrastructure absent; trusted explicit installation is required.' );
			} else {
				try {
					$engine->verify_infrastructure_objects();
					$doctor->check( 'objects', 'PASS', true, 'WriteLeash infrastructure', 'Existing helper and all five routines passed the #54 structural verifier.' );
				} catch ( \Throwable $error ) {
					$doctor->check( 'objects', 'FAIL', true, 'WriteLeash infrastructure', 'Existing objects conflict with #54 structural verification; nothing was replaced.' );
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
		$result = $doctor->result();
		if ( null !== $table ) {
			$tbl_check = null;
			foreach ( $doctor->checks as $c ) {
				if ( 'target_table' === $c['id'] ) {
					$tbl_check = $c;
					break;
				}
			}
			$result['integrations'] = array(
				(string) $table => array(
					'status' => null !== $tbl_check ? $tbl_check['status'] : 'UNKNOWN',
					'physical_ceiling' => null,
					'logical_budget' => $budget,
					'summary' => null !== $tbl_check ? $tbl_check['summary'] : '',
					'detail' => null !== $tbl_check ? $tbl_check['detail'] : '',
				),
			);
		} else {
			$result['integrations'] = array();
		}
		return $result;
	}

	/**
	 * Shared-runtime point-in-time verification without retained installer credentials.
	 *
	 * Inspects environment, restricted runtime account grants, infrastructure definitions
	 * and DEFINERs via the trusted DEFINER routine, policy ceilings, sibling reachability,
	 * and every non-target writable trigger surface.
	 *
	 * @param string|array<string, int|null>|array<int, string> $policies Known code integration policies.
	 * @param \wpdb|null $db Restricted runtime wpdb connection.
	 * @param array<int, string>|null $probe_targets Optional explicitly selected
	 *                     behavioral probe tables; null retains the existing
	 *                     all-known-policies behavior. Other known policies still
	 *                     undergo canonical metadata and grant verification.
	 * @return array{overall: string, checks: array, integrations: array<string, array>}
	 */
	public static function runtime( $policies, ?\wpdb $db = null, ?array $probe_targets = null ): array {
		if ( is_string( $policies ) ) {
			$normalized = array( $policies => null );
		} elseif ( is_array( $policies ) ) {
			$normalized = array();
			foreach ( $policies as $key => $val ) {
				if ( is_int( $key ) && is_string( $val ) ) {
					$normalized[ $val ] = null;
				} elseif ( is_string( $key ) ) {
					$normalized[ $key ] = is_array( $val ) && isset( $val['budget'] ) ? $val['budget'] : $val;
				}
			}
		} else {
			throw new \InvalidArgumentException( 'Expected policy table name or array of policy tables.' );
		}

		$doctor = new self();
		$wp = self::wordpress_status( Environment::wordpress_version() );
		$doctor->check( 'wordpress', $wp[0], true, 'WordPress version', $wp[1] );
		$php = Environment::php_version();
		$doctor->check( 'php', version_compare( $php, '7.4', '>=' ) ? 'PASS' : 'FAIL', true,
			'PHP version', 'PHP ' . $php . '; plugin minimum is 7.4.' );
		$multisite = function_exists( 'is_multisite' ) ? is_multisite() : null;
		$network = false === $multisite ? false : ( function_exists( 'is_plugin_active_for_network' ) ? is_plugin_active_for_network( 'writeleash/writeleash.php' ) : null );
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
				'evidence_channel' => 'Trusted runtime evidence',
				'objects' => 'WriteLeash infrastructure',
			) as $id => $summary ) {
				$doctor->check( $id, 'UNKNOWN', true, $summary, 'Cannot inspect without a ready mysqli-backed wpdb.' );
			}
			$doctor->check( 'default_engine', 'UNKNOWN', false, 'Default table engine', 'Cannot inspect without wpdb.' );
			$integrations = array();
			foreach ( $normalized as $name => $budget ) {
				$integrations[ $name ] = array(
					'status' => 'UNKNOWN',
					'physical_ceiling' => null,
					'logical_budget' => $budget,
					'summary' => 'Cannot inspect without ready wpdb.',
					'detail' => 'Cannot inspect target table without a ready mysqli-backed wpdb.',
				);
			}
			return array( 'overall' => 'FAIL', 'checks' => $doctor->checks, 'integrations' => $integrations );
		}

		$initial_error = '' !== (string) $db->last_error;
		$version = $db->get_var( 'SELECT VERSION()' );
		if ( ! is_string( $version ) || '' === $version || '' !== (string) $db->last_error ) {
			$doctor->check( 'database', 'UNKNOWN', true, 'Database identity', 'SELECT VERSION() failed; exact database version unknown.' );
		} else {
			$classification = self::version_status( $version );
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

		$engine = new Update_Engine( $db );
		$infrastructure_ok = true;
		$rotation_safe = false;
		try {
			$rotation_safe = ! $engine->rotation_unsafe();
			$doctor->check( 'rotation_drain', $rotation_safe ? 'PASS' : 'FAIL', true, 'Credential rotation drain',
				$rotation_safe ? 'No trusted unsafe-rotation marker exists.' : 'ROTATED_UNSAFE: old authenticated sessions may still exist; trusted standalone drain and V2 verification required before protected enablement.' );
		} catch ( \Throwable $error ) {
			$doctor->check( 'rotation_drain', 'UNKNOWN', true, 'Credential rotation drain', 'Cannot read trusted rotation safety marker; no protected READY claim.' );
		}

		// Evidence root: two SQL SECURITY DEFINER routines cross-report the live
		// body of all five reviewed routines; unmediated information_schema
		// metadata for the same routines is cross-checked by the runtime account.
		try {
			$attestation = $engine->runtime_attestation();
			$doctor->check( 'evidence_channel', 'PASS', true, 'Trusted runtime evidence',
				'Both DEFINER evidence routines cross-report the live bodies of all five reviewed routines; all bodies match the reviewed canonical templates and share DEFINER ' . $attestation['definer'] . '.' );
		} catch ( \Throwable $error ) {
			$infrastructure_ok = false;
			$doctor->check( 'evidence_channel', 'FAIL', true, 'Trusted runtime evidence',
				'Runtime evidence does not match the reviewed canonical installation: ' . $error->getMessage() );
		}

		$probes_allowed = 'PASS' === $state;
		try {
			if ( ! $infrastructure_ok ) {
				throw new \RuntimeException( 'The evidence channel is not canonical; infrastructure cannot be certified.' );
			}
			$engine->runtime_helper_shape();
			if ( ! $probes_allowed ) {
				$doctor->check( 'objects', 'UNKNOWN', true, 'WriteLeash infrastructure',
					'Helper shape and cross-attested routines passed, but behavioral probes require autocommit=1 and no caller transaction.' );
			} else {
				$engine->runtime_probe_routines();
				$doctor->check( 'objects', 'PASS', true, 'WriteLeash infrastructure',
					'Helper shape, five cross-attested routines and behavioral open/count/close probes passed without installer credentials.' );
			}
		} catch ( \Throwable $error ) {
			$infrastructure_ok = false;
			$doctor->check( 'objects', 'FAIL', true, 'WriteLeash infrastructure',
				'Runtime infrastructure objects absent, conflicting or altered: ' . $error->getMessage() );
		}

		// Foreign trigger surface check
		$known_tables = array_keys( $normalized );
		list( $ambiguous, $scopes ) = null !== $runtime_grants ? $runtime_grants->trigger_write_scopes( $known_tables ) : array( true, array() );
		if ( null === $runtime_grants || $ambiguous ) {
			$doctor->check( 'runtime_trigger_surface', 'UNKNOWN', true, 'Runtime opaque trigger surface',
				'Runtime grant evidence is unavailable or ambiguous; unreviewed trigger surfaces cannot be ruled out.' );
		} elseif ( ! $scopes ) {
			$doctor->check( 'runtime_trigger_surface', 'PASS', true, 'Runtime opaque trigger surface',
				'No non-target runtime write grant can fire an unreviewed trigger.' );
		} else {
			$foreign_triggers = 0;
			$inspection_failed = false;
			foreach ( $scopes as $scope ) {
				$tbl_id = '*' === $scope['object'] ? $scope['database'] : ( $scope['database'] === $schema ? $scope['object'] : $scope['database'] . '.' . $scope['object'] );
				try {
					$count = $engine->runtime_table_triggers( $tbl_id );
					$foreign_triggers += $count;
				} catch ( \Throwable $error ) {
					$inspection_failed = true;
					break;
				}
			}
			if ( $inspection_failed ) {
				$doctor->check( 'runtime_trigger_surface', 'UNKNOWN', true, 'Runtime opaque trigger surface',
					'Runtime-writable trigger graph could not be read; opaque server-side execution is not disproven.' );
			} elseif ( $foreign_triggers > 0 ) {
				$doctor->check( 'runtime_trigger_surface', 'UNKNOWN', true, 'Runtime opaque trigger surface',
					$foreign_triggers . ' unreviewed trigger(s) exist on foreign runtime-writable object(s); opaque server-side execution is not proven safe.' );
			} else {
				$doctor->check( 'runtime_trigger_surface', 'PASS', true, 'Runtime opaque trigger surface',
					'No triggers exist on foreign runtime-writable objects.' );
			}
		}

		// Target policies verification
		$integrations = array();
		$corrupted = array();
		foreach ( $normalized as $table => $budget ) {
			try {
				$tname = Update_Engine::table( $table );
			} catch ( \Throwable $error ) {
				$integrations[ $table ] = array(
					'status' => 'FAIL',
					'physical_ceiling' => null,
					'logical_budget' => $budget,
					'summary' => 'Invalid table identifier',
					'detail' => $error->getMessage(),
				);
				$corrupted[] = $table;
				continue;
			}

			$access = null !== $runtime_grants ? $runtime_grants->target_access( $tname ) : array( 'UNKNOWN', 'Grants unavailable.' );
			if ( 'PASS' !== $access[0] ) {
				$integrations[ $table ] = array(
					'status' => 'FAIL',
					'physical_ceiling' => null,
					'logical_budget' => $budget,
					'summary' => 'Runtime target-table rights',
					'detail' => $access[1],
				);
				$corrupted[] = $table;
				continue;
			}

			try {
				$ceiling = $engine->runtime_ceiling( $tname );
				$probe_note = ' Behavioral trigger accounting probe not run (transaction preconditions, evidence channel or explicit probe selection).';
				if ( $probes_allowed && $infrastructure_ok && ( null === $probe_targets || in_array( $tname, $probe_targets, true ) ) ) {
					$probe_result = $engine->runtime_trigger_probe( $tname );
					$probe_note = 'empty_table' === $probe_result
						? ' Behavioral trigger accounting probe skipped: target table has no row to observe.'
						: ' Behavioral trigger accounting probe confirmed exactly one physical accounting event for one data-preserving no-op row event.';
				}
				if ( null !== $budget ) {
					$limit = Update_Engine::budget( $budget );
					if ( $limit > $ceiling ) {
						$integrations[ $table ] = array(
							'status' => 'FAIL',
							'physical_ceiling' => $ceiling,
							'logical_budget' => $limit,
							'summary' => 'Logical budget exceeds physical ceiling',
							'detail' => "WriteLeash logical budget $limit exceeds installed physical ceiling $ceiling.",
						);
						$corrupted[] = $table;
						continue;
					}
					$integrations[ $table ] = array(
						'status' => 'PASS',
						'physical_ceiling' => $ceiling,
						'logical_budget' => $limit,
						'summary' => 'Policy verified',
						'detail' => "Target policy verified: physical ceiling $ceiling, logical budget $limit.",
					);
				} else {
					$integrations[ $table ] = array(
						'status' => 'PASS',
						'physical_ceiling' => $ceiling,
						'logical_budget' => null,
						'summary' => 'Policy verified',
						'detail' => "Target policy verified: physical ceiling $ceiling.",
					);
				}
				$integrations[ $table ]['detail'] .= $probe_note;
			} catch ( \Throwable $error ) {
				$integrations[ $table ] = array(
					'status' => 'FAIL',
					'physical_ceiling' => null,
					'logical_budget' => $budget,
					'summary' => 'Policy absent or invalid',
					'detail' => $error->getMessage(),
				);
				$corrupted[] = $table;
			}
		}

		// Trigger surface check impacts integration status:
		// If foreign trigger surface is UNKNOWN, no integration can PASS.
		$surface_status = 'PASS';
		foreach ( $doctor->checks as $check ) {
			if ( 'runtime_trigger_surface' === $check['id'] ) {
				$surface_status = $check['status'];
				break;
			}
		}

		if ( ! $rotation_safe ) {
			foreach ( $integrations as $table => $info ) {
				if ( 'PASS' === $info['status'] ) {
					$integrations[ $table ]['status'] = 'UNKNOWN';
					$integrations[ $table ]['detail'] .= ' (Credential rotation drain is unverified.)';
				}
			}
		} elseif ( ! $infrastructure_ok ) {
			foreach ( $integrations as $table => $info ) {
				if ( 'PASS' === $info['status'] ) {
					$integrations[ $table ]['status'] = 'UNKNOWN';
					$integrations[ $table ]['detail'] .= ' (The runtime evidence channel is not canonical; no integration can be certified.)';
				}
			}
		} elseif ( 'PASS' !== $surface_status ) {
			foreach ( $integrations as $table => $info ) {
				if ( 'PASS' === $info['status'] ) {
					$integrations[ $table ]['status'] = 'UNKNOWN';
					$integrations[ $table ]['detail'] .= ' (Unreviewed foreign trigger surface prevents certification.)';
				}
			}
		} elseif ( $corrupted ) {
			// Shared Reachability Invariant:
			// If any sibling integration on the shared connection is corrupted or failing,
			// all valid sibling integrations must become UNKNOWN because writes to the corrupted
			// sibling could bypass or corrupt the shared runtime connection.
			foreach ( $integrations as $table => $info ) {
				if ( 'PASS' === $info['status'] ) {
					$integrations[ $table ]['status'] = 'UNKNOWN';
					$integrations[ $table ]['detail'] .= ' (Shared runtime is compromised by corrupted sibling integration: ' . implode( ', ', $corrupted ) . '; shared reachability prevents certification.)';
				}
			}
		}

		// Calculate overall status
		$base_result = $doctor->result();
		$overall = $base_result['overall'];
		if ( 'PASS' === $overall ) {
			$integ_statuses = array_column( $integrations, 'status' );
			if ( ! $integ_statuses ) {
				$overall = 'PASS';
			} elseif ( array_fill( 0, count( $integ_statuses ), 'PASS' ) === $integ_statuses ) {
				$overall = 'PASS';
			} elseif ( array_fill( 0, count( $integ_statuses ), 'FAIL' ) === $integ_statuses ) {
				$overall = 'FAIL';
			} elseif ( in_array( 'FAIL', $integ_statuses, true ) || in_array( 'UNKNOWN', $integ_statuses, true ) ) {
				$overall = 'DEGRADED';
			}
		}

		return array(
			'overall' => $overall,
			'checks' => $doctor->checks,
			'integrations' => $integrations,
		);
	}

	/** Explicit network/site classification; network activation was refused by #55. */
	public static function site_status( $multisite, $network ): string {
		if ( true === $multisite || true === $network ) {
			return 'FAIL';
		}
		return false === $multisite && false === $network ? 'PASS' : 'UNKNOWN';
	}

	/**
	 * Only exact WordPress core builds exercised by the release matrix are
	 * TESTED: the #62 baseline fixture and the current-stable compatibility
	 * fixture. Observing a version string is not compatibility evidence; every
	 * other build (including untested minors in between) stays UNKNOWN and
	 * therefore NOT_READY. Untested is not FAIL.
	 */
	public const TESTED_WORDPRESS_VERSIONS = array( '6.8.3', '7.1.2' );

	public static function wordpress_status( string $version ): array {
		if ( '' === $version ) {
			return array( 'UNKNOWN', 'WordPress version unavailable; required compatibility cannot be established.' );
		}
		if ( in_array( $version, self::TESTED_WORDPRESS_VERSIONS, true ) ) {
			return array( 'PASS', 'WordPress ' . $version . ': TESTED exact fixture.' );
		}
		return array( 'UNKNOWN', 'WordPress ' . $version . ': OTHER VERSION BUT UNTESTED; only ' . implode( ' and ', self::TESTED_WORDPRESS_VERSIONS ) . ' are TESTED exact fixtures and no wider supported range is claimed.' );
	}

	/**
	 * Runtime write grants can fire triggers that the lexical Guard monitor
	 * cannot see. TRIGGER authority itself is FAILed by runtime(); a
	 * non-target writable object whose trigger graph is uninspected, or that
	 * currently has any trigger, cannot be certified PASS.
	 */
	private function trigger_surface( ?Compatibility_Grants $grants, $target, ?\wpdb $installer, bool $trusted ): void {
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
				$expected = 'writeleash_v01_' . substr( hash( 'sha256', $name ), 0, 16 );
				$status = 1 === count( $info['triggers'] ) && $expected === $info['triggers'][0]->TRIGGER_NAME ? 'UNKNOWN' : 'FAIL';
				$this->check( 'target_table', $status, true, 'Target table/policy', 'Existing trigger or policy; provide the expected budget to verify an installed policy. Unrelated triggers conflict.' );
				return;
			}
			if ( null !== $budget ) {
				// The expected runtime identity comes from the runtime connection
				// under test, never from the installer.
				$runtime_user = ( new Update_Engine( $db ) )->session_user_name();
				// The physical trigger is username-scoped: more than one account
				// row for that username makes runtime identity ambiguous.
				$account_count = $installer->get_var( $installer->prepare( 'SELECT COUNT(*) FROM mysql.user WHERE User = %s', $runtime_user ) );
				if ( null === $account_count || '' !== (string) $installer->last_error || 1 !== (int) $account_count ) {
					$this->check( 'target_table', 'FAIL', true, 'Target table/policy', 'Runtime identity is ambiguous or unverifiable: the certified username must map to exactly one database account; a username-scoped policy cannot be certified. Nothing was changed.' );
					return;
				}
				$engine->verify_policy( $name, $budget, $runtime_user );
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
