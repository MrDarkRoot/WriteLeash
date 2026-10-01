<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/** One lifecycle authority: the indexed writeleash_runner_state option row. */
final class Runner_Authority {
	public const OPTION = 'writeleash_runner_state';

	/** Uncached observation is diagnostic only; it never grants a transaction fence. */
	public static function active( \wpdb $db ): bool {
		$value = $db->get_var( $db->prepare( 'SELECT option_value FROM %i WHERE option_name=%s LIMIT 1', $db->options, self::OPTION ) );
		return 'active' === $value;
	}

	/**
	 * Lock the EXISTING active option row on the SAME pinned transaction as the
	 * item claim or Woo save. WordPress has a unique option_name index; the
	 * indexed equality shared locking read holds its InnoDB record lock to
	 * COMMIT without serializing unrelated jobs against one another.
	 * Uninstall's DELETE and deactivation's UPDATE of that row must wait. If
	 * shutdown deleted the row first, this returns false and the caller rolls
	 * back immediately; no absent-row/gap lock is ever used as authority.
	 *
	 * Global order: lifecycle -> apply job -> Undo operation -> item/journal
	 * -> Woo. Shutdown takes ONLY lifecycle, releases it, then cancels AS.
	 */
	public static function lock_active( \wpdb $db ): bool {
		if ( ! $db instanceof Price_Apply_Connection || ! $db->owns_attempt() ) { throw new Price_Apply_Error( 'TRANSACTION_LOST' ); }
		$engine = $db->get_var( $db->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $db->options ) );
		$index = $db->get_results( $db->prepare( "SHOW INDEX FROM %i WHERE Key_name='option_name'", $db->options ), ARRAY_A );
		if ( 'InnoDB' !== $engine || 1 !== count( $index ) || 'option_name' !== $index[0]['Column_name'] || '0' !== (string) $index[0]['Non_unique'] ) {
			throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' );
		}
		// LOCK IN SHARE MODE works in both pinned engines. A shutdown DELETE
		// or UPDATE needs an exclusive lock on this SAME existing record.
		$value = $db->get_var( $db->prepare( 'SELECT option_value FROM %i WHERE option_name=%s LOCK IN SHARE MODE', $db->options, self::OPTION ) );
		return 'active' === $value;
	}

	/** Short autocommit shutdown transition on the same indexed row. */
	public static function deactivate(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The uncached indexed lifecycle UPDATE is the durable shutdown linearization point, not a cacheable read.
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value=%s WHERE option_name=%s AND option_value=%s', $wpdb->options, 'deactivated', self::OPTION, 'active' ) );
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		if ( false === $updated || self::active( $wpdb ) ) { throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
	}
}
