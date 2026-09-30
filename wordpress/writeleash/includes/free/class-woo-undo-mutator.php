<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * One Undo item: restore the stored pre-apply regular price through Woo CRUD.
 *
 * This is the only Undo mutation primitive. It mirrors the #108 apply
 * transaction class-for-class: same short per-item transaction on the same
 * connection, same row-lock discipline, same cache invalidation and the same
 * fresh-observer certification. It never issues direct `_regular_price` SQL
 * as the product path and never rewrites apply history.
 *
 * Undo is NOT rollback: exactly one stored regular-price value WriteLeash
 * previously changed is restored. Emails, webhooks, remote calls, orders and
 * arbitrary plugin side effects are outside this contract; a second Woo save
 * during Undo can itself fire third-party hooks again.
 */
final class Woo_Undo_Mutator {
	private static function checkpoint( string $point, int $id, string $attempt ): void {
		do_action( 'writeleash_undo_checkpoint', $point, $id, $attempt );
	}

	private static function schema( \wpdb $db ): void {
		if ( ! defined( 'WC_VERSION' ) || ! function_exists( 'wc_get_product' ) || ! did_action( 'woocommerce_init' ) || is_multisite() || 'wpdb' !== get_class( $db ) || '11.1.2' !== WC_VERSION ) { throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
		Price_Apply_Journal::assert_schema( $db );
		Undo_Schema::assert_schema( $db );
		Job_Schema::assert_schema( $db );
		foreach ( array( $db->posts, $db->postmeta, $db->wc_product_meta_lookup, Price_Apply_Journal::table( $db ), Undo_Schema::items_table( $db ), Undo_Schema::operations_table( $db ), Job_Schema::jobs_table( $db ), Job_Schema::items_table( $db ), $db->options, $db->users, $db->usermeta ) as $table ) {
			if ( ! preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
			$engine = $db->get_var( $db->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
			if ( 'InnoDB' !== $engine ) { throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
		}
	}

	private static function authorize( int $product_id ): void {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) { throw new Price_Apply_Error( 'PERMISSION_DENIED' ); }
		$user = new \WP_User( $user_id );
		if ( ! user_can( $user, 'manage_woocommerce' ) || ! user_can( $user, 'edit_products' ) || ! user_can( $user, 'edit_post', $product_id ) ) { throw new Price_Apply_Error( 'PERMISSION_DENIED' ); }
	}

	/** Diagnostic write after rollback, separately locked; never demote UNDONE. */
	private static function refusal( int $job_id, int $undo_id, int $product_id, string $state, string $reason, string $attempt ): void {
		$db = Price_Cache_Verifier::observer();
		try {
			$db->query( 'START TRANSACTION' );
			$row = Undo_Repository::read_item_locked( $db, $job_id, $product_id );
			if ( $row ) {
				try { Undo_Repository::assert_item_binding_lite( $db, $row ); }
				catch ( Undo_Error $error ) { $db->query( 'ROLLBACK' ); return; }
			}
			if ( $row && in_array( $row['state'], array( Undo_Item_State::PENDING, Undo_Item_State::APPLYING ), true ) ) {
				Undo_Item_State::assert_transition( $row['state'], $state );
				Undo_Repository::transition_item( $db, $row, $state, $reason, $attempt );
			}
			if ( false === $db->query( 'COMMIT' ) ) { throw new Price_Apply_Error( 'AMBIGUOUS_COMMIT' ); }
		} finally { $db->close(); }
	}

	/**
	 * Restore the stored pre-apply regular price for one journal-proven APPLIED item.
	 *
	 * @return array{code: string, reason: string, attempt_id: string}
	 */
	public static function restore( int $job_id, int $undo_id, int $product_id, string $attempt, ?Price_Apply_Transaction_Guard $guard = null ): array {
		global $wpdb;
		$original = $wpdb;
		$tx = null;
		$committed = false;
		$code = 'FAILED';
		$reason = 'FAILED';
		$job = null;
		$plan = null;
		try {
			self::schema( $original );
			$job = Job_Repository::read( $job_id );
			if ( ! $job ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			try { $plan = Job_Repository::hydrate_plan( $job ); }
			catch ( Job_Error $error ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			if ( ! in_array( $job['status'], array( Job_State::COMPLETED, Job_State::COMPLETED_WITH_ISSUES ), true ) ) { throw new Price_Apply_Error( 'UNDO_NOT_ELIGIBLE' ); }
			if ( Undo_Repository::retention_expired_for( $job, Undo_Repository::read_operation( $undo_id ) ) ) { throw new Price_Apply_Error( 'UNDO_EXPIRED' ); }
			$tx = new Price_Apply_Connection( $original );
			$tx->begin();
			$wpdb = $tx;
			// Fenced guard first: Undo-operation row lock, then the shared apply
			// job-row lock, both held to COMMIT. See Undo_Transaction_Fence.
			if ( $guard ) { $guard->acquire( $tx ); }
			$undo = Undo_Repository::read_item_locked( $tx, $job_id, $product_id );
			if ( ! $undo ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			if ( (int) $undo['undo_id'] !== $undo_id ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			Undo_Repository::assert_item_binding( $tx, $undo, $plan, $job );
			// The worker claim already moved this row to UNDO_APPLYING; the
			// terminal transition below shares this same transaction, so a
			// crash before COMMIT provably leaves no durable Undo mutation.
			self::checkpoint( 'UNDO_ITEM_LOCKED', $product_id, $attempt );
			if ( Undo_Item_State::UNDONE === $undo['state'] ) {
				$tx->rollback();
				$wpdb = $original;
				self::observe_undo( $job, $undo_id, $product_id, $undo['attempt_id'] );
				return array( 'code' => 'ALREADY_UNDONE', 'reason' => 'DURABLE_UNDONE', 'attempt_id' => $undo['attempt_id'] );
			}
			if ( Undo_Item_State::PENDING !== $undo['state'] && Undo_Item_State::APPLYING !== $undo['state'] ) {
				$tx->rollback();
				return array( 'code' => $undo['state'], 'reason' => $undo['reason'], 'attempt_id' => $undo['attempt_id'] );
			}
			$provenance = json_decode( $undo['provenance'], true );
			if ( ! is_array( $provenance ) ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			if ( Undo_Fingerprint::fingerprint_of( $provenance ) !== $undo['fingerprint'] ) { throw new Price_Apply_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
			$post = $tx->get_row( $tx->prepare( "SELECT ID FROM {$tx->posts} WHERE ID=%d FOR UPDATE", $product_id ) );
			if ( ! $post ) { throw new Price_Apply_Error( 'PRODUCT_MISSING' ); }
			// Lock all product meta (including absent-key ranges), lookup and type terms.
			$truth = Price_Cache_Verifier::storage( $tx, $product_id, true );
			$tx->get_results( $tx->prepare( "SELECT * FROM {$tx->term_relationships} WHERE object_id=%d FOR UPDATE", $product_id ) );
			$role_key = $tx->get_blog_prefix() . 'user_roles';
			$tx->get_results( $tx->prepare( "SELECT option_id FROM {$tx->options} WHERE option_name IN ('woocommerce_currency','woocommerce_price_num_decimals',%s) FOR UPDATE", $role_key ) );
			$executor = get_current_user_id();
			$tx->get_results( $tx->prepare( "SELECT ID FROM {$tx->users} WHERE ID=%d FOR UPDATE", $executor ) );
			$tx->get_results( $tx->prepare( "SELECT umeta_id FROM {$tx->usermeta} WHERE user_id=%d FOR UPDATE", $executor ) );
			wp_cache_delete( 'alloptions', 'options' );
			foreach ( array( 'woocommerce_currency', 'woocommerce_price_num_decimals', $role_key ) as $key ) { wp_cache_delete( $key, 'options' ); }
			Price_Cache_Verifier::invalidate( $product_id );
			$product = wc_get_product( $product_id );
			if ( ! $product || 'WC_Product_Data_Store_CPT' !== $product->get_data_store()->get_current_class_name() ) { throw new Price_Apply_Error( 'UNSUPPORTED_PRODUCT_STATE' ); }
			$snapshot = Product_Price_Snapshot::read( $product_id, $product );
			$fresh = self::fresh_facts( $product_id, $product, $snapshot, $truth );
			$check = Undo_Fingerprint::verify( $provenance, $fresh );
			if ( ! $check['match'] ) { throw new Price_Apply_Error( self::conflict_code( $check['reasons'] ) ); }
			Price_Cache_Verifier::matches( $truth, $provenance['applied_price'] );
			// Recheck capabilities at the mutation boundary, not as WP root.
			self::authorize( $product_id );
			self::checkpoint( 'UNDO_BEFORE_WOO_SAVE', $product_id, $attempt );
			$tx->assert_owned();
			$product->set_regular_price( $provenance['expected_price'] );
			$product->save();
			self::checkpoint( 'UNDO_AFTER_WOO_SAVE_BEFORE_JOURNAL', $product_id, $attempt );
			$tx->assert_owned();
			$after = Price_Cache_Verifier::storage( $tx, $product_id );
			Price_Cache_Verifier::matches( $after, $provenance['expected_price'] );
			$evidence = array(
				'attempt_id' => $attempt,
				'job_id' => $job_id,
				'undo_id' => $undo_id,
				'plan_id' => $provenance['plan_id'],
				'product_id' => $product_id,
				'from_applied_price' => $provenance['applied_price'],
				'restored_price' => $provenance['expected_price'],
				'apply_attempt_id' => $provenance['apply_attempt_id'],
				'executor_id' => $executor,
				'connection_id' => $tx->id(),
				'regular' => $after['meta']['_regular_price'][0],
				'active' => $after['meta']['_price'][0],
				'lookup_min' => $after['lookup']['min_price'],
				'lookup_max' => $after['lookup']['max_price'],
			);
			if ( $guard && $guard->connection_id() > 0 ) { $evidence['fence_connection_id'] = $guard->connection_id(); }
			$evidence = Plan_Hasher::canonical_json( $evidence );
			Undo_Repository::transition_item( $tx, array_merge( $undo, array( 'state' => Undo_Item_State::APPLYING ) ), Undo_Item_State::UNDONE, 'UNDO_RESTORED', $attempt, $evidence );
			self::checkpoint( 'UNDO_AFTER_JOURNAL_BEFORE_COMMIT', $product_id, $attempt );
			$tx->commit();
			$committed = true;
			$wpdb = $original;
			self::checkpoint( 'UNDO_AFTER_COMMIT_BEFORE_RESPONSE', $product_id, $attempt );
			self::observe_undo( $job, $undo_id, $product_id, $attempt );
			return array( 'code' => 'UNDONE', 'reason' => 'UNDO_RESTORED', 'attempt_id' => $attempt );
		} catch ( \Throwable $e ) {
			$reason = $e instanceof Price_Apply_Error ? $e->getMessage() : ( $e instanceof Undo_Error ? $e->reason() : 'FAILED' );
			if ( $committed && 'FAILED' === $reason ) { $reason = 'AMBIGUOUS_COMMIT'; }
			$had_transaction = $tx && $tx->owns_attempt();
			$rolled_back = $tx ? $tx->rollback() : false;
			if ( $had_transaction && ! $rolled_back && 'AMBIGUOUS_COMMIT' !== $reason ) { $reason = 'TRANSACTION_LOST'; }
			$wpdb = $original;
			$review = $committed || in_array( $reason, array( 'TRANSACTION_LOST', 'AMBIGUOUS_COMMIT', 'CACHE_VERIFICATION_FAILED', 'LOOKUP_MISMATCH', 'JOURNAL_MISMATCH', 'UNDO_PROVENANCE_MISMATCH' ), true );
			$code = $review ? 'NEEDS_REVIEW' : ( in_array( $reason, array( 'UNDO_CONFLICT', 'PRODUCT_MISSING', 'PRODUCT_TYPE_CHANGED', 'PRODUCT_STATUS_CHANGED', 'SALE_CONFIGURED', 'UNDO_EXPIRED', 'UNDO_NOT_ELIGIBLE', 'PERMISSION_DENIED', 'UNSUPPORTED_PRODUCT_STATE', 'TRANSACTION_UNAVAILABLE', 'FENCE_LOST' ), true ) ? $reason : 'FAILED' );
			if ( in_array( $code, array( 'UNDO_CONFLICT', 'PRODUCT_MISSING', 'PRODUCT_TYPE_CHANGED', 'PRODUCT_STATUS_CHANGED', 'SALE_CONFIGURED' ), true ) ) { $code = 'UNDO_CONFLICT'; }
			try {
				// Connection loss: cleanup uses independent live DB, never a dead writer.
				$observer = Price_Cache_Verifier::observer();
				try { $wpdb = $observer; Price_Cache_Verifier::invalidate( $product_id ); } finally { $wpdb = $original; $observer->close(); }
				if ( $tx && ( $rolled_back || $review ) && null !== $job ) {
					// Known rollback FAILED/FENCE_LOST/TRANSACTION_UNAVAILABLE remains
					// UNDO_PENDING for an explicit retry or the authoritative newer
					// generation. Review never auto-retries. Conflict is terminal
					// with zero overwrite.
					$state = $review ? Undo_Item_State::NEEDS_REVIEW : ( in_array( $code, array( 'FAILED', 'FENCE_LOST', 'TRANSACTION_UNAVAILABLE' ), true ) ? Undo_Item_State::PENDING : ( 'UNDO_CONFLICT' === $code ? Undo_Item_State::CONFLICT : Undo_Item_State::FAILED ) );
					self::refusal( $job_id, $undo_id, $product_id, $state, $reason, $attempt );
				}
			} catch ( \Throwable $cleanup ) { $code = 'NEEDS_REVIEW'; $reason = 'CACHE_VERIFICATION_FAILED'; }
			return array( 'code' => $code, 'reason' => $reason, 'attempt_id' => $attempt );
		} finally { $wpdb = $original; }
	}

	/** Map fingerprint mismatch reasons to one stable typed code. */
	private static function conflict_code( array $reasons ): string {
		foreach ( array( 'product_identity_changed' => 'PRODUCT_MISSING', 'product_type_changed' => 'PRODUCT_TYPE_CHANGED', 'product_status_changed' => 'PRODUCT_STATUS_CHANGED', 'sale_configuration_changed' => 'SALE_CONFIGURED' ) as $needle => $code ) {
			if ( in_array( $needle, $reasons, true ) ) { return $code; }
		}
		return 'UNDO_CONFLICT';
	}

	/** Fresh mutation-boundary facts keyed like `Undo_Fingerprint::fields()`. */
	private static function fresh_facts( int $product_id, \WC_Product $product, Product_Price_Snapshot $snapshot, array $truth ): array {
		global $wp_version;
		$s = $snapshot->data();
		$from = $product->get_date_on_sale_from( 'edit' );
		$to = $product->get_date_on_sale_to( 'edit' );
		return array(
			'product_id' => $product_id,
			'applied_price' => (string) $product->get_regular_price( 'edit' ),
			'active_price' => (string) $product->get_price( 'edit' ),
			'product_type' => $s['type'],
			'core_simple' => $s['core_simple'],
			'status' => $product->get_status( 'edit' ),
			'sale_price' => $product->get_sale_price( 'edit' ),
			'sale_from' => $from ? (string) $from->getTimestamp() : null,
			'sale_to' => $to ? (string) $to->getTimestamp() : null,
			'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'price_decimals' => function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : -1,
			'lookup_min' => $truth['lookup']['min_price'],
			'lookup_max' => $truth['lookup']['max_price'],
			'lookup_onsale' => (string) $truth['lookup']['onsale'],
			'wordpress_version' => (string) $wp_version,
			'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
		);
	}

	/**
	 * DB truth first; Woo reads use a NEW object and independent DB connection.
	 * Never certifies UNDONE from the mutating Woo object.
	 */
	public static function observe_undo( array $job, int $undo_id, int $product_id, string $attempt ): array {
		$db = Price_Cache_Verifier::observer();
		$original = $GLOBALS['wpdb'];
		try {
			$undo = Undo_Repository::read_item( $db, (int) $job['id'], $product_id );
			if ( ! $undo || Undo_Item_State::UNDONE !== $undo['state'] || (int) $undo['undo_id'] !== $undo_id || $undo['attempt_id'] !== $attempt ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			$provenance = json_decode( $undo['provenance'], true );
			$evidence = json_decode( $undo['evidence'], true );
			if ( ! is_array( $provenance ) || ! is_array( $evidence ) ||
				( $evidence['job_id'] ?? 0 ) !== (int) $job['id'] || ( $evidence['undo_id'] ?? 0 ) !== $undo_id ||
				( $evidence['product_id'] ?? 0 ) !== $product_id || ( $evidence['attempt_id'] ?? '' ) !== $attempt ||
				( $evidence['from_applied_price'] ?? '' ) !== $provenance['applied_price'] || ( $evidence['restored_price'] ?? '' ) !== $provenance['expected_price'] ||
				( $evidence['connection_id'] ?? 0 ) < 1 ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			foreach ( array( 'regular', 'active', 'lookup_min', 'lookup_max' ) as $key ) {
				try { $match = isset( $evidence[$key] ) && Price_Decimal::parse( $evidence[$key] ) === Price_Decimal::parse( $provenance['expected_price'] ); }
				catch ( \Throwable $error ) { $match = false; }
				if ( ! $match ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			}
			$truth = Price_Cache_Verifier::storage( $db, $product_id );
			Price_Cache_Verifier::matches( $truth, $provenance['expected_price'] );
			$GLOBALS['wpdb'] = $db;
			Price_Cache_Verifier::invalidate( $product_id );
			$product = wc_get_product( $product_id );
			if ( ! $product || 'WC_Product_Simple' !== get_class( $product ) || 'publish' !== $product->get_status( 'edit' ) || '' !== $product->get_sale_price( 'edit' ) || $product->get_date_on_sale_from( 'edit' ) || $product->get_date_on_sale_to( 'edit' ) || Price_Decimal::parse( $product->get_regular_price( 'edit' ) ) !== Price_Decimal::parse( $provenance['expected_price'] ) || Price_Decimal::parse( $product->get_price( 'edit' ) ) !== Price_Decimal::parse( $provenance['expected_price'] ) ) { throw new Price_Apply_Error( 'CACHE_VERIFICATION_FAILED' ); }
			return array( 'undo' => $undo, 'storage' => $truth, 'observer_connection' => (int) $db->dbh->thread_id );
		} catch ( \Throwable $error ) {
			if ( $error instanceof Price_Apply_Error ) { throw $error; }
			throw new Price_Apply_Error( 'CACHE_VERIFICATION_FAILED' );
		} finally { $GLOBALS['wpdb'] = $original; $db->close(); }
	}
}
