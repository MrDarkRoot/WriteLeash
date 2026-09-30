<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

final class Price_Apply_Error extends \RuntimeException {
	public function __construct( string $reason ) { parent::__construct( $reason ); }
}

/**
 * Scoped wpdb transport on the ORIGINAL handle. Core wpdb auto-replays a query
 * after reconnect without re-running the query filter. This subclass disables
 * that replay and checks transaction ownership before and after every query.
 * No credentials, new writer connection, price SQL or Guard coupling.
 */
final class Price_Apply_Connection extends \wpdb {
	private $owner;
	private $original_wpdb;
	private int $connection_id;
	private string $sentinel;
	private bool $owned = false;
	public function __construct( \wpdb $original ) {
		if ( 'wpdb' !== get_class( $original ) || ! $original->dbh instanceof \mysqli ) { throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
		// Release original query-result ownership before sharing configuration.
		$original->flush();
		// Parent protected/public configuration is accessible in its subclass.
		// Private query caches retain their parent defaults; they are not ownership.
		foreach ( get_object_vars( $original ) as $key => $value ) { $this->$key = $value; }
		$this->original_wpdb = $original;
		$this->owner = $original->dbh;
		$this->connection_id = (int) $this->owner->thread_id;
		$this->sentinel = 'wl_price_' . bin2hex( random_bytes( 12 ) );
	}
	public function check_connection( $allow_bail = true ) { throw new Price_Apply_Error( 'TRANSACTION_LOST' ); }
	public function db_connect( $allow_bail = true ) { throw new Price_Apply_Error( 'TRANSACTION_LOST' ); }
	public function id(): int { return $this->connection_id; }
	private function raw( string $sql ) {
		try {
			if ( $this->dbh !== $this->owner || $this->original_wpdb->dbh !== $this->owner || (int) $this->owner->thread_id !== $this->connection_id ) { throw new Price_Apply_Error( 'TRANSACTION_LOST' ); }
			$result = $this->owner->query( $sql );
			if ( false === $result ) { throw new Price_Apply_Error( 'TRANSACTION_LOST' ); }
			return $result;
		} catch ( \Throwable $e ) { throw new Price_Apply_Error( 'TRANSACTION_LOST' ); }
	}
	public function begin(): void {
		// A probe savepoint outside a transaction is absent. Never commit caller work.
		$name = 'wl_probe_' . bin2hex( random_bytes( 12 ) );
		$this->raw( "SAVEPOINT $name" );
		try { $active = @$this->owner->query( "ROLLBACK TO SAVEPOINT $name" ); } catch ( \Throwable $e ) { $active = false; }
		if ( false !== $active ) { $this->raw( "RELEASE SAVEPOINT $name" ); throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
		if ( 1305 !== $this->owner->errno ) { throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
		$this->raw( 'START TRANSACTION' );
		$this->owned = true;
		$this->raw( "SAVEPOINT {$this->sentinel}" );
	}
	public function assert_owned(): void {
		if ( ! $this->owned ) { throw new Price_Apply_Error( 'TRANSACTION_LOST' ); }
		// RELEASE is non-destructive, unlike rolling back to an old sentinel.
		$this->raw( "RELEASE SAVEPOINT {$this->sentinel}" );
		$this->raw( "SAVEPOINT {$this->sentinel}" );
	}
	public function query( $sql ) {
		$this->assert_owned();
		// Third-party transaction control/DDL would discard our transaction.
		if ( ! preg_match( '/\A\s*(?:SELECT|INSERT|UPDATE|DELETE|REPLACE|SHOW|DESCRIBE)\b/i', $sql ) ) { throw new Price_Apply_Error( 'TRANSACTION_LOST' ); }
		$result = parent::query( $sql );
		$this->assert_owned();
		if ( false === $result ) { throw new Price_Apply_Error( 'FAILED' ); }
		return $result;
	}
	public function commit(): void {
		$this->assert_owned();
		try { $this->raw( 'COMMIT' ); } catch ( \Throwable $e ) { throw new Price_Apply_Error( 'AMBIGUOUS_COMMIT' ); }
		$this->owned = false;
	}
	public function owns_attempt(): bool { return $this->owned; }
	public function rollback(): bool {
		if ( ! $this->owned ) { return false; }
		try {
			if ( (int) $this->owner->thread_id !== $this->connection_id || false === $this->owner->query( 'ROLLBACK' ) ) { return false; }
			$this->owned = false; return true;
		} catch ( \Throwable $e ) { return false; }
	}
}
