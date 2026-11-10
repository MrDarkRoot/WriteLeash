<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * #326 read-only Protection report.
 *
 * No new table. Perimeter refusals are the append-only JSON lines
 * Perimeter_Audit_Log writes (timestamp, code, where, actor, object_id,
 * kind, detail). That file is the only store of the surface (`where`).
 * `writeleash_grant_audit` has no surface column and does not record those
 * refusals. Active budget and expiry already live on `writeleash_grants`.
 * This class only reads. It does not grant, revoke, or decide a denial.
 *
 * Detail and route are dropped at parse time so a payload key or request
 * body never reaches the screen. The actor is a user id; display names are
 * added by the admin screen.
 */
final class Protection_Report_Query {
	public const WINDOWS = array( 7, 30, 90 );
	public const DEFAULT_WINDOW = 7;
	public const PAGE_SIZE = 20;
	public const MAX_PAGE = 20;
	public const MAX_LISTED = 400;
	public const MAX_DENIAL_ROWS = 5000;
	public const MAX_LOG_BYTES = 5242880;
	public const MAX_LINE_BYTES = 8192;
	public const GRANT_LIST_LIMIT = 50;
	/** Surfaces Perimeter_Guard actually passes as `where`. Unknown log values are counted separately. */
	public const SURFACES = array(
		'rest',
		'product_save',
		'variation_save',
		'product_set_stock',
		'variation_set_stock',
		'update_product_stock_query',
		'update_post_metadata',
		'add_post_metadata',
		'delete_post_metadata',
		'pre_trash_post',
		'pre_delete_post',
	);

	public static function normalize_window( $value ): int {
		$days = is_int( $value ) ? $value : ( is_string( $value ) && 1 === preg_match( '/\A[0-9]{1,3}\z/D', $value ) ? (int) $value : 0 );
		return in_array( $days, self::WINDOWS, true ) ? $days : self::DEFAULT_WINDOW;
	}

	public static function normalize_page( $value ): int {
		$page = is_int( $value ) ? $value : ( is_string( $value ) && 1 === preg_match( '/\A[0-9]{1,9}\z/D', $value ) ? (int) $value : 1 );
		if ( $page < 1 ) { return 1; }
		return $page > self::MAX_PAGE ? self::MAX_PAGE : $page;
	}

	/**
	 * Same enablement rule as Perimeter_Audit_Log: a non-empty WP_DEBUG_LOG
	 * string, or boolean true meaning wp-content/debug.log. Logging off
	 * yields an empty path. This does not create the file.
	 */
	public static function log_path(): string {
		$wp_debug_log = defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false;
		if ( is_string( $wp_debug_log ) && '' !== $wp_debug_log ) {
			return $wp_debug_log;
		}
		if ( true !== $wp_debug_log || ! defined( 'WP_CONTENT_DIR' ) ) {
			return '';
		}
		return WP_CONTENT_DIR . '/debug.log';
	}

