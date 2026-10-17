<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * #298 standing-grant authority.
 *
 * Durable grant record separate from Change_Plan, the job row and the apply
 * journal. Enforces the ten founder-approved contract decisions:
 *
 * 1. Actor = frozen plan actor_id, re-checked at execution; never substituted.
 * 2. Issuer = authenticated human creator/delegator, stored separately; only
 *    manage_woocommerce + edit_products may issue/revoke; no new capability.
 * 3. Approver = human per-plan approval (approver>=1, mode human) or
 *    grant-submitted (approver 0, mode grant); never render 0 as a person.
 * 4. Scope = WriteLeash-owned regular/sale writes for published simple
 *    products and eligible variations, frozen concrete IDs + absolute targets.
 * 5. Expiry/revocation re-checked at acceptance and before each Woo CRUD
 *    write; revocation wins pre-boundary; global disable fails closed.
 * 6. Cumulative budget = distinct items reaching durable APPLIED across all
 *    jobs; reserve before accept, consume once on APPLIED, release on
 *    definitive non-applied, retain on ambiguous.
 * 7. Idempotency key scoped to issuer/grant; same key+same payload replays,
 *    same key+different payload rejects, one winner concurrently.
 * 8. Audit evidence append-only: actor/issuer/approver/mode, grant/version,
 *    key, frozen targets, before/after, reservation/budget events, outcomes.
 * 9. Lock order: runner option, job row, grant row/reservation, journal/item,
 *    Woo rows + CRUD. Reserve before accept; freeze atomically or rollback;
 *    ambiguous Woo commit retains reservation for reconciliation.
 * 10. Fail closed on store/budget/identity/version/lock/audit uncertainty;
 *    never fallback to unrestricted admin execution.
 *
 * Budget consumption lives in the grant + journal transaction, never in
 * `woocommerce_before_product_object_save`.
 */
final class Grant_Repository {
	public const APPROVAL_NONE = 'none';
	public const APPROVAL_HUMAN = 'human';
	public const APPROVAL_GRANT = 'grant';
	public const IDEMPOTENCY_REGEX = '/\A[a-zA-Z0-9_-]{1,64}\z/';
	public const PUBLIC_ID_REGEX = Job_Repository::PUBLIC_ID_REGEX;
	public const MAX_BUDGET = 10000;

	private static function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * True only when $claimed_id is the currently authenticated WordPress user.
	 *
	 * Possession of an id proves nothing: an anonymous caller (0), a deleted or
	 * invalid id, and a caller supplying another user's id are all refused
	 * before any capability is considered. There is no trusted-internal bypass
	 * parameter; grant management is always bound to the authenticated request
	 * principal. The only non-request caller is the #109 worker, which
	 * impersonates the frozen actor for item execution and never manages grants.
	 */
	public static function is_authenticated_self( int $claimed_id ): bool {
		if ( $claimed_id < 1 ) { return false; }
		$current = (int) get_current_user_id();
		return $current >= 1 && $current === $claimed_id;
	}

	/**
	 * Resolve the authenticated human principal for a human-only grant operation
	 * (issue/accept/revoke/update/disable/enable). Refuses anonymous, zero,
	 * deleted, invalid and mismatched principals, then requires the existing
	 * WooCommerce product-management capability on the actual authenticated
	 * issuer. Never accepts an arbitrary payload id.
	 */
	private static function require_human_self( int $claimed_id ): \WP_User {
		if ( ! self::is_authenticated_self( $claimed_id ) ) { throw new Grant_Error( 'PERMISSION_DENIED' ); }
		$user = new \WP_User( $claimed_id );
		if ( ! $user->exists() ) { throw new Grant_Error( 'PERMISSION_DENIED' ); }
		if ( ! user_can( $user, 'manage_woocommerce' ) || ! user_can( $user, 'edit_products' ) ) { throw new Grant_Error( 'PERMISSION_DENIED' ); }
		return $user;
	}

	/**
	 * Idempotent setup for the grant family. Fresh installs create
	 * plugin-owned tables; unknown/partial state fails closed with a typed
	 * reason and zero grant execution.
	 */
	private static function ensure_schema(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		try {
			if ( ! Grant_Schema::ready( $wpdb ) ) { Grant_Schema::install(); }
			if ( ! Job_Schema::ready( $wpdb ) ) { Job_Schema::install(); }
			if ( ! self::journal_ready( $wpdb ) ) { Price_Apply_Journal::install(); }
			Durable_Charset::migrate( $wpdb, Job_Schema::jobs_table( $wpdb ) );
			Durable_Charset::migrate( $wpdb, Job_Schema::items_table( $wpdb ) );
			Durable_Charset::migrate( $wpdb, Price_Apply_Journal::table( $wpdb ) );
			Job_Schema::assert_schema( $wpdb );
			Price_Apply_Journal::assert_schema( $wpdb );
		} catch ( \Throwable $error ) {
			$reason = $error instanceof Grant_Error ? $error->reason() : ( $error instanceof Job_Error ? $error->reason() : 'GRANT_STORE_UNAVAILABLE' );
			try { update_option( Grant_Schema::SETUP_OPTION, $reason, false ); } catch ( \Throwable $inner ) {}
			throw $error instanceof Grant_Error ? $error : new Grant_Error( 'GRANT_STORE_UNAVAILABLE' );
		}
	}
	private static function journal_ready( \wpdb $db ): bool {
		if ( (int) get_option( 'writeleash_price_journal_schema', 0 ) !== Price_Apply_Journal::SCHEMA_VERSION ) { return false; }
		try { $table = Price_Apply_Journal::table( $db ); }
		catch ( \Throwable $error ) { return false; }
		$exists = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
		if ( 1 !== $exists ) { return false; }
		try { Price_Apply_Journal::assert_schema( $db, false ); return true; }
		catch ( \Throwable $error ) { return false; }
	}

	// ------------------------------------------------------------------
	// Global disable.
	// ------------------------------------------------------------------

	/**
	 * Durable global kill-switch. `true` means restricted (grant-bound) writes
	 * are disabled. Fail-closed: missing/malformed option denies grant writes.
	 * Ordinary human jobs are unaffected; grant denial never falls back to an
	 * unrestricted save.
	 */
	public static function is_disabled(): bool {
		try {
			$value = get_option( Grant_Schema::DISABLE_OPTION, null );
		} catch ( \Throwable $error ) { return true; }
		// Only explicit '0' enables grants; absent/malformed fails closed.
		return '0' !== (string) $value;
	}

	/** Operator recovery: disable restricted writes. Requires product-management capability. */
	public static function disable( int $operator_id ): void {
		self::set_global_disabled( $operator_id, true );
	}

	/** Operator recovery: re-enable after the documented control. Same capability gate. */
	public static function enable( int $operator_id ): void {
		self::set_global_disabled( $operator_id, false );
	}

