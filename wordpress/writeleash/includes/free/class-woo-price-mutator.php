<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/** One item of a trusted #107 plan. No approval/UI/queue/Undo entry point. */
final class Woo_Price_Mutator {
	private static function checkpoint( string $point, int $id, string $attempt ): void {
		do_action( 'writeleash_price_apply_checkpoint', $point, $id, $attempt );
	}
	private static function schema( \wpdb $db ): void {
		$reason = Free_Support_Contract::execution_reason( $db );
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Typed machine reason code; not HTML output.
		if ( null !== $reason ) { throw new Price_Apply_Error( Free_Support_Contract::item_reason( $reason ) ); }
		Price_Apply_Journal::assert_schema( $db );
		foreach ( array( $db->posts, $db->postmeta, $db->wc_product_meta_lookup, Price_Apply_Journal::table( $db ), $db->options, $db->users, $db->usermeta, $db->term_relationships, $db->term_taxonomy, $db->terms ) as $table ) {
			if ( ! preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
			$engine = $db->get_var( $db->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
			// A non-transactional engine cannot hold the item transaction:
			// refuse with an actionable reason instead of a generic one.
			if ( 'InnoDB' !== $engine ) { throw new Price_Apply_Error( 'DB_TRANSACTIONS_UNSUPPORTED' ); }
		}
	}
	private static function authorize( Change_Plan $plan, int $id, \wpdb $db ): void {
		$actor = $plan->data()['actor_id'];
		// The actor/role rows were locked before any consistent snapshot read.
		wp_roles()->for_site( get_current_blog_id() );
		clean_user_cache( $actor );
		$user = new \WP_User( $actor );
		if ( get_current_user_id() !== $actor || ! user_can( $user, 'manage_woocommerce' ) || ! user_can( $user, 'edit_products' ) || ! user_can( $user, 'edit_post', $id ) ) { throw new Price_Apply_Error( 'PERMISSION_DENIED' ); }
	}
	/**
	 * Product-meta-neutral helper: only the plan's field is visited through
	 * Woo CRUD. Kept next to `apply` so no second pricing path can appear.
	 */
	private static function set_field( \WC_Product $product, string $field, string $target ): void {
		if ( Price_Operation::FIELD_SALE === $field ) {
			$product->set_sale_price( $target );
		} else {
			$product->set_regular_price( $target );
		}
	}
	/** Diagnostic write after rollback, separately locked; never demote APPLIED. */
	private static function refusal( Change_Plan $plan, int $id, string $state, string $reason, string $attempt ): void {
		$db = Price_Cache_Verifier::observer();
		try {
			$db->query( 'START TRANSACTION' );
			$row = Price_Apply_Journal::read( $db, $plan->data()['plan_id'], $id, true );
			if ( $row ) {
				try { Price_Apply_Journal::assert_binding( $row, $plan, $id ); }
				catch ( Price_Apply_Error $error ) { $db->query( 'ROLLBACK' ); return; }
			}
			if ( $row && 'PENDING' === $row['state'] ) { Price_Apply_Journal::transition( $db, $plan->data()['plan_id'], $id, $row['state'], $state, $reason, $attempt ); }
			// #298 grant release on definitive non-applied terminal outcomes.
			// CONFLICT/FAILED release one reservation; PENDING/NEEDS_REVIEW
			// retain the ambiguous reservation for reconciliation. Best-effort
			// inside the same refusal transaction; failures retain.
			try {
				if ( in_array( $state, array( 'CONFLICT', 'FAILED' ), true ) ) {
					$job = Grant_Repository::job_for_plan_in_tx( $db, $plan->data()['plan_id'] );
					$pin = $job ? Grant_Repository::job_grant( $job ) : null;
					if ( $job && $pin ) {
						$grants = Grant_Schema::grants_table( $db );
						$grant = $db->get_row( $db->prepare( 'SELECT * FROM %i WHERE public_id=%s FOR UPDATE', $grants, $pin['public_id'] ), ARRAY_A );
						if ( is_array( $grant ) ) {
							Grant_Repository::release_in_tx( $db, (int) $grant['id'], (int) $job['id'], $id );
						}
					}
				}
			} catch ( \Throwable $ignored ) {}
			if ( false === $db->query( 'COMMIT' ) ) { throw new Price_Apply_Error( 'AMBIGUOUS_COMMIT' ); }
		} finally { $db->close(); }
	}
	public static function apply( Change_Plan $plan, int $id, ?Price_Apply_Transaction_Guard $guard = null ): array {
		global $wpdb;
		$original = $wpdb;
		$tx = null;
		$attempt = wp_generate_uuid4();
		$committed = false;
		$code = 'FAILED';
		$reason = 'FAILED';
		try {
			self::schema( $original );
			$field = $plan->price_field();
			$item = $plan->item( $id )->data();
			if ( 'CHANGING' !== $item['result'] || 'BLOCKED' === $plan->data()['status'] ) { throw new Price_Apply_Error( 'PLAN_POLICY_BLOCKED' ); }
			$tx = new Price_Apply_Connection( $original );
			$tx->begin();
			$wpdb = $tx;
			// Optional fenced guard: acquires its row lock on this same
			// connection before any journal/Woo write and holds it to COMMIT.
			if ( $guard ) { $guard->acquire( $tx ); }
			// #298 standing-grant authorization/write boundary. Runner (guard)
			// then job (guard), then disable + grant row here (held to
			// COMMIT so revoke/disable wait), then journal/item, then Woo.
			// Ordinary jobs (no grant pin) skip this block with zero behavior
			// change. Missing/uncertain evidence fails closed, zero Woo saves.
			$grant = null;
			$grant_job = null;
			$grant_pin = null;
			try {
				$candidate = Grant_Repository::job_for_plan_in_tx( $tx, $plan->data()['plan_id'] );
				$candidate_pin = $candidate ? Grant_Repository::job_grant( $candidate ) : null;
				if ( $candidate && $candidate_pin ) {
					$grant_job = $candidate;
					$grant_pin = $candidate_pin;
					$grant = Grant_Repository::assert_grant_for_write( $tx, $candidate, $plan, $id );
				}
			} catch ( Price_Apply_Error $grant_error ) { throw $grant_error; } catch ( \Throwable $grant_error ) { throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' ); }
			$row = Price_Apply_Journal::read( $tx, $plan->data()['plan_id'], $id, true );
			if ( ! $row ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			Price_Apply_Journal::assert_binding( $row, $plan, $id );
			self::checkpoint( 'ITEM_LOCKED', $id, $attempt );
			if ( 'APPLIED' === $row['state'] ) {
				$tx->rollback();
				$wpdb = $original;
				Price_Cache_Verifier::observe( $plan, $id );
				return array( 'code' => 'ALREADY_APPLIED', 'reason' => 'DURABLE_APPLIED', 'attempt_id' => $row['attempt_id'] );
			}
			if ( 'PENDING' !== $row['state'] ) {
				$tx->rollback();
				return array( 'code' => $row['state'], 'reason' => $row['reason'], 'attempt_id' => $row['attempt_id'] );
			}
			Price_Apply_Journal::transition( $tx, $plan->data()['plan_id'], $id, $row['state'], 'APPLYING', 'ITEM_LOCKED', $attempt );
			$post = $tx->get_row( $tx->prepare( "SELECT ID FROM {$tx->posts} WHERE ID=%d FOR UPDATE", $id ) );
			if ( ! $post ) { throw new Price_Apply_Error( 'CONFLICT' ); }
			// Lock all product meta (including absent-key ranges), lookup and type terms.
			$truth = Price_Cache_Verifier::storage( $tx, $id, true );
			$tx->get_results( $tx->prepare( "SELECT * FROM {$tx->term_relationships} WHERE object_id=%d FOR UPDATE", $id ) );
			$role_key = $tx->get_blog_prefix() . 'user_roles';
			$tx->get_results( $tx->prepare( "SELECT option_id FROM {$tx->options} WHERE option_name IN ('woocommerce_currency','woocommerce_price_num_decimals',%s) FOR UPDATE", $role_key ) );
			$actor = $plan->data()['actor_id'];
			$tx->get_results( $tx->prepare( "SELECT ID FROM {$tx->users} WHERE ID=%d FOR UPDATE", $actor ) );
			$tx->get_results( $tx->prepare( "SELECT umeta_id FROM {$tx->usermeta} WHERE user_id=%d FOR UPDATE", $actor ) );
			wp_cache_delete( 'alloptions', 'options' );
			foreach ( array( 'woocommerce_currency', 'woocommerce_price_num_decimals', $role_key ) as $key ) { wp_cache_delete( $key, 'options' ); }
			Price_Cache_Verifier::invalidate( $id );
			try { $product = Product_Price_Snapshot::fresh_product( $id ); }
			catch ( Price_Validation_Error $error ) {
				// A stable unsupported product class keeps its terminal refusal;
				// a transient read failure is rethrown and stays retryable FAILED.
				if ( 'unsupported_product_type' !== $error->getMessage() ) { throw $error; }
				throw new Price_Apply_Error( 'UNSUPPORTED_PRODUCT_STATE' );
			}
			if ( ! $product || ! Price_Cache_Verifier::core_data_store( $product ) ) { throw new Price_Apply_Error( 'UNSUPPORTED_PRODUCT_STATE' ); }
			$is_variation = ! empty( $item['snapshot']['core_variation'] );
			$parent_id = $is_variation ? (int) ( $item['snapshot']['parent_id'] ?? 0 ) : 0;
			// The frozen plan says variation but the live product is not: a
			// concurrent type change is an optimistic CONFLICT, not corruption.
			if ( $is_variation && ( 'WC_Product_Variation' !== get_class( $product ) || $parent_id < 1 ) ) { throw new Price_Apply_Error( 'CONFLICT' ); }
			$precondition = $plan->precondition( $id, Product_Price_Snapshot::read( $id, $product ), Price_Store_Context::current() );
			if ( 'MATCH' !== $precondition['state'] ) { throw new Price_Apply_Error( 'CONFLICT' ); }
			// A concurrent, supported Woo edit may commit while this process has
			// cached its previous Woo object. Even after targeted eviction, the
			// newly locked storage can be newer than the public object. Only a
			// coherent stored field state at a DIFFERENT price is an optimistic
			// CONFLICT; malformed/duplicate/lookup-divergent storage still takes
			// #108's JOURNAL_MISMATCH/NEEDS_REVIEW path.
			Price_Cache_Verifier::coherence( $truth );
			$field_meta = '_' . Price_Operation::meta_key( $field );
			if ( ! Price_Decimal::equal( $truth['meta'][ $field_meta ][0] ?? '', $item['expected_regular_price'] ) ) {
				throw new Price_Apply_Error( 'CONFLICT' );
			}
			// The locked database basis must match approval too: a fresh Woo
			// object alone cannot certify a dependency under concurrent edits.
			if ( in_array( $plan->data()['operation']['type'], array( Price_Operation::CLEAR_SALE, Price_Operation::SALE_DISCOUNT_PERCENT ), true ) && ! Price_Decimal::equal( $truth['meta']['_regular_price'][0], $item['snapshot']['regular_price'] ) ) { throw new Price_Apply_Error( 'CONFLICT' ); }
			// A concurrent edit that would make WooCommerce's own save clear
			// the other price field (regular <= sale, or sale >= regular) is
			// an optimistic conflict: never lose stored sale configuration.
			if ( Price_Operation::FIELD_REGULAR === $field ) {
				$current_sale = $truth['meta']['_sale_price'][0] ?? '';
				if ( '' !== $current_sale && Price_Decimal::compare( Price_Decimal::units( Price_Decimal::parse( $item['planned_regular_price'] ) ), Price_Decimal::units( Price_Decimal::parse( $current_sale ) ) ) <= 0 ) { throw new Price_Apply_Error( 'CONFLICT' ); }
			} elseif ( '' !== $item['planned_regular_price'] && Price_Decimal::compare( Price_Decimal::units( Price_Decimal::parse( $item['planned_regular_price'] ) ), Price_Decimal::units( Price_Decimal::parse( $truth['meta']['_regular_price'][0] ) ) ) >= 0 ) {
				throw new Price_Apply_Error( 'CONFLICT' );
			}
			$preserved = array(
				'regular_price' => $truth['meta']['_regular_price'][0],
				'sale_price' => $truth['meta']['_sale_price'][0] ?? '',
				'sale_from' => $truth['meta']['_sale_price_dates_from'][0] ?? '',
				'sale_to' => $truth['meta']['_sale_price_dates_to'][0] ?? '',
			);
			// Recheck capabilities at the mutation boundary, not as WP root.
			self::authorize( $plan, $id, $tx );
			self::checkpoint( 'BEFORE_WOO_SAVE', $id, $attempt );
			$tx->assert_owned();
			self::set_field( $product, $field, $item['planned_regular_price'] );
			$product->save();
			self::checkpoint( 'AFTER_WOO_SAVE_BEFORE_JOURNAL', $id, $attempt );
			$tx->assert_owned();
			$after = Price_Cache_Verifier::storage( $tx, $id );
			Price_Cache_Verifier::matches_field( $after, $field, $item['planned_regular_price'], $preserved );
			// A variation save never refreshes its parent. Sync on this same
			// transaction connection and refuse a divergent parent range
			// before any journal evidence exists.
			if ( $is_variation ) { Price_Cache_Verifier::sync_variable_parent( $tx, $parent_id ); }
			$after_field = $after['meta'][ '_' . Price_Operation::meta_key( $field ) ][0] ?? '';
			$evidence = array( 'attempt_id' => $attempt, 'plan_id' => $plan->data()['plan_id'], 'plan_schema_version' => Change_Plan::SCHEMA_VERSION, 'plan_hash_version' => Change_Plan::HASH_VERSION, 'plan_hash' => $plan->hash(), 'product_id' => $id, 'target' => $item['planned_regular_price'], 'price_field' => $field, 'field_value' => $after_field, 'connection_id' => $tx->id(), 'regular' => $after['meta']['_regular_price'][0], 'sale' => $after['meta']['_sale_price'][0] ?? '', 'active' => $after['meta']['_price'][0], 'lookup_min' => $after['lookup']['min_price'], 'lookup_max' => $after['lookup']['max_price'], 'onsale' => (string) ( $after['lookup']['onsale'] ?? '0' ) );
			if ( $is_variation ) { $evidence['parent_id'] = $parent_id; }
			if ( $guard && $guard->connection_id() > 0 ) { $evidence['fence_connection_id'] = $guard->connection_id(); }
			$evidence = Plan_Hasher::canonical_json( $evidence );
			// The row was claimed APPLYING above in this same transaction; the
			// expected state makes a stale or reordered success fail closed
			// before COMMIT instead of overwriting newer durable state.
			Price_Apply_Journal::transition( $tx, $plan->data()['plan_id'], $id, 'APPLYING', 'APPLIED', 'WOO_CRUD_VERIFIED', $attempt, $evidence );
			// #298 consume exactly once when the journal durably reaches
			// APPLIED, in this same transaction before COMMIT. Budget lives
			// here, never in `woocommerce_before_product_object_save`.
			if ( $grant && $grant_job ) {
				try {
					Grant_Repository::consume_in_tx( $tx, $grant, (int) $grant_job['id'], $id );
					Grant_Repository::audit_item_in_tx( $tx, (int) $grant['id'], (int) $grant['version'], (int) $grant_job['id'], $id, $plan->data()['plan_id'], (int) $plan->data()['actor_id'], (int) $grant['issuer_id'], 0, Grant_Repository::APPROVAL_GRANT, (string) ( $grant_pin['key'] ?? '' ), 'ITEM_APPLIED', array( 'expected' => $item['expected_regular_price'] ?? '', 'planned' => $item['planned_regular_price'] ?? '', 'field' => $field, 'attempt' => $attempt ) );
				} catch ( Price_Apply_Error $consume_error ) { throw $consume_error; } catch ( \Throwable $consume_error ) { throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' ); }
			}
			self::checkpoint( 'AFTER_JOURNAL_BEFORE_COMMIT', $id, $attempt );
			$tx->commit();
			$committed = true;
			$wpdb = $original;
			self::checkpoint( 'AFTER_COMMIT_BEFORE_RESPONSE', $id, $attempt );
			Price_Cache_Verifier::observe( $plan, $id );
			return array( 'code' => 'APPLIED', 'reason' => 'DURABLE_APPLIED', 'attempt_id' => $attempt );
		} catch ( \Throwable $e ) {
			$reason = $e instanceof Price_Apply_Error ? $e->getMessage() : 'FAILED';
			if ( $committed && 'FAILED' === $reason ) { $reason = 'AMBIGUOUS_COMMIT'; }
			$had_transaction = $tx && $tx->owns_attempt();
			$rolled_back = $tx ? $tx->rollback() : false;
			if ( $had_transaction && ! $rolled_back && 'AMBIGUOUS_COMMIT' !== $reason ) { $reason = 'TRANSACTION_LOST'; }
			$wpdb = $original;
			// #298 grant failure policy: definitive grant denials fail closed
			// with zero Woo saves and no unrestricted fallback. Ambiguous
			// grant-store uncertainty retains the reservation via NEEDS_REVIEW.
			$grant_definitive = array( 'GRANT_REVOKED', 'GRANT_EXPIRED', 'GRANT_DISABLED', 'GRANT_SCOPE_DENIED', 'GRANT_BUDGET_EXHAUSTED', 'GRANT_IDENTITY_INVALID', 'GRANT_BINDING_MISMATCH', 'GRANT_IDEMPOTENCY_MISMATCH', 'GRANT_NOT_FOUND', 'GRANT_PLAN_EXISTS' );
			$grant_ambiguous = array( 'GRANT_STORE_UNAVAILABLE' );
			$review = $committed || in_array( $reason, array( 'TRANSACTION_LOST', 'AMBIGUOUS_COMMIT', 'CACHE_VERIFICATION_FAILED', 'LOOKUP_MISMATCH', 'JOURNAL_MISMATCH' ), true ) || in_array( $reason, $grant_ambiguous, true );
			if ( in_array( $reason, $grant_definitive, true ) ) { $code = 'GRANT_DENIED'; }
			else { $code = $review ? 'NEEDS_REVIEW' : ( in_array( $reason, array( 'CONFLICT', 'PERMISSION_DENIED', 'UNSUPPORTED_PRODUCT_STATE', 'TRANSACTION_UNAVAILABLE', 'WOOCOMMERCE_VERSION_UNSUPPORTED', 'MULTISITE_UNSUPPORTED', 'DB_TRANSACTIONS_UNSUPPORTED', 'FENCE_LOST', 'DEACTIVATED' ), true ) ? $reason : 'FAILED' ); }
			try {
				// Connection loss: cleanup uses independent live DB, never a dead writer.
				$observer = Price_Cache_Verifier::observer();
				try { $wpdb = $observer; Price_Cache_Verifier::invalidate( $id ); } finally { $wpdb = $original; $observer->close(); }
				if ( $tx && ( $rolled_back || $review ) ) {
					// Known rollback FAILED/FENCE_LOST remains PENDING for an explicit retry or
					// for the authoritative newer generation. Environment refusals
					// (unsupported Woo/multisite/non-transactional tables) also
					// return to PENDING: fixing the environment and resuming
					// continues the same job. Review never auto-retries.
					$state = $review ? 'NEEDS_REVIEW' : ( in_array( $code, array( 'FAILED', 'FENCE_LOST', 'DEACTIVATED', 'TRANSACTION_UNAVAILABLE', 'WOOCOMMERCE_VERSION_UNSUPPORTED', 'MULTISITE_UNSUPPORTED', 'DB_TRANSACTIONS_UNSUPPORTED' ), true ) ? 'PENDING' : ( 'CONFLICT' === $code ? 'CONFLICT' : 'FAILED' ) );
					self::refusal( $plan, $id, $state, $reason, $attempt );
				}
			} catch ( \Throwable $cleanup ) { $code = 'NEEDS_REVIEW'; $reason = 'CACHE_VERIFICATION_FAILED'; }
			return array( 'code' => $code, 'reason' => $reason, 'attempt_id' => $attempt );
		} finally { $wpdb = $original; }
	}
	/** #110 prototype: eligibility observation only; never restores a price. */
	public static function undo_precondition( Change_Plan $plan, int $id ): string {
		try { Price_Cache_Verifier::observe( $plan, $id ); return 'UNDO_ELIGIBLE'; }
		catch ( \Throwable $e ) { return 'UNDO_CONFLICT'; }
	}
}