	/**
	 * Parse denial lines from the perimeter log. Non-JSON and other debug
	 * lines are ignored. `detail` and `route` are never copied out.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, capped: bool}
	 */
	public static function read_log( string $path, int $max_bytes = 0, int $max_rows = 0 ): array {
		if ( $max_bytes < 1 ) { $max_bytes = self::MAX_LOG_BYTES; }
		if ( $max_rows < 1 ) { $max_rows = self::MAX_DENIAL_ROWS; }
		$empty = array( 'rows' => array(), 'capped' => false );
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return $empty;
		}
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) { return $empty; }
		if ( ! flock( $handle, LOCK_SH ) ) {
			fclose( $handle );
			return $empty;
		}
		$size = filesize( $path );
		$capped = false;
		if ( is_int( $size ) && $size > $max_bytes ) {
			$capped = true;
			if ( 0 !== fseek( $handle, $size - $max_bytes ) ) {
				flock( $handle, LOCK_UN );
				fclose( $handle );
				return $empty;
			}
			fgets( $handle, self::MAX_LINE_BYTES );
		}
		$ring = array();
		$start = 0;
		$count = 0;
		$sequence = 0;
		while ( false !== ( $line = fgets( $handle, self::MAX_LINE_BYTES ) ) ) {
			$row = self::parse_line( $line );
			if ( null === $row ) { continue; }
			$row['seq'] = $sequence;
			++$sequence;
			if ( $count < $max_rows ) {
				$ring[] = $row;
				++$count;
				continue;
			}
			$capped = true;
			$ring[ $start ] = $row;
			$start = ( $start + 1 ) % $max_rows;
		}
		flock( $handle, LOCK_UN );
		fclose( $handle );
		if ( $count === $max_rows && $start > 0 ) {
			$rows = array_merge( array_slice( $ring, $start ), array_slice( $ring, 0, $start ) );
		} else {
			$rows = $ring;
		}
		return array( 'rows' => $rows, 'capped' => $capped );
	}

	/**
	 * Active grants: status active, restriction still in place, expiry in the
	 * future. Failures return readable=false and no rows. Read-only.
	 *
	 * @return array{readable: bool, active_count: int, expiring_count: int, rows: array<int, array<string, mixed>>}
	 */
	public static function read_grants( $db, int $window_days ): array {
		$empty = array( 'readable' => false, 'active_count' => 0, 'expiring_count' => 0, 'rows' => array() );
		$none = array( 'readable' => true, 'active_count' => 0, 'expiring_count' => 0, 'rows' => array() );
		if ( ! $db instanceof \wpdb ) { return $empty; }
		$window_days = self::normalize_window( $window_days );
		try {
			// Absent schema is zero grants. A query error below stays unreadable.
			if ( ! Grant_Schema::ready( $db ) ) { return $none; }
			$table = Grant_Schema::grants_table( $db );
		} catch ( \Throwable $error ) {
			unset( $error );
			return $empty;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only report count; identifier and values are prepared.
		$count = $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM %i WHERE status=%s AND restriction_removed_at IS NULL AND expires_at > UTC_TIMESTAMP()', $table, 'active' ) );
		if ( ( ! is_string( $count ) && ! is_int( $count ) ) || ! is_numeric( $count ) ) { return $empty; }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only expiring count; the window is a bound integer.
		$expiring = $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM %i WHERE status=%s AND restriction_removed_at IS NULL AND expires_at > UTC_TIMESTAMP() AND expires_at <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d DAY)', $table, 'active', $window_days ) );
		if ( ( ! is_string( $expiring ) && ! is_int( $expiring ) ) || ! is_numeric( $expiring ) ) { return $empty; }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded grant listing for the report; no grant columns beyond budget and expiry.
		$rows = $db->get_results( $db->prepare( 'SELECT public_id, actor_id, budget_max, budget_consumed, budget_reserved, expires_at, status, restriction_removed_at FROM %i WHERE status=%s AND restriction_removed_at IS NULL AND expires_at > UTC_TIMESTAMP() ORDER BY expires_at ASC, id ASC LIMIT %d', $table, 'active', self::GRANT_LIST_LIMIT ), ARRAY_A );
		if ( ! is_array( $rows ) ) { return $empty; }
		return array(
			'readable' => true,
			'active_count' => (int) $count,
			'expiring_count' => (int) $expiring,
			'rows' => $rows,
		);
	}

	/**
	 * Pure report from already loaded denial and grant rows.
	 *
	 * @param array<int, array<string, mixed>> $denials
	 * @param array<int, array<string, mixed>> $grants
	 * @return array<string, mixed>
	 */
	public static function build( array $denials, array $grants, int $window_days, int $page, int $now ): array {
		$window_days = self::normalize_window( $window_days );
		$page = self::normalize_page( $page );
		$start = $now - ( $window_days * 86400 );
		$in_window = array();
		foreach ( $denials as $denial ) {
			if ( ! is_array( $denial ) || ! isset( $denial['unix'] ) || ! is_int( $denial['unix'] ) ) { continue; }
			if ( $denial['unix'] < $start || $denial['unix'] > $now ) { continue; }
			$in_window[] = $denial;
		}
		usort( $in_window, static function ( array $left, array $right ): int {
			if ( $left['unix'] === $right['unix'] ) {
				$left_seq = isset( $left['seq'] ) ? (int) $left['seq'] : 0;
				$right_seq = isset( $right['seq'] ) ? (int) $right['seq'] : 0;
				return $right_seq <=> $left_seq;
			}
			return $right['unix'] <=> $left['unix'];
		} );
		$blocked = count( $in_window );
		$surfaces = array();
		foreach ( self::SURFACES as $surface ) { $surfaces[ $surface ] = 0; }
		foreach ( $in_window as $index => $denial ) {
			$surface = self::safe_token( $denial['surface'] ?? null );
			$in_window[ $index ]['surface'] = $surface;
			if ( ! isset( $surfaces[ $surface ] ) ) { $surfaces[ $surface ] = 0; }
			++$surfaces[ $surface ];
		}
		$listed_pool = $in_window;
		$list_capped = $blocked > self::MAX_LISTED;
		if ( $list_capped ) {
			$listed_pool = array_slice( $in_window, 0, self::MAX_LISTED );
		}
		$listed = count( $listed_pool );
		$pages = max( 1, (int) ceil( $listed / self::PAGE_SIZE ) );
		if ( $pages > self::MAX_PAGE ) { $pages = self::MAX_PAGE; }
		if ( $page > $pages ) { $page = $pages; }
		$offset = ( $page - 1 ) * self::PAGE_SIZE;
		$page_rows = array_slice( $listed_pool, $offset, self::PAGE_SIZE );
		$rows = array();
		foreach ( $page_rows as $denial ) {
			$rows[] = array(
				'time' => self::safe_time( $denial['time'] ?? null ),
				'actor_id' => isset( $denial['actor_id'] ) ? max( 0, (int) $denial['actor_id'] ) : 0,
				'surface' => self::safe_token( $denial['surface'] ?? null ),
				'product_id' => isset( $denial['product_id'] ) ? max( 0, (int) $denial['product_id'] ) : 0,
				'reason' => self::safe_reason( $denial['reason'] ?? null ),
			);
		}
		$grant_report = self::build_grants( $grants, $window_days, $now );
		return array(
			'window_days' => $window_days,
			'page' => $page,
			'page_size' => self::PAGE_SIZE,
			'pages' => $pages,
			'blocked' => $blocked,
			'listed' => $listed,
			'list_capped' => $list_capped,
			'surfaces' => $surfaces,
			'rows' => $rows,
			'empty' => 0 === $blocked,
			'grants' => $grant_report['rows'],
			'active_grant_count' => $grant_report['active_count'],
			'expiring_grant_count' => $grant_report['expiring_count'],
			'grants_list_limited' => $grant_report['limited'],
			'now' => $now,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function load( int $window_days, int $page, ?int $now = null ): array {
		$now = null === $now ? time() : $now;
		$window_days = self::normalize_window( $window_days );
		$page = self::normalize_page( $page );
		$path = self::log_path();
		$log = '' === $path ? array( 'rows' => array(), 'capped' => false ) : self::read_log( $path );
		global $wpdb;
		$grants = self::read_grants( $wpdb, $window_days );
		$report = self::build( $log['rows'], $grants['rows'], $window_days, $page, $now );
		$report['log_enabled'] = '' !== $path;
		$report['log_capped'] = (bool) $log['capped'];
		$report['grants_readable'] = (bool) $grants['readable'];
		if ( $grants['readable'] ) {
			$report['active_grant_count'] = (int) $grants['active_count'];
			$report['expiring_grant_count'] = (int) $grants['expiring_count'];
			$report['grants_list_limited'] = (int) $grants['active_count'] > count( $report['grants'] );
		} else {
			$report['grants'] = array();
			$report['active_grant_count'] = 0;
			$report['expiring_grant_count'] = 0;
			$report['grants_list_limited'] = false;
		}
		return $report;
	}

	/** @param mixed $value */
	private static function safe_token( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[a-z0-9_]{1,64}\z/D', $value ) ) {
			return 'unrecognized';
		}
		return $value;
	}

	/** @param mixed $value */
	private static function safe_reason( $value ): string {
		$token = self::safe_token( $value );
		return 'unrecognized' === $token ? 'writeleash_perimeter_denied' : $token;
	}

	/** @param mixed $value */
	private static function safe_time( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2})\z/', $value ) ) {
			return '';
		}
		return $value;
	}

	/** @return array<string, mixed>|null */
	private static function parse_line( string $line ): ?array {
		$line = trim( $line );
		if ( '' === $line || '{' !== $line[0] ) { return null; }
		$decoded = json_decode( $line, true );
		if ( ! is_array( $decoded ) || ( $decoded['code'] ?? '' ) !== 'writeleash_perimeter_denied' ) {
			return null;
		}
		$stamp = isset( $decoded['timestamp'] ) && is_string( $decoded['timestamp'] ) ? $decoded['timestamp'] : '';
		if ( 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2})\z/', $stamp ) ) {
			return null;
		}
		$unix = strtotime( $stamp );
		if ( false === $unix ) { return null; }
		$surface = isset( $decoded['where'] ) && is_string( $decoded['where'] ) ? $decoded['where'] : '';
		if ( 1 !== preg_match( '/\A[a-z0-9_]{1,64}\z/D', $surface ) ) {
			$surface = 'unrecognized';
		}
		$actor = isset( $decoded['actor'] ) ? (int) $decoded['actor'] : 0;
		$object = isset( $decoded['object_id'] ) ? (int) $decoded['object_id'] : 0;
		return array(
			'time' => $stamp,
			'unix' => $unix,
			'actor_id' => $actor > 0 ? $actor : 0,
			'surface' => $surface,
			'product_id' => $object > 0 ? $object : 0,
			'reason' => 'writeleash_perimeter_denied',
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $grants
	 * @return array{rows: array<int, array<string, mixed>>, active_count: int, expiring_count: int, limited: bool}
	 */
	private static function build_grants( array $grants, int $window_days, int $now ): array {
		$active = array();
		foreach ( $grants as $grant ) {
			if ( ! is_array( $grant ) ) { continue; }
			$normalized = self::normalize_grant( $grant, $window_days, $now );
			if ( null === $normalized ) { continue; }
			$active[] = $normalized;
		}
		usort( $active, static function ( array $left, array $right ): int {
			return $left['expires_unix'] <=> $right['expires_unix'];
		} );
		$active_count = count( $active );
		$expiring = 0;
		foreach ( $active as $grant ) {
			if ( $grant['expiring_soon'] ) { ++$expiring; }
		}
		$limited = $active_count > self::GRANT_LIST_LIMIT;
		if ( $limited ) { $active = array_slice( $active, 0, self::GRANT_LIST_LIMIT ); }
		$rows = array();
		foreach ( $active as $grant ) {
			unset( $grant['expires_unix'] );
			$rows[] = $grant;
		}
		return array( 'rows' => $rows, 'active_count' => $active_count, 'expiring_count' => $expiring, 'limited' => $limited );
	}

	/**
	 * @param array<string, mixed> $grant
	 * @return array<string, mixed>|null
	 */
	private static function normalize_grant( array $grant, int $window_days, int $now ): ?array {
		if ( ( $grant['status'] ?? '' ) !== 'active' ) { return null; }
		if ( self::restriction_removed( $grant ) ) { return null; }
		$public_id = isset( $grant['public_id'] ) && is_string( $grant['public_id'] ) ? strtolower( $grant['public_id'] ) : '';
		if ( 1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $public_id ) ) {
			return null;
		}
		$expires_at = isset( $grant['expires_at'] ) && is_string( $grant['expires_at'] ) ? $grant['expires_at'] : '';
		if ( 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $expires_at ) ) {
			return null;
		}
		$expires_unix = strtotime( $expires_at . ' UTC' );
		if ( false === $expires_unix || $expires_unix <= $now ) { return null; }
		$max = isset( $grant['budget_max'] ) ? (int) $grant['budget_max'] : 0;
		$used = isset( $grant['budget_consumed'] ) ? (int) $grant['budget_consumed'] : 0;
		$reserved = isset( $grant['budget_reserved'] ) ? (int) $grant['budget_reserved'] : 0;
		if ( $max < 0 ) { $max = 0; }
		if ( $used < 0 ) { $used = 0; }
		if ( $reserved < 0 ) { $reserved = 0; }
		$remaining = $max - $used - $reserved;
		if ( $remaining < 0 ) { $remaining = 0; }
		$actor = isset( $grant['actor_id'] ) ? (int) $grant['actor_id'] : 0;
		return array(
			'public_id' => $public_id,
			'actor_id' => $actor > 0 ? $actor : 0,
			'budget_max' => $max,
			'budget_used' => $used,
			'budget_reserved' => $reserved,
			'budget_remaining' => $remaining,
			'expires_at' => $expires_at,
			'expires_unix' => $expires_unix,
			'expiring_soon' => $expires_unix <= ( $now + ( $window_days * 86400 ) ),
		);
	}

	/** @param array<string, mixed> $grant */
	private static function restriction_removed( array $grant ): bool {
		if ( ! array_key_exists( 'restriction_removed_at', $grant ) || null === $grant['restriction_removed_at'] ) {
			return false;
		}
		$value = $grant['restriction_removed_at'];
		return is_string( $value ) && '' !== $value && '0000-00-00 00:00:00' !== $value;
	}
}