	/**
	 * Flip the durable kill-switch and append its audit event in one
	 * transaction. The option row is locked FOR UPDATE, so the flip waits for
	 * in-flight item transactions holding the SHARE lock and blocks new ones;
	 * a failed audit rolls the flip back (fail-closed). The direct option
	 * write bypasses the object cache, so the cache is always dropped after
	 * the transaction — a stale "enabled" read can never survive a rollback.
	 * The operator must be the authenticated principal.
	 */
	private static function set_global_disabled( int $operator_id, bool $disabled ): void {
		self::ensure_schema();
		self::require_human_self( $operator_id );
		$original = self::db();
		$tx = null;
		$committed = false;
		try {
			$tx = new Price_Apply_Connection( $original );
			$tx->begin();
			$value = $tx->get_var( $tx->prepare( 'SELECT option_value FROM %i WHERE option_name=%s FOR UPDATE', $tx->options, Grant_Schema::DISABLE_OPTION ) );
			if ( null === $value ) { throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' ); }
			$next = $disabled ? '1' : '0';
			if ( 1 !== $tx->query( $tx->prepare( 'UPDATE %i SET option_value=%s WHERE option_name=%s', $tx->options, $next, Grant_Schema::DISABLE_OPTION ) ) ) {
				throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' );
			}
			self::audit_in_tx( $tx, 0, 0, 0, 0, '', 0, $operator_id, 0, self::APPROVAL_NONE, '', $disabled ? 'GRANT_DISABLED' : 'GRANT_ENABLED', array( 'operator_id' => $operator_id ) );
			$tx->commit();
			$committed = true;
		} catch ( \Throwable $error ) {
			if ( ! $committed && $tx && $tx->owns_attempt() ) { $tx->rollback(); }
			throw $error instanceof Grant_Error ? $error : new Grant_Error( 'GRANT_STORE_UNAVAILABLE' );
		} finally {
			if ( $tx ) { $tx->rollback(); }
			self::flush_disable_cache();
		}
	}

	/** Drop cached copies of the kill-switch after a direct option write/rollback. */
	private static function flush_disable_cache(): void {
		try {
			wp_cache_delete( Grant_Schema::DISABLE_OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			$notoptions = wp_cache_get( 'notoptions', 'options' );
			if ( is_array( $notoptions ) && isset( $notoptions[ Grant_Schema::DISABLE_OPTION ] ) ) {
				unset( $notoptions[ Grant_Schema::DISABLE_OPTION ] );
				wp_cache_set( 'notoptions', $notoptions, 'options' );
			}
		} catch ( \Throwable $ignored ) {}
	}

	/**
	 * Lock the durable disable option row SHARE inside an item/acceptance
	 * transaction (indexed equality, held to COMMIT). Returns true when grants
	 * are enabled. Missing row fails closed (false). Follows Runner_Authority
	 * discipline: shutdown-style UPDATEs on the same row wait behind the lock.
	 */
	public static function lock_enabled( \wpdb $tx ): bool {
		if ( ! $tx instanceof Price_Apply_Connection || ! $tx->owns_attempt() ) { throw new Price_Apply_Error( 'TRANSACTION_LOST' ); }
		$engine = $tx->get_var( $tx->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $tx->options ) );
		$index = $tx->get_results( $tx->prepare( "SHOW INDEX FROM %i WHERE Key_name='option_name'", $tx->options ), ARRAY_A );
		if ( 'InnoDB' !== $engine || 1 !== count( $index ) || 'option_name' !== $index[0]['Column_name'] || '0' !== (string) $index[0]['Non_unique'] ) {
			throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' );
		}
		$value = $tx->get_var( $tx->prepare( 'SELECT option_value FROM %i WHERE option_name=%s LOCK IN SHARE MODE', $tx->options, Grant_Schema::DISABLE_OPTION ) );
		return '0' === (string) $value;
	}

	// ------------------------------------------------------------------
	// Grant lifecycle.
	// ------------------------------------------------------------------

	/**
	 * Create a standing grant. Issuer must hold manage_woocommerce +
	 * edit_products; issuer and actor must differ and both be >=1.
	 * Expiry must be an explicit future UTC datetime `Y-m-d H:i:s`.
	 * Budget is a grant-wide APPLIED ceiling (1..MAX_BUDGET).
	 */
	public static function create_grant( int $issuer_id, int $actor_id, int $budget_max, string $expires_at ): array {
		self::ensure_schema();
		self::require_human_self( $issuer_id );
		if ( $actor_id < 1 || $issuer_id === $actor_id ) { throw new Grant_Error( 'GRANT_IDENTITY_INVALID' ); }
		if ( $budget_max < 1 || $budget_max > self::MAX_BUDGET ) { throw new Grant_Error( 'GRANT_BUDGET_INVALID' ); }
		if ( 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $expires_at ) ) { throw new Grant_Error( 'GRANT_EXPIRY_INVALID' ); }
		// Expiry must be in the future at creation; comparison in UTC.
		if ( strtotime( $expires_at . ' UTC' ) <= time() ) { throw new Grant_Error( 'GRANT_EXPIRY_INVALID' ); }
		$original = self::db();
		$public_id = wp_generate_uuid4();
		$tx = null;
		$committed = false;
		try {
			$tx = new Price_Apply_Connection( $original );
			$tx->begin();
			$grants = Grant_Schema::grants_table( $tx );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Durable grant INSERT; values are prepared.
			if ( 1 !== $tx->query( $tx->prepare(
				"INSERT INTO %i (schema_version,public_id,version,issuer_id,actor_id,budget_max,budget_consumed,budget_reserved,expires_at,status,revoke_reason,created_at,updated_at) VALUES (%d,%s,%d,%d,%d,%d,0,0,%s,'active','',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
				$grants, Grant_Schema::SCHEMA_VERSION, $public_id, 1, $issuer_id, $actor_id, $budget_max, $expires_at
			) ) ) {
				throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' );
			}
			$grant_id = (int) $tx->insert_id;
			if ( $grant_id < 1 ) { throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' ); }
			// Audit is mandatory evidence: append it in the same transaction so
			// an unwritable audit store rolls the grant creation back.
			self::audit_in_tx( $tx, $grant_id, 1, 0, 0, '', $actor_id, $issuer_id, 0, self::APPROVAL_NONE, '', 'GRANT_CREATED', array( 'budget_max' => $budget_max, 'expires_at' => $expires_at, 'actor_id' => $actor_id, 'issuer_id' => $issuer_id ) );
			$tx->commit();
			$committed = true;
		} catch ( \Throwable $error ) {
			if ( ! $committed && $tx && $tx->owns_attempt() ) { $tx->rollback(); }
			throw $error instanceof Grant_Error ? $error : new Grant_Error( 'GRANT_STORE_UNAVAILABLE' );
		} finally { if ( $tx ) { $tx->rollback(); } }
		$grant = self::read_by_public_id( $public_id );
		if ( ! $grant ) { throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' ); }
		return $grant;
	}

	public static function read_by_public_id( string $public_id ): ?array {
		if ( ! preg_match( self::PUBLIC_ID_REGEX, $public_id ) ) { return null; }
		try { $db = self::db(); } catch ( \Throwable $error ) { return null; }
		try { $table = Grant_Schema::grants_table( $db ); } catch ( \Throwable $error ) { return null; }
		$row = $db->get_row( $db->prepare( 'SELECT * FROM %i WHERE public_id=%s', $table, $public_id ), ARRAY_A );
		return $row ?: null;
	}

	public static function read_db( int $grant_db_id ): ?array {
		if ( $grant_db_id < 1 ) { return null; }
		$db = self::db();
		$table = Grant_Schema::grants_table( $db );
		$row = $db->get_row( $db->prepare( 'SELECT * FROM %i WHERE id=%d', $table, $grant_db_id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Durable revocation. Only the issuer (or manage_options admin) holding
	 * product-management capability may revoke. Bumps version; pinned jobs
	 * keep their frozen version and are denied by status, not rewritten.
	 */
	public static function revoke( string $public_id, int $revoker_id, string $reason = 'OPERATOR_REVOKED' ): array {
		self::ensure_schema();
		if ( ! preg_match( '/\A[A-Z0-9_]{1,64}\z/', $reason ) ) { throw new Grant_Error( 'GRANT_REVOKE_INVALID' ); }
		$grant = self::read_by_public_id( $public_id );
		if ( ! $grant ) { throw new Grant_Error( 'GRANT_NOT_FOUND' ); }
		$revoker = self::require_human_self( $revoker_id );
		if ( $revoker_id !== (int) $grant['issuer_id'] && ! user_can( $revoker, 'manage_options' ) ) { throw new Grant_Error( 'PERMISSION_DENIED' ); }
		$original = self::db();
		$tx = null;
		$committed = false;
		try {
			$tx = new Price_Apply_Connection( $original );
			$tx->begin();
			$grants = Grant_Schema::grants_table( $tx );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Durable revocation CAS; values are prepared.
			$moved = $tx->query( $tx->prepare( "UPDATE %i SET status='revoked',revoke_reason=%s,revoked_at=UTC_TIMESTAMP(),version=version+1,updated_at=UTC_TIMESTAMP() WHERE public_id=%s AND status='active'", $grants, $reason, $public_id ) );
			if ( 1 !== $moved ) {
				// Revoking an already-revoked grant is an idempotent no-op that
				// changes no state, so it appends no new evidence.
				$row = $tx->get_row( $tx->prepare( 'SELECT * FROM %i WHERE public_id=%s', $grants, $public_id ), ARRAY_A );
				if ( is_array( $row ) && 'revoked' === $row['status'] ) { $tx->rollback(); return $row; }
				throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' );
			}
			$row = $tx->get_row( $tx->prepare( 'SELECT * FROM %i WHERE public_id=%s', $grants, $public_id ), ARRAY_A );
			if ( ! is_array( $row ) ) { throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' ); }
			// Audit shares the revocation transaction: a failed append rolls
			// the revoke back rather than losing the record silently.
			self::audit_in_tx( $tx, (int) $row['id'], (int) $row['version'], 0, 0, '', (int) $row['actor_id'], (int) $row['issuer_id'], 0, self::APPROVAL_NONE, '', 'GRANT_REVOKED', array( 'reason' => $reason, 'revoker_id' => $revoker_id ) );
			$tx->commit();
			$committed = true;
		} catch ( \Throwable $error ) {
			if ( ! $committed && $tx && $tx->owns_attempt() ) { $tx->rollback(); }
			throw $error instanceof Grant_Error ? $error : new Grant_Error( 'GRANT_STORE_UNAVAILABLE' );
		} finally { if ( $tx ) { $tx->rollback(); } }
		$grant = self::read_by_public_id( $public_id );
		if ( ! $grant || 'revoked' !== $grant['status'] ) { throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' ); }
		return $grant;
	}

	/**
	 * Later grant edit (expiry extension or budget change) bumps version.
	 * Never rewrites already-frozen jobs: jobs pin (public_id, version) at
	 * acceptance and keep it. Budget cannot drop below consumed+reserved.
	 */
	public static function update_grant( string $public_id, int $editor_id, ?string $expires_at = null, ?int $budget_max = null ): array {
		self::ensure_schema();
		$grant = self::read_by_public_id( $public_id );
		if ( ! $grant ) { throw new Grant_Error( 'GRANT_NOT_FOUND' ); }
		$editor = self::require_human_self( $editor_id );
		if ( $editor_id !== (int) $grant['issuer_id'] && ! user_can( $editor, 'manage_options' ) ) { throw new Grant_Error( 'PERMISSION_DENIED' ); }
		if ( null === $expires_at && null === $budget_max ) { throw new Grant_Error( 'GRANT_UPDATE_EMPTY' ); }
		if ( null !== $expires_at && 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $expires_at ) ) { throw new Grant_Error( 'GRANT_EXPIRY_INVALID' ); }
		if ( null !== $budget_max && ( $budget_max < 1 || $budget_max > self::MAX_BUDGET ) ) { throw new Grant_Error( 'GRANT_BUDGET_INVALID' ); }
		if ( null !== $budget_max && $budget_max < (int) $grant['budget_consumed'] + (int) $grant['budget_reserved'] ) { throw new Grant_Error( 'GRANT_BUDGET_INVALID' ); }
		$original = self::db();
		$grants = Grant_Schema::grants_table( $original );
		$tx = null;
		$committed = false;
		$before_expires = (string) $grant['expires_at'];
		$before_budget = (int) $grant['budget_max'];
		$new_expires = null;
		$new_budget = null;
		try {
			$tx = new Price_Apply_Connection( $original );
			$tx->begin();
			$row = $tx->get_row( $tx->prepare( 'SELECT * FROM %i WHERE public_id=%s FOR UPDATE', $grants, $public_id ), ARRAY_A );
			if ( ! $row ) { throw new Grant_Error( 'GRANT_NOT_FOUND' ); }
			$new_expires = null !== $expires_at ? $expires_at : $row['expires_at'];
			$new_budget = null !== $budget_max ? $budget_max : (int) $row['budget_max'];
			if ( $new_budget < (int) $row['budget_consumed'] + (int) $row['budget_reserved'] ) { throw new Grant_Error( 'GRANT_BUDGET_INVALID' ); }
			if ( 1 !== $tx->query( $tx->prepare( 'UPDATE %i SET expires_at=%s,budget_max=%d,version=version+1,updated_at=UTC_TIMESTAMP() WHERE public_id=%s', $grants, $new_expires, $new_budget, $public_id ) ) ) { throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' ); }
			// Evidence is mandatory and shares the edit transaction; the
			// pre-edit values are recorded explicitly so the audit is not
			// merely re-read from the mutated row.
			self::audit_in_tx( $tx, (int) $row['id'], (int) $row['version'] + 1, 0, 0, '', (int) $row['actor_id'], (int) $row['issuer_id'], 0, self::APPROVAL_NONE, '', 'GRANT_UPDATED', array( 'editor_id' => $editor_id, 'before' => array( 'expires_at' => $before_expires, 'budget_max' => $before_budget ), 'after' => array( 'expires_at' => $new_expires, 'budget_max' => $new_budget ) ) );
			$tx->commit();
			$committed = true;
		} catch ( \Throwable $error ) {
			if ( ! $committed && $tx && $tx->owns_attempt() ) { $tx->rollback(); }
			throw $error instanceof Grant_Error ? $error : new Grant_Error( 'GRANT_STORE_UNAVAILABLE' );
		} finally { if ( $tx ) { $tx->rollback(); } }
		$grant = self::read_by_public_id( $public_id );
		if ( ! $grant ) { throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' ); }
		return $grant;
	}

	// ------------------------------------------------------------------
	// Scope.
	// ------------------------------------------------------------------

	/**
	 * Grant scope allowlist: WriteLeash-owned regular/sale writes for
	 * published simple products and eligible variations, frozen concrete IDs
	 * and absolute targets. Rejects BLOCKED plans, non-CHANGING execution
	 * sets, unsupported types/states/fields/target forms. Never reruns
	 * selectors or percentage arithmetic; consumes only frozen strings.
	 *
	 * @throws Grant_Error with GRANT_SCOPE_DENIED on any boundary violation.
	 */
	public static function assert_scope( Change_Plan $plan ): int {
		$data = $plan->data();
		if ( 'BLOCKED' === ( $data['status'] ?? '' ) ) { throw new Grant_Error( 'GRANT_SCOPE_DENIED' ); }
		try { $field = $plan->price_field(); } catch ( \Throwable $error ) { throw new Grant_Error( 'GRANT_SCOPE_DENIED' ); }
		if ( ! in_array( $field, Price_Operation::FIELDS, true ) ) { throw new Grant_Error( 'GRANT_SCOPE_DENIED' ); }
		$changing = 0;
		foreach ( $data['items'] as $item ) {
			if ( 'CHANGING' !== ( $item['result'] ?? '' ) ) { continue; }
			++$changing;
			$snapshot = $item['snapshot'] ?? null;
			if ( ! is_array( $snapshot ) ) { throw new Grant_Error( 'GRANT_SCOPE_DENIED' ); }
			$is_simple = ! empty( $snapshot['core_simple'] ) && 'simple' === ( $snapshot['type'] ?? '' );
			$is_variation = ! empty( $snapshot['core_variation'] ) && 'variation' === ( $snapshot['type'] ?? '' );
			if ( ! $is_simple && ! $is_variation ) { throw new Grant_Error( 'GRANT_SCOPE_DENIED' ); }
			if ( $is_variation ) {
				if ( (int) ( $snapshot['parent_id'] ?? 0 ) < 1 || empty( $snapshot['parent_core_variable'] ) || 'variable' !== ( $snapshot['parent_type'] ?? '' ) || 'publish' !== ( $snapshot['parent_status'] ?? '' ) ) { throw new Grant_Error( 'GRANT_SCOPE_DENIED' ); }
			}
			if ( 'publish' !== ( $snapshot['status'] ?? '' ) ) { throw new Grant_Error( 'GRANT_SCOPE_DENIED' ); }
			if ( '' === ( $snapshot['regular_price'] ?? '' ) ) { throw new Grant_Error( 'GRANT_SCOPE_DENIED' ); }
			try { Price_Decimal::parse( $snapshot['regular_price'] ); } catch ( \Throwable $error ) { throw new Grant_Error( 'GRANT_SCOPE_DENIED' ); }
			$expected = $item['expected_regular_price'] ?? null;
			$planned = $item['planned_regular_price'] ?? null;
			$op_type = $data['operation']['type'] ?? null;
			$is_clear = Price_Operation::FIELD_SALE === $field && Price_Operation::CLEAR_SALE === $op_type && '' === $planned;
			if ( $is_clear ) {
				if ( '' !== $expected && null !== $expected ) {
					try { Price_Decimal::parse( $expected ); } catch ( \Throwable $error ) { throw new Grant_Error( 'GRANT_SCOPE_DENIED' ); }
				}
				continue;
			}
			if ( ! is_string( $expected ) || '' === $expected || ! is_string( $planned ) || '' === $planned ) { throw new Grant_Error( 'GRANT_SCOPE_DENIED' ); }
			try { Price_Decimal::parse( $expected ); Price_Decimal::parse( $planned ); } catch ( \Throwable $error ) { throw new Grant_Error( 'GRANT_SCOPE_DENIED' ); }
			// Absolute frozen targets only: percentage/selector recomputation
			// never happens here; Apply/Resume consume these strings as-is.
		}
		if ( $changing < 1 ) { throw new Grant_Error( 'GRANT_SCOPE_DENIED' ); }
		return $changing;
	}

	/** Canonical idempotency payload: plan hash + actor + grant. Same material => same hash. */
	public static function canonical_payload( Change_Plan $plan, string $grant_public_id ): string {
		$data = $plan->data();
		$material = array(
			'plan_hash' => $plan->hash(),
			'actor_id' => (int) $data['actor_id'],
			'grant' => $grant_public_id,
			'field' => $plan->price_field(),
			'targets' => array(),
		);
		foreach ( $data['items'] as $item ) {
			if ( 'CHANGING' !== ( $item['result'] ?? '' ) ) { continue; }
			$material['targets'][] = array( (int) $item['product_id'], (string) ( $item['planned_regular_price'] ?? '' ) );
		}
		sort( $material['targets'] );
		return Plan_Hasher::canonical_json( $material );
	}

	// ------------------------------------------------------------------
	// Acceptance: reserve + freeze atomically or rollback.
	// ------------------------------------------------------------------

	/**
	 * Grant acceptance: validate grant/scope/idempotency, atomically reserve
	 * budget, freeze the plan as a grant-bound READY job (approver 0, mode
	 * grant) with journal seed, or roll all acceptance records back.
	 *
	 * Same key + same canonical payload replays the original job without a
	 * second plan or reservation. Same key + different payload rejects.
	 * Concurrent identical submissions have one winner via the durable unique
	 * key. Budget denial causes zero Woo saves (no Woo path here at all).
	 *
	 * @return array{job: array, replayed: bool}
	 * @throws Grant_Error
	 */
	public static function accept_plan( Change_Plan $plan, string $grant_public_id, int $issuer_id, string $idempotency_key ): array {
		self::ensure_schema();
		if ( ! preg_match( self::PUBLIC_ID_REGEX, $grant_public_id ) ) { throw new Grant_Error( 'GRANT_NOT_FOUND' ); }
		if ( ! preg_match( self::IDEMPOTENCY_REGEX, $idempotency_key ) ) { throw new Grant_Error( 'GRANT_IDEMPOTENCY_INVALID' ); }
		// The authenticated caller must be the issuer (and hold product
		// management). The durable grant row is re-checked to the same issuer
		// inside the transaction, so a supplied id cannot impersonate.
		self::require_human_self( $issuer_id );
		$data = $plan->data();
		$actor_id = (int) ( $data['actor_id'] ?? 0 );
		if ( $actor_id < 1 || $actor_id === $issuer_id ) { throw new Grant_Error( 'GRANT_IDENTITY_INVALID' ); }
		$needed = self::assert_scope( $plan );
		$payload = self::canonical_payload( $plan, $grant_public_id );
		$hash = hash( 'sha256', $payload );

		$original = self::db();
		$tx = null;
		$committed = false;
		try {
			$tx = new Price_Apply_Connection( $original );
			$tx->begin();
			// Lock order for acceptance: disable option (SHARE), grant row
			// (FOR UPDATE), then job/journal inserts. No job row exists yet.
			$engine = $tx->get_var( $tx->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $tx->options ) );
			if ( 'InnoDB' !== $engine ) { throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' ); }
			$disable = $tx->get_var( $tx->prepare( 'SELECT option_value FROM %i WHERE option_name=%s LOCK IN SHARE MODE', $tx->options, Grant_Schema::DISABLE_OPTION ) );
			$grants = Grant_Schema::grants_table( $tx );
			$grant = $tx->get_row( $tx->prepare( 'SELECT * FROM %i WHERE public_id=%s FOR UPDATE', $grants, $grant_public_id ), ARRAY_A );
			if ( ! $grant ) { throw new Grant_Error( 'GRANT_NOT_FOUND' ); }
			if ( (int) $grant['issuer_id'] !== $issuer_id ) { throw new Grant_Error( 'GRANT_IDEMPOTENCY_MISMATCH' ); }
			if ( (int) $grant['actor_id'] !== $actor_id ) { throw new Grant_Error( 'GRANT_IDENTITY_INVALID' ); }
			// Idempotency is evaluated BEFORE any mutable grant state. A replay
			// of an already-accepted plan returns its original job even if the
			// grant has since been revoked, expired or globally disabled: it
			// creates no plan, no reservation and no new write authority (each
			// item is still denied at execution by the current grant state).
			$idem_table = Grant_Schema::idempotency_table( $tx );
			$existing_key = $tx->get_row( $tx->prepare( 'SELECT * FROM %i WHERE grant_db_id=%d AND idempotency_key=%s FOR UPDATE', $idem_table, (int) $grant['id'], $idempotency_key ), ARRAY_A );
			if ( $existing_key ) {
				if ( ! hash_equals( (string) $existing_key['canonical_hash'], $hash ) ) { throw new Grant_Error( 'GRANT_IDEMPOTENCY_MISMATCH' ); }
				if ( (int) $existing_key['issuer_id'] !== $issuer_id ) { throw new Grant_Error( 'GRANT_IDEMPOTENCY_MISMATCH' ); }
				if ( (string) $existing_key['plan_id'] !== (string) $data['plan_id'] ) { throw new Grant_Error( 'GRANT_IDEMPOTENCY_MISMATCH' ); }
				$job_id = (int) $existing_key['job_id'];
				$tx->rollback();
				$job = $job_id > 0 ? Job_Repository::read( $job_id ) : null;
				if ( ! $job ) { throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' ); }
				self::assert_job_grant_binding( $job, $grant_public_id, 0, $idempotency_key );
				// Stored-record integrity: the returned job must still carry
				// the original plan and a readable pinned version. A missing or
				// inconsistent record fails closed rather than minting a job.
				if ( (int) ( $job['grant_version'] ?? 0 ) < 1 ) { throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' ); }
				if ( (string) ( $job['plan_id'] ?? '' ) !== (string) $data['plan_id'] || (string) ( $job['plan_hash'] ?? '' ) !== $plan->hash() ) { throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' ); }
				if ( (int) ( $job['creator_id'] ?? 0 ) !== $actor_id ) { throw new Grant_Error( 'GRANT_IDENTITY_INVALID' ); }
				return array( 'job' => $job, 'replayed' => true );
			}
			// New acceptance only: now enforce current grant state.
			if ( '0' !== (string) $disable ) { throw new Grant_Error( 'GRANT_DISABLED' ); }
			if ( 'active' !== $grant['status'] ) { throw new Grant_Error( 'GRANT_REVOKED' ); }
			// Expiry is a durable UTC comparison inside the transaction.
			$now = $tx->get_var( 'SELECT UTC_TIMESTAMP()' );
			if ( ! is_string( $now ) || $now === '' || strcmp( (string) $grant['expires_at'], $now ) <= 0 ) { throw new Grant_Error( 'GRANT_EXPIRED' ); }
			// Atomic reservation: one conditional UPDATE, so concurrent jobs
			// cannot overbook the last unit. Zero rows => denial.
			$reserved = $tx->query( $tx->prepare(
				'UPDATE %i SET budget_reserved=budget_reserved+%d,version=version,updated_at=UTC_TIMESTAMP() WHERE id=%d AND status=%s AND expires_at>UTC_TIMESTAMP() AND budget_consumed+budget_reserved+%d<=budget_max',
				$grants, $needed, (int) $grant['id'], 'active', $needed
			) );
			if ( 1 !== $reserved ) {
				$fresh = $tx->get_row( $tx->prepare( 'SELECT status,expires_at,budget_max,budget_consumed,budget_reserved FROM %i WHERE id=%d', $grants, (int) $grant['id'] ), ARRAY_A );
				if ( ! $fresh || 'active' !== $fresh['status'] ) { throw new Grant_Error( 'GRANT_REVOKED' ); }
				throw new Grant_Error( 'GRANT_BUDGET_EXHAUSTED' );
			}
			// #298 test seam: no production listener. The callback may wait while
			// this transaction still holds the grant row lock. The writer
			// connection is not passed out.
			do_action( 'writeleash_grant_accept_checkpoint', 'AFTER_RESERVE', (int) $grant['id'], $idempotency_key );
			// Freeze the plan as a grant-bound job: READY, approver 0, mode
			// grant, pinned grant version/key. Change_Plan JSON itself is
			// untouched (immutable); the job row carries the pin.
			$jobs = Job_Schema::jobs_table( $tx );
			$existing_plan = $tx->get_row( $tx->prepare( 'SELECT id FROM %i WHERE plan_id=%s', $jobs, $data['plan_id'] ), ARRAY_A );
			if ( $existing_plan ) { throw new Grant_Error( 'GRANT_PLAN_EXISTS' ); }
			$store = $data['store'];
			$summary = $data['summary'];
			$status = 'BLOCKED' === $data['status'] ? Job_State::BLOCKED : Job_State::READY;
			if ( Job_State::BLOCKED === $status ) { throw new Grant_Error( 'GRANT_SCOPE_DENIED' ); }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Grant acceptance INSERT; values are prepared.
			if ( 1 !== $tx->query( $tx->prepare(
				"INSERT INTO %i (schema_version,public_id,plan_id,plan_schema_version,plan_hash_version,plan_hash,plan_json,creator_id,approver_id,approval_mode,grant_public_id,grant_version,grant_idempotency_key,status,status_reason,currency,price_decimals,wordpress_version,woocommerce_version,total_selected,total_eligible,total_changing,total_unchanged,total_unsupported,total_blocked,planned,pending,unchanged,unsupported,created_at,updated_at) VALUES (%d,%s,%s,%d,%s,%s,%s,%d,0,%s,%s,%d,%s,%s,%s,%s,%d,%s,%s,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
				$jobs, Job_Schema::SCHEMA_VERSION, wp_generate_uuid4(), $data['plan_id'], Change_Plan::SCHEMA_VERSION, Change_Plan::HASH_VERSION, $plan->hash(), $plan->json(), $actor_id, self::APPROVAL_GRANT, $grant_public_id, (int) $grant['version'], $idempotency_key, $status, 'GRANT_ACCEPTED', $store['currency'], (int) $store['price_decimals'], $store['wordpress_version'], $store['woocommerce_version'],
				(int) $summary['selected'], (int) $summary['eligible'], (int) $summary['changing'], (int) $summary['unchanged'], (int) $summary['unsupported'], (int) $summary['blocked'], (int) $summary['selected'], (int) $summary['changing'], (int) $summary['unchanged'], (int) $summary['unsupported']
			) ) ) {
				throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' );
			}
			$job_id = (int) $tx->insert_id;
			if ( $job_id < 1 ) { throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' ); }
			self::insert_job_items( $tx, $job_id, $plan );
			Job_Repository::refresh_counters( $tx, $job_id );
			// Journal seed shares this transaction: partial acceptance is not executable.
			$wpdb_backup = $GLOBALS['wpdb'];
			$GLOBALS['wpdb'] = $tx;
			try { Price_Apply_Journal::seed( $plan ); } finally { $GLOBALS['wpdb'] = $wpdb_backup; }
			// Per-item reservations in the same transaction (one winner).
			$res_table = Grant_Schema::reservations_table( $tx );
			foreach ( $data['items'] as $item ) {
				if ( 'CHANGING' !== ( $item['result'] ?? '' ) ) { continue; }
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reservation INSERT; values are prepared.
				if ( false === $tx->query( $tx->prepare(
					"INSERT INTO %i (schema_version,grant_db_id,job_id,plan_id,product_id,state,created_at,updated_at) VALUES (%d,%d,%d,%s,%d,'RESERVED',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
					$res_table, Grant_Schema::SCHEMA_VERSION, (int) $grant['id'], $job_id, $data['plan_id'], (int) $item['product_id']
				) ) ) { throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' ); }
			}
			// Durable idempotency binding in the same transaction.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Idempotency INSERT; values are prepared.
			if ( 1 !== $tx->query( $tx->prepare(
				'INSERT INTO %i (schema_version,grant_db_id,idempotency_key,issuer_id,canonical_hash,job_id,plan_id,created_at) VALUES (%d,%d,%s,%d,%s,%d,%s,UTC_TIMESTAMP())',
				$idem_table, Grant_Schema::SCHEMA_VERSION, (int) $grant['id'], $idempotency_key, $issuer_id, $hash, $job_id, $data['plan_id']
			) ) ) { throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' ); }
			self::audit_in_tx( $tx, (int) $grant['id'], (int) $grant['version'], $job_id, 0, $data['plan_id'], $actor_id, $issuer_id, 0, self::APPROVAL_GRANT, $idempotency_key, 'GRANT_ACCEPTED', array( 'reserved' => $needed, 'plan_hash' => $plan->hash(), 'targets' => self::targets_summary( $plan ) ) );
			$tx->commit();
			$committed = true;
			$GLOBALS['wpdb'] = $original;
			$job = Job_Repository::read( $job_id );
			if ( ! $job ) { throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' ); }
			return array( 'job' => $job, 'replayed' => false );
		} catch ( \Throwable $error ) {
			$GLOBALS['wpdb'] = $original;
			if ( ! $committed && $tx && $tx->owns_attempt() ) { $tx->rollback(); }
			if ( $error instanceof Grant_Error ) { throw $error; }
			throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' );
		} finally {
			$GLOBALS['wpdb'] = $original;
			if ( $tx ) { $tx->rollback(); }
		}
	}

	private static function insert_job_items( \wpdb $tx, int $job_id, Change_Plan $plan ): void {
		$items_table = Job_Schema::items_table( $tx );
		$sequence = 0;
		foreach ( $plan->data()['items'] as $item ) {
			++$sequence;
			$expected = $item['expected_regular_price'] ?? '';
			$planned = $item['planned_regular_price'] ?? '';
			$state = Job_Item_State::initial_for_result( $item['result'] );
			$reason = 'PENDING' === $state ? '' : ( 'UNCHANGED' === $state ? 'PLAN_UNCHANGED' : (string) ( $item['eligibility']['reason'] ?? 'PLAN_UNSUPPORTED' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Grant job import INSERT; values are prepared.
			if ( false === $tx->query( $tx->prepare(
				"INSERT INTO %i (schema_version,job_id,plan_id,product_id,sequence,plan_result,eligibility_state,eligibility_reason,blockers,warnings,expected_price,planned_price,state,reason,created_at,updated_at) VALUES (%d,%d,%s,%d,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id=id",
				$items_table, Job_Schema::SCHEMA_VERSION, $job_id, $plan->data()['plan_id'], (int) $item['product_id'], $sequence, $item['result'], $item['eligibility']['state'], $item['eligibility']['reason'], Plan_Hasher::canonical_json( $item['blockers'] ), Plan_Hasher::canonical_json( $item['warnings'] ), $expected, $planned, $state, $reason
			) ) ) {
				throw new Grant_Error( 'GRANT_STORE_UNAVAILABLE' );
			}
		}
	}

	private static function targets_summary( Change_Plan $plan ): array {
		$targets = array();
		foreach ( $plan->data()['items'] as $item ) {
			if ( 'CHANGING' !== ( $item['result'] ?? '' ) ) { continue; }
			$targets[] = array( 'product_id' => (int) $item['product_id'], 'expected' => (string) ( $item['expected_regular_price'] ?? '' ), 'planned' => (string) ( $item['planned_regular_price'] ?? '' ) );
		}
		return $targets;
	}

	/** Pinned grant binding on a grant job never changes after acceptance. */
	public static function assert_job_grant_binding( array $job, string $grant_public_id, int $grant_version, string $idempotency_key ): void {
		if ( self::APPROVAL_GRANT !== (string) ( $job['approval_mode'] ?? '' ) ) { throw new Grant_Error( 'GRANT_BINDING_MISMATCH' ); }
		if ( 0 !== (int) $job['approver_id'] ) { throw new Grant_Error( 'GRANT_BINDING_MISMATCH' ); }
		if ( (string) ( $job['grant_public_id'] ?? '' ) !== $grant_public_id ) { throw new Grant_Error( 'GRANT_BINDING_MISMATCH' ); }
		// Pinned version is frozen at acceptance; later grant edits bump the
		// grant row version without rewriting jobs. A replay supplies the
		// current grant version, which may exceed the pin: allow drift, only
		// refuse an unreadable pin. Exact-pin callers pass 0 to skip the check.
		if ( 0 !== $grant_version && (int) ( $job['grant_version'] ?? 0 ) < 1 ) { throw new Grant_Error( 'GRANT_BINDING_MISMATCH' ); }
		if ( '' !== $idempotency_key && (string) ( $job['grant_idempotency_key'] ?? '' ) !== $idempotency_key ) { throw new Grant_Error( 'GRANT_BINDING_MISMATCH' ); }
	}

	/** Whether a job row carries grant authority (v2 columns, non-empty pin). */
	public static function job_grant( array $job ): ?array {
		$public_id = (string) ( $job['grant_public_id'] ?? '' );
		if ( '' === $public_id || self::APPROVAL_GRANT !== (string) ( $job['approval_mode'] ?? '' ) ) { return null; }
		return array( 'public_id' => $public_id, 'version' => (int) ( $job['grant_version'] ?? 0 ), 'key' => (string) ( $job['grant_idempotency_key'] ?? '' ) );
	}

	/** Load the apply job row for a plan inside an item/acceptance transaction (no extra lock; guard already holds it). */
	public static function job_for_plan_in_tx( \wpdb $tx, string $plan_id ): ?array {
		try {
			$jobs = Job_Schema::jobs_table( $tx );
		} catch ( \Throwable $error ) { return null; }
		try { $row = $tx->get_row( $tx->prepare( 'SELECT * FROM %i WHERE plan_id=%s', $jobs, $plan_id ), ARRAY_A ); }
		catch ( \Throwable $error ) { return null; }
		return $row ?: null;
	}

	/** Append-only per-item audit inside the caller's transaction. Fail-closed on write failure. */
	public static function audit_item_in_tx( \wpdb $tx, int $grant_db_id, int $grant_version, int $job_id, int $product_id, string $plan_id, int $actor_id, int $issuer_id, int $approver_id, string $mode, string $key, string $event, array $details ): void {
		self::audit_in_tx( $tx, $grant_db_id, $grant_version, $job_id, $product_id, $plan_id, $actor_id, $issuer_id, $approver_id, $mode, $key, $event, $details );
	}

	// ------------------------------------------------------------------
	// Per-item authorization/write boundary.
	// ------------------------------------------------------------------

	/**
	 * Serialize grant validation with the item write. Call inside the #108
	 * item transaction AFTER the job fence (guard->acquire) and BEFORE the
	 * journal row lock. Lock order: runner (guard), job (guard),
	 * disable option (SHARE), grant row (FOR UPDATE), journal/item,
	 * Woo rows + CRUD. Missing/uncertain evidence fails closed with zero Woo
	 * writes. Revocation wins pre-boundary.
	 *
	 * @return array grant row (locked to COMMIT)
	 * @throws Price_Apply_Error with GRANT_* reason (mapped by the mutator).
	 */
	public static function assert_grant_for_write( \wpdb $tx, array $job, Change_Plan $plan, int $product_id ): array {
		$pin = self::job_grant( $job );
		if ( null === $pin ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
		try {
			if ( ! Grant_Schema::ready( $tx ) && ! Grant_Schema::ready( self::db() ) ) { throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' ); }
		} catch ( \Throwable $error ) { throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' ); }
		// Actor re-check happens in the mutator's authorize(); here we verify
		// the frozen actor still matches the grant's delegated actor.
		$actor = (int) $plan->data()['actor_id'];
		if ( $actor < 1 || $actor !== (int) $job['creator_id'] ) { throw new Price_Apply_Error( 'GRANT_IDENTITY_INVALID' ); }
		// Global disable (SHARE lock, held to COMMIT so disable waits).
		try {
			if ( ! self::lock_enabled( $tx ) ) { throw new Price_Apply_Error( 'GRANT_DISABLED' ); }
		} catch ( Price_Apply_Error $error ) { throw $error; } catch ( \Throwable $error ) { throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' ); }
		// Grant row (FOR UPDATE, held to COMMIT so revoke waits).
		try {
			$grants = Grant_Schema::grants_table( $tx );
			$grant = $tx->get_row( $tx->prepare( 'SELECT * FROM %i WHERE public_id=%s FOR UPDATE', $grants, $pin['public_id'] ), ARRAY_A );
		} catch ( \Throwable $error ) { throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' ); }
		if ( ! is_array( $grant ) ) { throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' ); }
		if ( (int) $grant['actor_id'] !== $actor ) { throw new Price_Apply_Error( 'GRANT_IDENTITY_INVALID' ); }
		if ( 'active' !== $grant['status'] ) { throw new Price_Apply_Error( 'GRANT_REVOKED' ); }
		try {
			$now = $tx->get_var( 'SELECT UTC_TIMESTAMP()' );
		} catch ( \Throwable $error ) { throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' ); }
		if ( ! is_string( $now ) || '' === $now || strcmp( (string) $grant['expires_at'], $now ) <= 0 ) { throw new Price_Apply_Error( 'GRANT_EXPIRED' ); }
		// Pinned version is audit evidence; current-row revocation/expiry/
		// disable/budget govern execution. Version unreadability fails closed.
		if ( (int) $grant['version'] < 1 ) { throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' ); }
		// RESERVED authorizes the write. CONSUMED lets the caller adopt a durable
		// APPLIED row without a second save. Any other state fails closed.
		try {
			$res_table = Grant_Schema::reservations_table( $tx );
			$res = $tx->get_row( $tx->prepare( 'SELECT * FROM %i WHERE grant_db_id=%d AND job_id=%d AND product_id=%d FOR UPDATE', $res_table, (int) $grant['id'], (int) $job['id'], $product_id ), ARRAY_A );
		} catch ( \Throwable $error ) { throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' ); }
		if ( ! is_array( $res ) || 'RELEASED' === (string) $res['state'] ) { throw new Price_Apply_Error( 'GRANT_BUDGET_EXHAUSTED' ); }
		if ( 'CONSUMED' === (string) $res['state'] ) { throw new Price_Apply_Error( 'GRANT_RESERVATION_CONSUMED' ); }
		if ( 'RESERVED' !== (string) $res['state'] ) { throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' ); }
		// Scope re-check on frozen material (no selector/percentage rerun).
		try { self::assert_scope_for_item( $plan, $product_id ); } catch ( Grant_Error $error ) { throw new Price_Apply_Error( 'GRANT_SCOPE_DENIED' ); } catch ( \Throwable $error ) { throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' ); }
		return $grant;
	}

	/** Frozen scope re-check for one product (no live catalog reads). */
	public static function assert_scope_for_item( Change_Plan $plan, int $product_id ): void {
		$item = $plan->item( $product_id )->data();
		if ( 'CHANGING' !== $item['result'] ) { throw new Grant_Error( 'GRANT_SCOPE_DENIED' ); }
		$field = $plan->price_field();
		$snapshot = $item['snapshot'];
		$is_simple = ! empty( $snapshot['core_simple'] ) && 'simple' === ( $snapshot['type'] ?? '' );
		$is_variation = ! empty( $snapshot['core_variation'] ) && 'variation' === ( $snapshot['type'] ?? '' );
		if ( ! $is_simple && ! $is_variation ) { throw new Grant_Error( 'GRANT_SCOPE_DENIED' ); }
		if ( 'publish' !== ( $snapshot['status'] ?? '' ) ) { throw new Grant_Error( 'GRANT_SCOPE_DENIED' ); }
		if ( $is_variation && ( (int) ( $snapshot['parent_id'] ?? 0 ) < 1 || empty( $snapshot['parent_core_variable'] ) ) ) { throw new Grant_Error( 'GRANT_SCOPE_DENIED' ); }
		$planned = $item['planned_regular_price'] ?? null;
		$op_type = $plan->data()['operation']['type'] ?? null;
		if ( Price_Operation::FIELD_SALE === $field && Price_Operation::CLEAR_SALE === $op_type && '' === $planned ) { return; }
		if ( ! is_string( $planned ) || '' === $planned ) { throw new Grant_Error( 'GRANT_SCOPE_DENIED' ); }
		try { Price_Decimal::parse( $planned ); } catch ( \Throwable $error ) { throw new Grant_Error( 'GRANT_SCOPE_DENIED' ); }
	}

	/**
	 * Consume one reservation exactly once when the journal durably reaches
	 * APPLIED. Call inside the SAME item transaction AFTER the journal
	 * APPLIED transition and BEFORE COMMIT. Idempotent via the reservation
	 * state CAS (RESERVED -> CONSUMED); already-CONSUMED is a no-op success.
	 */
	public static function consume_in_tx( \wpdb $tx, array $grant, int $job_id, int $product_id ): bool {
		$res_table = Grant_Schema::reservations_table( $tx );
		$grants = Grant_Schema::grants_table( $tx );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Grant consume CAS; values are prepared.
		$moved = $tx->query( $tx->prepare( "UPDATE %i SET state='CONSUMED',updated_at=UTC_TIMESTAMP() WHERE grant_db_id=%d AND job_id=%d AND product_id=%d AND state='RESERVED'", $res_table, (int) $grant['id'], $job_id, $product_id ) );
		if ( 1 === $moved ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Grant budget consume; values are prepared.
			if ( 1 !== $tx->query( $tx->prepare( 'UPDATE %i SET budget_reserved=IF(budget_reserved>0,budget_reserved-1,0),budget_consumed=budget_consumed+1,updated_at=UTC_TIMESTAMP() WHERE id=%d', $grants, (int) $grant['id'] ) ) ) {
				throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' );
			}
			return true;
		}
		$state = $tx->get_var( $tx->prepare( 'SELECT state FROM %i WHERE grant_db_id=%d AND job_id=%d AND product_id=%d', $res_table, (int) $grant['id'], $job_id, $product_id ) );
		if ( 'CONSUMED' === (string) $state ) { return false; }
		throw new Price_Apply_Error( 'GRANT_BUDGET_EXHAUSTED' );
	}

	/**
	 * Release one reservation on a definitive non-applied terminal outcome
	 * (CONFLICT, definitive FAILED). Call in the refusal transaction that
	 * persists the journal terminal state. Already-RELEASED/CONSUMED is a
	 * no-op; ambiguous outcomes must not call this (retain RESERVED).
	 */
	public static function release_in_tx( \wpdb $tx, int $grant_db_id, int $job_id, int $product_id ): bool {
		$res_table = Grant_Schema::reservations_table( $tx );
		$grants = Grant_Schema::grants_table( $tx );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Grant release CAS; values are prepared.
		$moved = $tx->query( $tx->prepare( "UPDATE %i SET state='RELEASED',updated_at=UTC_TIMESTAMP() WHERE grant_db_id=%d AND job_id=%d AND product_id=%d AND state='RESERVED'", $res_table, $grant_db_id, $job_id, $product_id ) );
		if ( false === $moved ) { throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' ); }
		if ( 1 === $moved ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Grant budget release; values are prepared.
			if ( 1 !== $tx->query( $tx->prepare( 'UPDATE %i SET budget_reserved=IF(budget_reserved>0,budget_reserved-1,0),updated_at=UTC_TIMESTAMP() WHERE id=%d', $grants, $grant_db_id ) ) ) {
				throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' );
			}
			return true;
		}
		return false;
	}

	/**
	 * Reconcile retained ambiguous reservations after journal truth is known.
	 * APPLIED consumes once; definitive non-applied releases; otherwise the
	 * reservation stays RESERVED. Best-effort; failures fail closed to retain.
	 */
	public static function reconcile_item( int $grant_db_id, int $job_id, string $plan_id, int $product_id ): string {
		$observer = null;
		try { $observer = Price_Cache_Verifier::observer(); } catch ( \Throwable $error ) { return 'RETAINED'; }
		try { $journal = Price_Apply_Journal::read( $observer, $plan_id, $product_id ); }
		catch ( \Throwable $error ) { return 'RETAINED'; }
		finally { if ( $observer ) { try { $observer->close(); } catch ( \Throwable $ignored ) {} } }
		// Evidence fields come from the immutable job pin, never the current
		// grant row, so reconciliation preserves the original actor/version
		// even after the grant has been edited.
		$job = Job_Repository::read( $job_id );
		$pin = $job ? self::job_grant( $job ) : null;
		$actor_id = $job ? (int) $job['creator_id'] : 0;
		$pinned_version = $pin ? (int) $pin['version'] : 0;
		$key = $pin ? (string) $pin['key'] : '';
		// Use a short transaction for the grant-side CAS + evidence append.
		$original = self::db();
		$tx = null;
		try {
			$tx = new Price_Apply_Connection( $original );
			$tx->begin();
			$grants = Grant_Schema::grants_table( $tx );
			$grant = $tx->get_row( $tx->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', $grants, $grant_db_id ), ARRAY_A );
			if ( ! $grant ) { $tx->rollback(); return 'RETAINED'; }
			$version = $pinned_version > 0 ? $pinned_version : (int) $grant['version'];
			if ( $journal && 'APPLIED' === $journal['state'] ) {
				try {
					if ( self::consume_in_tx( $tx, $grant, $job_id, $product_id ) ) {
						self::audit_item_in_tx( $tx, (int) $grant['id'], $version, $job_id, $product_id, $plan_id, $actor_id, (int) $grant['issuer_id'], 0, self::APPROVAL_GRANT, $key, 'ITEM_RECONCILED', array( 'source' => 'RECONCILE', 'journal' => 'APPLIED' ) );
					}
				} catch ( \Throwable $error ) { $tx->rollback(); return 'RETAINED'; }
				$tx->commit();
				return 'CONSUMED';
			}
			if ( $journal && in_array( $journal['state'], array( 'CONFLICT', 'FAILED' ), true ) ) {
				if ( self::release_in_tx( $tx, $grant_db_id, $job_id, $product_id ) ) {
					self::audit_item_in_tx( $tx, (int) $grant['id'], $version, $job_id, $product_id, $plan_id, $actor_id, (int) $grant['issuer_id'], 0, self::APPROVAL_GRANT, $key, 'ITEM_RELEASED', array( 'source' => 'RECONCILE', 'journal' => (string) $journal['state'] ) );
				}
				$tx->commit();
				return 'RELEASED';
			}
			$tx->rollback();
			return 'RETAINED';
		} catch ( \Throwable $error ) {
			if ( $tx ) { $tx->rollback(); }
			return 'RETAINED';
		} finally { if ( $tx ) { $tx->rollback(); } }
	}

	// ------------------------------------------------------------------
	// Audit (append-only where feasible).
	// ------------------------------------------------------------------

	private static function audit_in_tx( \wpdb $tx, int $grant_db_id, int $grant_version, int $job_id, int $product_id, string $plan_id, int $actor_id, int $issuer_id, int $approver_id, string $mode, string $key, string $event, array $details ): void {
		// #298 test seam: no production listener. A throw here must roll the
		// caller's transaction back. The writer connection is not passed out.
		do_action( 'writeleash_grant_audit_before_insert', $event, $grant_db_id, $job_id, $product_id );
		$audit = Grant_Schema::audit_table( $tx );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Append-only audit INSERT; values are prepared.
		if ( 1 !== $tx->query( $tx->prepare(
			'INSERT INTO %i (schema_version,grant_db_id,grant_version,job_id,product_id,plan_id,actor_id,issuer_id,approver_id,approval_mode,idempotency_key,event,details,created_at) VALUES (%d,%d,%d,%d,%d,%s,%d,%d,%d,%s,%s,%s,%s,UTC_TIMESTAMP())',
			$audit, Grant_Schema::SCHEMA_VERSION, $grant_db_id, $grant_version, $job_id, $product_id, $plan_id, $actor_id, $issuer_id, $approver_id, $mode, $key, $event, Plan_Hasher::canonical_json( $details )
		) ) ) { throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' ); }
	}

	/** Operator-facing identity copy. Never renders approver 0 as a person. */
	public static function operator_label( array $job ): string {
		$mode = (string) ( $job['approval_mode'] ?? self::APPROVAL_NONE );
		if ( self::APPROVAL_GRANT === $mode ) {
			$grant = (string) ( $job['grant_public_id'] ?? '' );
			$short = '' !== $grant ? substr( $grant, 0, 8 ) : 'unknown';
			return 'Grant-authorized (no human approval) · grant ' . $short . ' · actor #' . (int) $job['creator_id'];
		}
		if ( (int) ( $job['approver_id'] ?? 0 ) > 0 ) {
			return 'Approved by user #' . (int) $job['approver_id'];
		}
		return 'Awaiting human approval';
	}
}
