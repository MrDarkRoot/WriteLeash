<?php
namespace WriteLeash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Explicit trusted/operator fixture for the one WriteLeash-owned disposable demo. */
final class Disposable_Demo_Setup {
	private $installer;
	private $schema;
	private $table;
	private $user;
	private $host;

	/** Installer connection is supplied only by offline operator/test tooling. */
	public function __construct( \wpdb $installer, string $runtime_user, string $runtime_host ) {
		$normal = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		if ( ! $installer->ready || ! $installer->dbh instanceof \mysqli || $installer === $normal || ! $normal instanceof \wpdb ) {
			throw new \RuntimeException( 'A distinct trusted installer connection is required for the WriteLeash-owned disposable demo.' );
		}
		$this->installer = $installer;
		$this->user = Provisioning_Plan::validate_user( $runtime_user );
		$this->host = Provisioning_Plan::validate_host( $runtime_host );
		$this->schema = Provisioning_Plan::validate_identifier( (string) $installer->get_var( 'SELECT DATABASE()' ), 'schema' );
		if ( $this->schema !== $normal->get_var( 'SELECT DATABASE()' ) ||
			$installer->get_var( 'SELECT @@hostname' ) !== $normal->get_var( 'SELECT @@hostname' ) ||
			$installer->get_var( 'SELECT @@port' ) !== $normal->get_var( 'SELECT @@port' ) ||
			'' !== (string) $installer->last_error ) {
			throw new \RuntimeException( 'Trusted demo installer must point to the same WordPress database endpoint.' );
		}
		$this->table = Disposable_Demo::table( (string) $normal->prefix );
	}

	public function plan(): Provisioning_Plan {
		return Provisioning_Plan::add_target( $this->schema, $this->user, $this->host, $this->table, Disposable_Demo::PHYSICAL_CEILING );
	}

	private function query( string $sql ): void {
		if ( false === $this->installer->query( $sql ) ) {
			throw new \RuntimeException( 'Trusted WriteLeash-owned disposable demo step failed; inspect owned objects and retry after repair.' );
		}
	}

	private function exists(): bool {
		$count = $this->installer->get_var( $this->installer->prepare(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->table
		) );
		if ( null === $count || '' !== (string) $this->installer->last_error ) {
			throw new \RuntimeException( 'Cannot inspect exact disposable demo table name.' );
		}
		return 1 === (int) $count;
	}

	private function grants(): Compatibility_Grants {
		$rows = $this->installer->get_results( "SHOW GRANTS FOR '" . $this->user . "'@'" . $this->host . "'", ARRAY_N );
		$statements = is_array( $rows ) ? array_column( $rows, 0 ) : array();
		$grants = Compatibility_Grants::from_statements( $statements, $this->schema );
		if ( null === $grants || '' !== (string) $this->installer->last_error ) {
			throw new \RuntimeException( 'Cannot inspect exact demo runtime grants.' );
		}
		return $grants;
	}

	private function assert_absent(): void {
		$count = $this->installer->get_var( $this->installer->prepare(
			'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s',
			Update_Engine::trigger_name( $this->table )
		) );
		if ( null === $count || 0 !== (int) $count ||
			'PASS' !== $this->grants()->target_access_exact( $this->table, array(), false )[0] ) {
			throw new \RuntimeException( 'An orphan demo trigger or target grant remains; trusted operator repair is required.' );
		}
	}

	/** Strict ownership: name, shape, policy body/ceiling, and target grants. */
	private function verify( bool $require_policy ): void {
		if ( ! $this->exists() || ! Disposable_Demo::owned_table_shape( $this->installer, $this->table ) ) {
			throw new \RuntimeException( 'Exact demo table absent or foreign shape; refusing trusted mutation.' );
		}
		$engine = new Update_Engine( $this->installer );
		$has_trigger = $engine->assert_canonical_trigger( $this->table, ! $require_policy, $this->user );
		if ( $has_trigger ) {
			$engine->verify_policy( $this->table, Disposable_Demo::PHYSICAL_CEILING, $this->user );
		}
		$access = $this->grants()->target_access_exact( $this->table, array( 'SELECT', 'UPDATE' ), $require_policy );
		if ( 'PASS' !== $access[0] || '' !== (string) $this->installer->last_error ) {
			throw new \RuntimeException( 'Exact demo target grants cannot be proven; refusing trusted mutation.' );
		}
	}

	/** Replays after a partial setup, but never replaces a foreign table/trigger. */
	public function setup(): array {
		if ( $this->exists() ) {
			$this->verify( false );
		} else {
			$this->assert_absent();
			$this->query( "CREATE TABLE `{$this->table}` (id INT NOT NULL PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB COMMENT=" . $this->installer->prepare( '%s', Disposable_Demo::TABLE_COMMENT ) );
		}
		// #84 manages the shared account/infrastructure separately. Never CREATE USER here.
		$engine = new Update_Engine( $this->installer );
		if ( ! $engine->assert_canonical_trigger( $this->table, true, $this->user ) ) {
			$this->plan()->apply( $this->installer );
		}
		$this->verify( true );
		return array( 'label' => Disposable_Demo::LABEL, 'status' => 'READY_FOR_RUNTIME', 'table' => $this->table );
	}

	/** Trusted-only deterministic reset; never called from normal demo run(). */
	public function reset(): void {
		$this->verify( true );
		$this->query( "DELETE FROM `{$this->table}`" );
		$this->query( "INSERT INTO `{$this->table}` (id, value) VALUES (1,0),(2,0),(3,0),(4,0),(5,0),(6,0)" );
	}

	/** Point-in-time trusted cleanup inventory; no wildcard or shared-object deletion. */
	public function cleanup_status(): array {
		try {
			if ( ! $this->exists() ) {
				$this->assert_absent();
				return array( 'label' => Disposable_Demo::LABEL, 'status' => 'ABSENT', 'table' => $this->table );
			}
			$this->verify( false );
			return array( 'label' => Disposable_Demo::LABEL, 'status' => 'OWNED', 'table' => $this->table );
		} catch ( \Throwable $error ) {
			return array( 'label' => Disposable_Demo::LABEL, 'status' => 'UNKNOWN', 'reason' => 'ownership_unverified', 'table' => $this->table );
		}
	}

	/** Remove only verified demo trigger, exact target grants and owned table. */
	public function cleanup(): array {
		if ( ! $this->exists() ) {
			$this->assert_absent();
			return array( 'label' => Disposable_Demo::LABEL, 'status' => 'ABSENT', 'table' => $this->table );
		}
		$this->verify( false );
		$no_grants = 'PASS' === $this->grants()->target_access_exact( $this->table, array(), false )[0];
		if ( $no_grants ) {
			// Interrupted after #84's revoke: no REVOKE is left to repeat. The
			// remaining trigger, if any, has already passed exact verification.
			if ( ( new Update_Engine( $this->installer ) )->assert_canonical_trigger( $this->table, true, $this->user ) ) {
				$this->query( 'DROP TRIGGER `' . Update_Engine::trigger_name( $this->table ) . '`' );
			}
		} else {
			Provisioning_Plan::remove_target( $this->schema, $this->user, $this->host, $this->table, Disposable_Demo::PHYSICAL_CEILING )->apply( $this->installer );
		}
		// DDL/DCL is nontransactional: after interrupted removal, rerun cleanup;
		// ownership/grants are reverified before the exact table drop.
		$this->verify( false );
		$this->query( "DROP TABLE `{$this->table}`" );
		return array( 'label' => Disposable_Demo::LABEL, 'status' => 'REMOVED', 'table' => $this->table );
	}
}
