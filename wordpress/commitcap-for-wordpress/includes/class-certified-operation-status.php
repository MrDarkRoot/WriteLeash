<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Live readiness/status evaluation for the certified operation (#78).
 *
 * One small coordinator answers, from the immutable descriptor plus the tiny
 * mutable config and the current runtime:
 *
 *   READY         all checks passed; the REST integration may execute
 *   DISABLED      config absent or `enabled=false`
 *   UNSUPPORTED   exact plugin version or adapter boundary unavailable
 *   MISCONFIGURED stored state invalid, or enabled without a valid budget
 *   NOT_READY     runtime/Doctor/physical-ceiling mismatch
 *
 * Nothing here is cached: every call reads the live config and runs the live
 * #83 runtime Doctor on the restricted connection. There is no stored
 * `doctor_pass` shortcut. All fail-closed outcomes carry a machine reason and
 * a secret-free detail.
 */
final class Certified_Operation_Status {
	public const READY        = 'READY';
	public const DISABLED     = 'DISABLED';
	public const UNSUPPORTED  = 'UNSUPPORTED';
	public const MISCONFIGURED = 'MISCONFIGURED';
	public const NOT_READY    = 'NOT_READY';

	/**
	 * Evaluate the operation against the current runtime.
	 *
	 * @param Certified_Operation $operation Known descriptor.
	 * @param string|null $detected_version Live plugin version exactly as
	 *                                      detected; null means "unavailable"
	 *                                      and fails closed. Callers that want
	 *                                      live detection pass
	 *                                      Redirection_Bulk_Disable::detected_version().
	 * @param \wpdb|null $runtime Explicit restricted runtime connection; null
	 *                            uses the operator's protected configuration.
	 * @return array{status: string, reason: string, detail: string, logical_budget: int|null, physical_ceiling: int, actual_physical_ceiling: int|null, runtime: \wpdb|null, doctor: array|null}
	 */
	public static function check( Certified_Operation $operation, ?string $detected_version = null, ?\wpdb $runtime = null ): array {
		$config = Operation_Config::read( $operation );
		if ( 'invalid' === $config['state'] ) {
			return self::result( self::MISCONFIGURED, 'config_invalid', $config['detail'], $operation );
		}
		if ( ! $config['enabled'] ) {
			return self::result( self::DISABLED, 'operation_disabled', $config['detail'], $operation );
		}
		if ( null === $config['logical_budget'] ) {
			return self::result( self::MISCONFIGURED, 'logical_budget_missing', 'The certified operation is enabled without a logical budget.', $operation );
		}
		$budget = $config['logical_budget'];
		if ( ! $operation->supports_logical_budget( $budget ) ) {
			return self::result( self::MISCONFIGURED, 'logical_budget_invalid', 'The configured logical budget is outside the descriptor range.', $operation );
		}

		$version = $detected_version;
		if ( $operation->plugin_version() !== $version ) {
			return self::result( self::UNSUPPORTED, 'redirection_version_unsupported', 'The installed Redirection version is not the certified version.', $operation, array( 'logical_budget' => $budget, 'detected_version' => $version ) );
		}
		$adapter = $operation->adapter_class();
		if ( ! class_exists( $adapter ) || ! method_exists( $adapter, 'run' ) || ! Redirection_Bulk_Disable::plugin_class_available() ) {
			return self::result( self::UNSUPPORTED, 'adapter_unavailable', 'The reviewed adapter boundary is unavailable for this installation.', $operation, array( 'logical_budget' => $budget ) );
		}

		$db = $runtime instanceof \wpdb ? $runtime : self::runtime_connection();
		if ( ! $db instanceof \wpdb || ! $db->ready || ! $db->dbh instanceof \mysqli ) {
			return self::result( self::NOT_READY, 'runtime_unavailable', 'The restricted shared runtime connection is unavailable.', $operation, array( 'logical_budget' => $budget ) );
		}
		$table = (string) $db->prefix . $operation->table_suffix();

		try {
			$doctor = Compatibility_Doctor::runtime( array( $table => $budget ), $db );
		} catch ( \Throwable $error ) {
			return self::result( self::NOT_READY, 'doctor_not_ready', 'Runtime Doctor could not evaluate the certified operation.', $operation, array( 'logical_budget' => $budget, 'runtime' => $db ) );
		}
		$integration = isset( $doctor['integrations'][ $table ] ) && is_array( $doctor['integrations'][ $table ] )
			? $doctor['integrations'][ $table ]
			: null;
		if ( 'PASS' !== ( isset( $doctor['overall'] ) ? $doctor['overall'] : null ) || null === $integration || 'PASS' !== $integration['status'] ) {
			return self::result( self::NOT_READY, 'doctor_not_ready', 'Runtime Doctor is not READY for the certified target policy.', $operation, array( 'logical_budget' => $budget, 'runtime' => $db, 'doctor' => $doctor ) );
		}
		$actual_ceiling = isset( $integration['physical_ceiling'] ) ? $integration['physical_ceiling'] : null;
		if ( $operation->physical_ceiling() !== $actual_ceiling ) {
			return self::result( self::NOT_READY, 'physical_ceiling_mismatch', 'The installed physical ceiling differs from the certified operation descriptor.', $operation, array( 'logical_budget' => $budget, 'runtime' => $db, 'doctor' => $doctor, 'actual_physical_ceiling' => $actual_ceiling ) );
		}

		$schema = $db->get_var( 'SELECT DATABASE()' );
		$grants = is_string( $schema ) && '' !== $schema && '' === (string) $db->last_error ? Compatibility_Grants::read( $db, $schema ) : null;
		$access = null === $grants ? array( 'UNKNOWN', 'Runtime target-table grants could not be read.' ) : $grants->target_access( $table );
		if ( 'PASS' !== $access[0] ) {
			return self::result( self::NOT_READY, 'target_privileges_missing', 'The restricted runtime lacks the descriptor-required target privileges: ' . $access[1], $operation, array( 'logical_budget' => $budget, 'runtime' => $db, 'doctor' => $doctor, 'actual_physical_ceiling' => $actual_ceiling ) );
		}

		return self::result( self::READY, 'ok', 'Certified operation is enabled, policy-verified and READY.', $operation, array(
			'logical_budget'          => $budget,
			'actual_physical_ceiling' => $actual_ceiling,
			'runtime'                 => $db,
			'doctor'                  => $doctor,
		) );
	}

	/**
	 * Restricted shared runtime connection from the operator's protected
	 * configuration, on the same DB server as the normal WordPress connection.
	 * `COMMITCAP_DB_HOST` from the provisioning snippet is the account host
	 * restriction and is not a connect host; the normal connection's host is
	 * used here. Missing or unusable configuration returns null.
	 */
	public static function runtime_connection(): ?\wpdb {
		if ( ! defined( 'COMMITCAP_DB_USER' ) || ! defined( 'COMMITCAP_DB_PASSWORD' ) ) {
			return null;
		}
		$user     = (string) constant( 'COMMITCAP_DB_USER' );
		$password = (string) constant( 'COMMITCAP_DB_PASSWORD' );
		if ( '' === $user ) {
			return null;
		}
		$normal = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \wpdb ? $GLOBALS['wpdb'] : null;
		$name   = defined( 'COMMITCAP_DB_NAME' ) && '' !== (string) constant( 'COMMITCAP_DB_NAME' )
			? (string) constant( 'COMMITCAP_DB_NAME' )
			: ( null !== $normal ? (string) $normal->dbname : '' );
		$host   = null !== $normal ? (string) $normal->dbhost : 'localhost';
		if ( '' === $name || '' === $host ) {
			return null;
		}
		try {
			$db = new \wpdb( $user, $password, $name, $host );
		} catch ( \Throwable $error ) {
			return null;
		}
		$db->suppress_errors( true );
		if ( null !== $normal ) {
			$db->set_prefix( (string) $normal->prefix );
		}
		$probe = $db->get_var( 'SELECT 1' );
		if ( ! $db->ready || '1' !== (string) $probe ) {
			return null;
		}
		return $db;
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array{status: string, reason: string, detail: string, logical_budget: int|null, physical_ceiling: int, actual_physical_ceiling: int|null, runtime: \wpdb|null, doctor: array|null}
	 */
	private static function result( string $status, string $reason, string $detail, Certified_Operation $operation, array $extra = array() ): array {
		return array_merge(
			array(
				'status'                  => $status,
				'reason'                  => $reason,
				'detail'                  => $detail,
				'logical_budget'          => null,
				'physical_ceiling'        => $operation->physical_ceiling(),
				'actual_physical_ceiling' => null,
				'runtime'                 => null,
				'doctor'                  => null,
			),
			$extra
		);
	}
}
