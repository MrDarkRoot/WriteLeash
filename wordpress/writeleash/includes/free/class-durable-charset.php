<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/** Physical encoding only. Logical row/plan versions, JSON bytes and mutation authority never change. */
final class Durable_Charset {
	private static function owned( \wpdb $db, string $table ): void {
		if ( ! preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) || ! in_array( $table, array( $db->prefix . 'writeleash_jobs', $db->prefix . 'writeleash_job_items', $db->prefix . 'writeleash_price_items', $db->prefix . 'writeleash_undo_operations', $db->prefix . 'writeleash_undo_items' ), true ) ) {
			throw new \RuntimeException( 'Durable encoding: unowned table.' );
		}
	}

	public static function table_clause( \wpdb $db ): string {
		if ( 'utf8mb4' !== $db->charset || ! preg_match( '/\Autf8mb4_[a-z0-9_]+\z/D', $db->collate ) ) {
			throw new \RuntimeException( 'Durable encoding requires the WordPress utf8mb4 connection and collation.' );
		}
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE ' . $db->collate;
	}

	private static function columns( \wpdb $db, string $table ): array {
		$columns = $db->get_results( $db->prepare( 'SHOW FULL COLUMNS FROM %i', $table ), ARRAY_A );
		if ( ! is_array( $columns ) || ! $columns ) { throw new \RuntimeException( 'Durable encoding: column metadata unavailable.' ); }
		return $columns;
	}

	/** Read-only readiness check; no installer/DDL on History or worker read paths. */
	public static function assert_table( \wpdb $db, string $table, array $binary_columns, bool $legacy = false ): void {
		self::owned( $db, $table );
		$default = $db->get_var( $db->prepare( 'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
		if ( ! is_string( $default ) || 0 !== strpos( $default, 'utf8mb4_' ) ) { throw new \RuntimeException( 'Durable encoding: table default is not utf8mb4.' ); }
		$found = array();
		foreach ( self::columns( $db, $table ) as $column ) {
			$found[$column['Field']] = $column;
			if ( preg_match( '/(?:char|text|blob|binary|enum|set)/i', $column['Type'] ) && ( ! is_string( $column['Collation'] ) || ( 0 !== strpos( $column['Collation'], 'utf8mb4_' ) && ! ( $legacy && in_array( $column['Field'], $binary_columns, true ) && 'ascii_bin' === $column['Collation'] ) ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic exception construction; not HTML output.
				throw new \RuntimeException( 'Durable encoding: incompatible column ' . $table . '.' . $column['Field'] . '.' );
			}
		}
		foreach ( $binary_columns as $name ) {
			if ( ! isset( $found[$name] ) || ! in_array( $found[$name]['Collation'], $legacy ? array( 'ascii_bin', 'utf8mb4_bin' ) : array( 'utf8mb4_bin' ), true ) ) { throw new \RuntimeException( 'Durable encoding: opaque identity must use utf8mb4_bin.' ); }
		}
	}

	/**
	 * Explicit schema/setup path only, outside caller transactions. No DROP,
	 * row UPDATE, JSON reserialization or encoding bypass. One atomic ALTER per
	 * existing table; a interrupted family migration is detected and replayed.
	 */
	public static function migrate( \wpdb $db, string $table ): void {
		self::owned( $db, $table );
		$default = $db->get_var( $db->prepare( 'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
		if ( null === $default ) { return; }
		// DDL must never run inside an item writer transaction. Standard
		// wpdb subclasses and drop-ins on the normal connection are fine.
		if ( $db instanceof Price_Apply_Connection ) { throw new \RuntimeException( 'Durable encoding migration cannot run on a writer transaction.' ); }
		$clause = self::table_clause( $db );
		$columns = self::columns( $db, $table );
		$changes = array();
		foreach ( $columns as $column ) {
			$type = $column['Type']; $collation = $column['Collation'];
			if ( ! preg_match( '/(?:char|text|blob|binary|enum|set)/i', $type ) ) { continue; }
			if ( ! preg_match( '/\A(?:char\([0-9]+\)|varchar\([0-9]+\)|(?:tiny|medium|long)?text)\z/D', $type ) || ! is_string( $collation ) || ! preg_match( '/\A(?:ascii|utf8|utf8mb3|utf8mb4)_[a-z0-9_]+\z/D', $collation ) || '' !== $column['Extra'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic exception construction; not HTML output.
				throw new \RuntimeException( 'Durable encoding: unsupported byte storage ' . $table . '.' . $column['Field'] . '.' );
			}
			if ( 0 === strpos( $collation, 'utf8mb4_' ) ) { continue; }
			$target = 'ascii_bin' === $collation ? 'utf8mb4_bin' : ( 0 === strpos( $collation, 'ascii_' ) ? $db->collate : preg_replace( '/\Autf8(?:mb3)?_/', 'utf8mb4_', $collation ) );
			$definition = $db->prepare( 'MODIFY COLUMN %i ', $column['Field'] ) . $type . ' CHARACTER SET utf8mb4 COLLATE ' . $target . ( 'YES' === $column['Null'] ? ' NULL' : ' NOT NULL' );
			if ( null !== $column['Default'] ) { $definition .= $db->prepare( ' DEFAULT %s', $column['Default'] ); }
			elseif ( 'YES' === $column['Null'] ) { $definition .= ' DEFAULT NULL'; }
			$definition .= $db->prepare( ' COMMENT %s', $column['Comment'] );
			$changes[] = $definition;
		}
		if ( ! $changes && 0 === strpos( $default, 'utf8mb4_' ) ) { return; }
		// DDL must never implicitly commit another caller's transaction. The
		// existing transport's savepoint probe refuses an ambient transaction.
		$probe = new Price_Apply_Connection( $db );
		try { $probe->begin(); }
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic exception construction; not HTML output.
		catch ( \Throwable $error ) { throw new \RuntimeException( 'Durable encoding migration requires no active transaction.', 0, $error ); }
		if ( ! $probe->rollback() ) { throw new \RuntimeException( 'Durable encoding: transaction probe cleanup failed.' ); }
		// Validate every text column without loading catalog/history-sized rows
		// into PHP. Both declared-charset conversion and forced UTF-8 decoding
		// must preserve bytes. Invalid/undecodable records are not coerced.
		foreach ( $columns as $column ) {
			if ( null === $column['Collation'] ) { continue; }
			$name = $column['Field'];
			$sql = $db->prepare( 'SELECT id FROM %i WHERE %i IS NOT NULL AND (CAST(CONVERT(%i USING utf8mb4) AS BINARY) <> CAST(%i AS BINARY) OR CAST(CONVERT(CAST(%i AS BINARY) USING utf8mb4) AS BINARY) <> CAST(%i AS BINARY)) LIMIT 1', $table, $name, $name, $name, $name, $name );
			$bad = $db->get_var( $sql );
			$error = $db->last_error;
			$warnings = $db->get_results( 'SHOW WARNINGS', ARRAY_A );
			if ( null !== $bad || '' !== $error || $warnings ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic exception construction; not HTML output.
				throw new \RuntimeException( 'Durable encoding: undecodable row/column ' . $table . '.' . $name . '; migration refused.' );
			}
		}
		$mode = $db->get_var( 'SELECT @@SESSION.sql_mode' );
		if ( ! is_string( $mode ) ) { throw new \RuntimeException( 'Durable encoding: session SQL mode unavailable.' ); }
		$strict = $mode . ( '' === $mode ? '' : ',' ) . 'STRICT_ALL_TABLES';
		try {
			if ( false === $db->query( $db->prepare( 'SET SESSION sql_mode=%s', $strict ) ) ) { throw new \RuntimeException( 'Durable encoding: strict migration mode unavailable.' ); }
			$sql = $db->prepare( 'ALTER TABLE %i ', $table ) . $clause . ( $changes ? ', ' . implode( ', ', $changes ) : '' );
			if ( false === $db->query( $sql ) || $db->get_results( 'SHOW WARNINGS', ARRAY_A ) ) { throw new \RuntimeException( 'Durable encoding: ALTER failed or warned; setup remains unready.' ); }
		} finally {
			Price_Apply_Connection::forget_table_metadata( $db, $table );
			if ( false === $db->query( $db->prepare( 'SET SESSION sql_mode=%s', $mode ) ) ) { throw new \RuntimeException( 'Durable encoding: SQL mode restore failed.' ); }
		}
	}
}
