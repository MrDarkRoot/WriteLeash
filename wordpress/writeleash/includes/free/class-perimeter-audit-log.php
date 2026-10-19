<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * #307 follow-up: structured denial logging for Perimeter_Guard refusals.
 *
 * Listens to `writeleash_perimeter_denied` (fired by Perimeter_Guard::deny()
 * on every restricted-principal refusal) and appends one JSON line to the
 * WordPress debug log. Post-notification of the denial only: this class never
 * alters the denial outcome, never throws out of the hook, and never touches
 * the guard's decision logic. Operators get a machine-parseable refusal
 * trail; fluorescent debugging of the perimeter stays with the guard.
 *
 * @package WriteLeash
 */
final class Perimeter_Audit_Log {

	public static function boot(): void {
		add_action( 'writeleash_perimeter_denied', array( __CLASS__, 'record' ), 10, 6 );
	}

	/**
	 * Append one JSON line describing the refusal. Swallows every error so
	 * logging can never break the denial flow (the guard denial or the REST
	 * 403 happens exactly as before, logged or not).
	 *
	 * @param mixed $code      Denial code, always writeleash_perimeter_denied.
	 * @param mixed $where     Guard call site (rest, *_metadata, *_save, ...).
	 * @param mixed $actor     Actor user id at refusal time.
	 * @param mixed $object_id Product/post id the write targeted (0 for REST).
	 * @param mixed $kind      Mutation kind (price, stock, trash, delete, rest).
	 * @param mixed $detail    Meta key, JSON payload keys, or the REST route.
	 */
	public static function record( $code, $where, $actor, $object_id, $kind, $detail ): void {
		try {
			$target = self::target();
			if ( '' === $target ) {
				return;
			}
			$entry = array(
				'timestamp' => gmdate( 'c' ),
				'code'      => (string) $code,
				'where'     => (string) $where,
				'actor'     => (int) $actor,
				'object_id' => (int) $object_id,
				'kind'      => (string) $kind,
				'detail'    => (string) $detail,
			);				// REST refusals carry the route in the guard's detail argument;
			// surface it as its own field when available.
			if ( 'rest' === $entry['where'] && '' !== $entry['detail'] ) {
				$entry['route'] = $entry['detail'];
			}
			$json = wp_json_encode( $entry );
			if ( ! is_string( $json ) ) {
				return;
			}
			// Cheap disk-fill cap: once the log passes the ceiling, refusals
			// are dropped (operators rotate the file) rather than appending
			// forever. No tables, no rotation machinery.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A stat failure must never surface PHP warnings into the denial flow.
			if ( @filesize( $target ) > self::DEFAULT_MAX_BYTES ) {
				return;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Audit must never surface PHP warnings into the denial flow.
			@file_put_contents( $target, $json . "\n", FILE_APPEND | LOCK_EX );
		} catch ( \Throwable $ignored ) {
			// Logging never breaks the denial flow.
		}
	}

	/** Audit trail ceiling (5 MB) before refusals are dropped, not appended. */
	private const DEFAULT_MAX_BYTES = 5 * 1024 * 1024;

	/**
	 * Resolve the debug-log path exactly as WordPress does: only a truthy or
	 * non-empty-string WP_DEBUG_LOG enables writing (WordPress core writes
	 * the same file under the same condition); log_errors alone does not
	 * imply debug.log is the intended target, so it must not opt in.
	 */
	private static function target(): string {
		$wp_debug_log = defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false;
		if ( is_string( $wp_debug_log ) && '' !== $wp_debug_log ) {
			return $wp_debug_log;
		}
		if ( true !== $wp_debug_log ) {
			return '';
		}
		return WP_CONTENT_DIR . '/debug.log';
	}
}
