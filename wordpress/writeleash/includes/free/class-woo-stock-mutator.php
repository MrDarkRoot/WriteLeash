<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * #310 frozen stock operation with bounded absolute targets.
 *
 * Mirrors the trusted #107 price operation contract for the product_stock
 * asset: SET / INCREASE_INT / DECREASE_INT on stock_quantity and SET on
 * stock_status, every target computed once to an absolute value at plan
 * time and re-verified under lock at Apply. No percentage math, no
 * re-selection, no negative results: the floor (>= 0) and the absolute
 * delta ceiling are part of the frozen operation and re-checked by the
 * grant scope on the frozen strings only.
 */
final class Stock_Operation {
	public const SET = 'SET';
	public const INCREASE_INT = 'INCREASE_INT';
	public const DECREASE_INT = 'DECREASE_INT';
	public const FIELD_QUANTITY = 'stock_quantity';
	public const FIELD_STATUS = 'stock_status';
	public const FIELDS = array( self::FIELD_QUANTITY, self::FIELD_STATUS );
	public const ASSET = 'product_stock';
	/** The three exact core stock statuses of the supported range. */
	public const STATUSES = array( 'instock', 'outofstock', 'onbackorder' );
	/** Nine integer digits keep every quantity exact in Price_Decimal and in varchar(32) columns. */
	public const QUANTITY_LIMIT = '999999999';

	private string $type;
	private string $field;
	private string $input;
	private string $max_abs_delta;
	private string $min_stock_floor;

	public function __construct( string $type, string $field, string $input, string $max_abs_delta, string $min_stock_floor ) {
		if ( ! in_array( $field, self::FIELDS, true ) ) { throw new Price_Validation_Error( 'unsupported_stock_field' ); }
		if ( self::FIELD_STATUS === $field ) {
			if ( self::SET !== $type ) { throw new Price_Validation_Error( 'unsupported_operation' ); }
			if ( ! in_array( $input, self::STATUSES, true ) ) { throw new Price_Validation_Error( 'unsupported_stock_status' ); }
		} else {
			if ( ! in_array( $type, array( self::SET, self::INCREASE_INT, self::DECREASE_INT ), true ) ) { throw new Price_Validation_Error( 'unsupported_operation' ); }
			if ( ! preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $input ) || Price_Decimal::compare( $input, self::QUANTITY_LIMIT ) > 0 ) { throw new Price_Validation_Error( 'invalid_stock_quantity' ); }
		}
		if ( ! preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $max_abs_delta ) || '0' === Price_Decimal::natural( $max_abs_delta ) || Price_Decimal::compare( $max_abs_delta, self::QUANTITY_LIMIT ) > 0 ) { throw new Price_Validation_Error( 'invalid_stock_delta_bound' ); }
		if ( ! preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $min_stock_floor ) || Price_Decimal::compare( $min_stock_floor, self::QUANTITY_LIMIT ) > 0 ) { throw new Price_Validation_Error( 'invalid_stock_floor' ); }
		$this->type = $type;
		$this->field = $field;
		$this->input = $input;
		$this->max_abs_delta = $max_abs_delta;
		$this->min_stock_floor = $min_stock_floor;
	}

	/** data() carries every key so one canonical shape covers plan hash and persistence. */
	public function data(): array {
		return array(
			'asset' => self::ASSET,
			'type' => $this->type,
			'field' => $this->field,
			'input' => $this->input,
			'max_abs_delta' => $this->max_abs_delta,
			'min_stock_floor' => $this->min_stock_floor,
		);
	}

	/** Trusted persistence validation for hydrated material. */
	public static function assert_data( array $data ): void {
		if ( ( $data['asset'] ?? null ) !== self::ASSET || ! is_string( $data['type'] ?? null ) || ! is_string( $data['field'] ?? null )
			|| ! is_string( $data['input'] ?? null ) || ! is_string( $data['max_abs_delta'] ?? null ) || ! is_string( $data['min_stock_floor'] ?? null ) ) {
			throw new Price_Validation_Error( 'invalid_plan_material' );
		}
		try { new self( $data['type'], $data['field'], $data['input'], $data['max_abs_delta'], $data['min_stock_floor'] ); }
		catch ( Price_Validation_Error $error ) { throw new Price_Validation_Error( 'invalid_plan_material' ); }
	}

	/** Exact absolute target from the frozen facts; the only place a stock target is computed. */
	public function target( string $expected_quantity ): string {
		if ( self::FIELD_STATUS === $this->field ) { return $expected_quantity; }
		if ( '' === $expected_quantity ) { throw new Price_Validation_Error( 'empty_stock_baseline' ); }
		$expected = Price_Decimal::parse( $expected_quantity );
		try {
			switch ( $this->type ) {
				case self::SET: $planned = Price_Decimal::parse( $this->input ); break;
				case self::INCREASE_INT: $planned = Price_Decimal::add( $expected, $this->input ); break;
				default: $planned = Price_Decimal::subtract( $expected, $this->input ); break;
			}
		} catch ( Price_Validation_Error $error ) {
			if ( 'negative_target' === $error->reason() ) { throw new Price_Validation_Error( 'stock_floor_violation' ); }
			throw $error;
		}
		if ( Price_Decimal::compare( $planned, self::QUANTITY_LIMIT ) > 0 ) { throw new Price_Validation_Error( 'stock_quantity_overflow' ); }
		if ( Price_Decimal::compare( $planned, $this->min_stock_floor ) < 0 ) { throw new Price_Validation_Error( 'stock_floor_violation' ); }
		$delta = Price_Decimal::compare( $planned, $expected ) >= 0 ? Price_Decimal::subtract( $planned, $expected ) : Price_Decimal::subtract( $expected, $planned );
		if ( Price_Decimal::compare( $delta, $this->max_abs_delta ) > 0 ) { throw new Price_Validation_Error( 'stock_delta_overflow' ); }
		return $planned;
	}
}

/**
 * #310 stock mutator for the product_stock asset. Structural mirror of
 * Woo_Price_Mutator::apply() on the shared #108 engine: same schema gates,
 * same Price_Apply_Connection item transaction, same journal CAS states, same
 * grant reservation consume-once, same Perimeter execution frame (kind
 * 'stock'), same refusal and reconciliation discipline. The stock-specific
 * parts are the lock set (surveyed for #310: product + parent post rows, all
 * postmeta including absent-key ranges, lookup row, type terms, stock
 * options), the baseline verification against the frozen expected
 * quantity/status, the Woo CRUD write itself (set_stock_quantity()/
 * set_stock_status() + save(); WC_Product_Variable::sync_stock_status() for
 * variations) and the stock-typed storage verification.
 */
final class Woo_Stock_Mutator {
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
			if ( 'InnoDB' !== $engine ) { throw new Price_Apply_Error( 'DB_TRANSACTIONS_UNSUPPORTED' ); }
		}
	}
	private static function authorize( Change_Plan $plan, int $id, \wpdb $db ): void {
		$actor = $plan->data()['actor_id'];
		wp_roles()->for_site( get_current_blog_id() );
		clean_user_cache( $actor );
		$user = new \WP_User( $actor );
		if ( get_current_user_id() !== $actor || ! user_can( $user, 'manage_woocommerce' ) || ! user_can( $user, 'edit_products' ) || ! user_can( $user, 'edit_post', $id ) ) { throw new Price_Apply_Error( 'PERMISSION_DENIED' ); }
	}
	/**
	 * Stock-typed storage read. Unlike the price reader it does not require
	 * price meta to be present or parseable (a stock-only product may carry
	 * an empty price), but every stock key must be single-valued and the
	 * lookup row must exist.
	 */
	private static function storage( \wpdb $db, int $id, bool $lock = false ): array {
		$rows = $db->get_results( $db->prepare( "SELECT meta_key,meta_value FROM {$db->postmeta} WHERE post_id=%d ORDER BY meta_id" . ( $lock ? ' FOR UPDATE' : '' ), $id ), ARRAY_A );
		if ( ! is_array( $rows ) ) { throw new Price_Apply_Error( 'TRANSACTION_LOST' ); }
		$meta = array();
		foreach ( $rows as $row ) { $meta[$row['meta_key']][] = $row['meta_value']; }
		foreach ( array( '_stock', '_stock_status', '_manage_stock' ) as $key ) {
			if ( count( $meta[ $key ] ?? array() ) > 1 ) { throw new Price_Apply_Error( 'UNSUPPORTED_PRODUCT_STATE' ); }
		}
		$lookup_rows = $db->get_results( $db->prepare( "SELECT * FROM {$db->wc_product_meta_lookup} WHERE product_id=%d" . ( $lock ? ' FOR UPDATE' : '' ), $id ), ARRAY_A );
		if ( ! is_array( $lookup_rows ) || 1 !== count( $lookup_rows ) ) { throw new Price_Apply_Error( 'LOOKUP_MISMATCH' ); }
		return array( 'meta' => $meta, 'lookup' => $lookup_rows[0] );
	}
	/** Diagnostic write after rollback, separately locked; never demote APPLIED. */
	private static function refusal( Change_Plan $plan, int $id, string $state, string $reason, string $attempt ): void {
		$db = Price_Cache_Verifier::observer();
		try {
			if ( false === $db->query( 'START TRANSACTION' ) ) { throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' ); }
			$row = Price_Apply_Journal::read( $db, $plan->data()['plan_id'], $id, true );
			if ( $row ) {
				try { Price_Apply_Journal::assert_binding( $row, $plan, $id ); }
				catch ( Price_Apply_Error $error ) { $db->query( 'ROLLBACK' ); return; }
			}
			if ( $row && 'PENDING' === $row['state'] ) { Price_Apply_Journal::transition( $db, $plan->data()['plan_id'], $id, $row['state'], $state, $reason, $attempt ); }
			if ( in_array( $state, array( 'CONFLICT', 'FAILED' ), true ) ) {
				$job = Grant_Repository::job_for_plan_in_tx( $db, $plan->data()['plan_id'] );
				$pin = $job ? Grant_Repository::job_grant( $job ) : null;
				if ( $job && $pin ) {
					$grants = Grant_Schema::grants_table( $db );
					$grant = $db->get_row( $db->prepare( 'SELECT * FROM %i WHERE public_id=%s FOR UPDATE', $grants, $pin['public_id'] ), ARRAY_A );
					if ( ! is_array( $grant ) ) { throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' ); }
					if ( Grant_Repository::release_in_tx( $db, (int) $grant['id'], (int) $job['id'], $id ) ) {
						Grant_Repository::audit_item_in_tx( $db, (int) $grant['id'], (int) $pin['version'], (int) $job['id'], $id, $plan->data()['plan_id'], (int) $plan->data()['actor_id'], (int) $grant['issuer_id'], 0, Grant_Repository::APPROVAL_GRANT, (string) $pin['key'], 'ITEM_RELEASED', array( 'source' => 'REFUSAL', 'state' => $state, 'reason' => $reason, 'asset' => Stock_Operation::ASSET ) );
					}
				}
			}
			if ( false === $db->query( 'COMMIT' ) ) { throw new Price_Apply_Error( 'AMBIGUOUS_COMMIT' ); }
		} catch ( \Throwable $error ) {
			try { $db->query( 'ROLLBACK' ); } catch ( \Throwable $rollback_error ) { unset( $rollback_error ); }
			throw $error instanceof Price_Apply_Error ? $error : new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' );
		} finally { $db->close(); }
	}
	/** The planned value this item writes: absolute quantity string or stock status. */
	private static function planned_value( array $item ): string {
		$stock = $item['stock'];
		return null !== $stock['planned_status'] ? (string) $stock['planned_status'] : (string) $item['planned_regular_price'];
	}
	public static function apply( Change_Plan $plan, int $id, ?Price_Apply_Transaction_Guard $guard = null ): array {
		global $wpdb;
		$original = $wpdb;
		$tx = null;
		$attempt = wp_generate_uuid4();
		$committed = false;
		$frame_opened = false;
		$code = 'FAILED';
		$reason = 'FAILED';
		try {
			self::schema( $original );
			$operation = $plan->stock_operation();
			$field = (string) $operation['field'];
			$item = $plan->item( $id )->data();
			if ( 'CHANGING' !== $item['result'] || 'BLOCKED' === $plan->data()['status'] ) { throw new Price_Apply_Error( 'PLAN_POLICY_BLOCKED' ); }
			$tx = new Price_Apply_Connection( $original );
			$tx->begin();
			$wpdb = $tx;
			if ( $guard ) { $guard->acquire( $tx ); }
			$grant = null;
			$grant_job = null;
			$grant_pin = null;
			$grant_consumed = false;
			if ( $guard instanceof Price_Apply_Grant_Guard ) {
				try {
					$candidate = $guard->locked_job_row();
					$candidate_pin = $candidate ? Grant_Repository::job_grant( $candidate ) : null;
					if ( $candidate && $candidate_pin ) {
						$grant_job = $candidate;
						$grant_pin = $candidate_pin;
						try {
							$grant = Grant_Repository::assert_grant_for_write( $tx, $candidate, $plan, $id );
						} catch ( Price_Apply_Error $consumed ) {
							if ( 'GRANT_RESERVATION_CONSUMED' !== $consumed->getMessage() ) { throw $consumed; }
							$grant_consumed = true;
						}
					}
				} catch ( Price_Apply_Error $grant_error ) { throw $grant_error; } catch ( \Throwable $grant_error ) { throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' ); }
			}
			$row = Price_Apply_Journal::read( $tx, $plan->data()['plan_id'], $id, true );
			if ( ! $row ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			Price_Apply_Journal::assert_binding( $row, $plan, $id );
			self::checkpoint( 'ITEM_LOCKED', $id, $attempt );
			if ( 'APPLIED' === $row['state'] ) {
				$tx->rollback();
				$wpdb = $original;
				self::verify_applied( $plan, $id );
				return array( 'code' => 'ALREADY_APPLIED', 'reason' => 'DURABLE_APPLIED', 'attempt_id' => $row['attempt_id'] );
			}
			if ( $grant_consumed ) { throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' ); }
			if ( 'PENDING' !== $row['state'] ) {
				$tx->rollback();
				return array( 'code' => $row['state'], 'reason' => $row['reason'], 'attempt_id' => $row['attempt_id'] );
			}
			Price_Apply_Journal::transition( $tx, $plan->data()['plan_id'], $id, $row['state'], 'APPLYING', 'ITEM_LOCKED', $attempt );
			$post = $tx->get_row( $tx->prepare( "SELECT ID FROM {$tx->posts} WHERE ID=%d FOR UPDATE", $id ) );
			if ( ! $post ) { throw new Price_Apply_Error( 'CONFLICT' ); }
			$is_variation = ! empty( $item['snapshot']['core_variation'] );
			$parent_id = $is_variation ? (int) ( $item['snapshot']['parent_id'] ?? 0 ) : 0;
			if ( $is_variation ) {
				// The parent post row is locked with the item so the in-frame
				// parent status sync cannot interleave with a concurrent parent
				// edit; its storage is locked for the same reason.
				$parent_post = $tx->get_row( $tx->prepare( "SELECT ID FROM {$tx->posts} WHERE ID=%d FOR UPDATE", $parent_id ) );
				if ( ! $parent_post ) { throw new Price_Apply_Error( 'CONFLICT' ); }
			}
			$truth = self::storage( $tx, $id, true );
			if ( $is_variation ) { self::storage( $tx, $parent_id, true ); }
			$tx->get_results( $tx->prepare( "SELECT * FROM {$tx->term_relationships} WHERE object_id=%d FOR UPDATE", $id ) );
			$role_key = $tx->get_blog_prefix() . 'user_roles';
			$tx->get_results( $tx->prepare( "SELECT option_id FROM {$tx->options} WHERE option_name IN ('woocommerce_manage_stock','woocommerce_notify_low_stock_amount','woocommerce_currency','woocommerce_price_num_decimals',%s) FOR UPDATE", $role_key ) );
			$actor = $plan->data()['actor_id'];
			$tx->get_results( $tx->prepare( "SELECT ID FROM {$tx->users} WHERE ID=%d FOR UPDATE", $actor ) );
			$tx->get_results( $tx->prepare( "SELECT umeta_id FROM {$tx->usermeta} WHERE user_id=%d FOR UPDATE", $actor ) );
			wp_cache_delete( 'alloptions', 'options' );
			foreach ( array( 'woocommerce_manage_stock', 'woocommerce_notify_low_stock_amount', 'woocommerce_currency', 'woocommerce_price_num_decimals', $role_key ) as $key ) { wp_cache_delete( $key, 'options' ); }
			Price_Cache_Verifier::invalidate( $id );
			try { $product = Product_Price_Snapshot::fresh_product( $id ); }
			catch ( Price_Validation_Error $error ) {
				if ( 'unsupported_product_type' !== $error->getMessage() ) { throw $error; }
				throw new Price_Apply_Error( 'UNSUPPORTED_PRODUCT_STATE' );
			}
			if ( ! $product || ! Price_Cache_Verifier::core_data_store( $product ) ) { throw new Price_Apply_Error( 'UNSUPPORTED_PRODUCT_STATE' ); }
			if ( $is_variation && ( 'WC_Product_Variation' !== get_class( $product ) || $parent_id < 1 ) ) { throw new Price_Apply_Error( 'CONFLICT' ); }
			$precondition = $plan->precondition( $id, Product_Price_Snapshot::read( $id, $product ), Price_Store_Context::current() );
			if ( 'MATCH' !== $precondition['state'] ) { throw new Price_Apply_Error( 'CONFLICT' ); }
			// Locked live baseline must equal the frozen expectation: a checkout
			// race (wc_maybe_reduce_stock_levels) or any concurrent edit between
			// Preview and Apply lands here as CONFLICT with zero writes.
			$quantity_op = Stock_Operation::FIELD_QUANTITY === $field;
			$expected_quantity = (string) ( $item['expected_regular_price'] ?? '' );
			$expected_status = (string) ( $item['snapshot']['stock_status'] ?? '' );
			if ( $quantity_op ) {
				if ( 'yes' !== (string) ( $truth['meta']['_manage_stock'][0] ?? '' ) ) { throw new Price_Apply_Error( 'CONFLICT' ); }
				if ( 'yes' !== (string) get_option( 'woocommerce_manage_stock' ) ) { throw new Price_Apply_Error( 'CONFLICT' ); }
				if ( ! Price_Decimal::equal( (string) ( $truth['meta']['_stock'][0] ?? '' ), $expected_quantity ) ) { throw new Price_Apply_Error( 'CONFLICT' ); }
			} else {
				if ( 'yes' === (string) ( $truth['meta']['_manage_stock'][0] ?? '' ) ) { throw new Price_Apply_Error( 'CONFLICT' ); }
				if ( ! Price_Decimal::equal( (string) ( $truth['meta']['_stock_status'][0] ?? '' ), $expected_status ) ) { throw new Price_Apply_Error( 'CONFLICT' ); }
			}
			$planned = self::planned_value( $item );
			// Recompute the absolute target from the frozen operation. A plan
			// that violates the floor or the delta ceiling is refused before
			// any Woo save; the journal lands FAILED, not a retryable PENDING.
			try {
				$frozen = new Stock_Operation( (string) $operation['type'], $field, (string) $operation['input'], (string) $operation['max_abs_delta'], (string) $operation['min_stock_floor'] );
				if ( $quantity_op ) {
					$recomputed = $frozen->target( $expected_quantity );
					if ( ! Price_Decimal::equal( $recomputed, $planned ) ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
				} elseif ( $planned !== (string) $operation['input'] ) {
					throw new Price_Apply_Error( 'JOURNAL_MISMATCH' );
				}
			} catch ( Price_Validation_Error $error ) {
				unset( $error );
				throw new Price_Apply_Error( 'UNSUPPORTED_PRODUCT_STATE' );
			}
			$preserved_status = (string) ( $truth['meta']['_stock_status'][0] ?? '' );
			$preserved_quantity = (string) ( $truth['meta']['_stock'][0] ?? '' );
			self::authorize( $plan, $id, $tx );
			self::checkpoint( 'BEFORE_WOO_SAVE', $id, $attempt );
			$tx->assert_owned();
			// #306: same item-connection classification as the price mutator.
			$grant_write = Grant_Repository::allows_controlled_write( (int) $actor, null !== $grant );
			if ( ! $grant_write ) { throw new Price_Apply_Error( 'PERMISSION_DENIED' ); }
			self::checkpoint( 'RESTRICTION_DECIDED', $id, $attempt );
			$frame_product_ids = $is_variation ? array( $id, $parent_id ) : array( $id );
			Perimeter_Guard::open_frame(
				$actor,
				$grant_job ? (int) $grant_job['id'] : 0,
				$plan->data()['plan_id'],
				$frame_product_ids,
				'stock',
				$field
			);
			$frame_opened = true;
			if ( $quantity_op ) {
				$product->set_stock_quantity( $planned );
			} else {
				$product->set_stock_status( $planned );
			}
			$product->save();
			// A variation save never refreshes its parent: Woo does not auto-sync
			// the parent stock status on variation CRUD saves, so the frame's
			// extra product id carries the explicit public-API sync. The parent
			// must still be the exact core variable product under a published
			// status, otherwise the frozen identity is stale (CONFLICT).
			$parent_status = '';
			if ( $is_variation ) {
				try { $parent = Product_Price_Snapshot::fresh_product( $parent_id ); }
				catch ( Price_Validation_Error $error ) {
					if ( 'unsupported_product_type' !== $error->getMessage() ) { throw $error; }
					throw new Price_Apply_Error( 'CONFLICT' );
				}
				if ( ! $parent || 'WC_Product_Variable' !== get_class( $parent ) || 'publish' !== $parent->get_status( 'edit' ) ) { throw new Price_Apply_Error( 'CONFLICT' ); }
				\WC_Product_Variable::sync_stock_status( $parent );
				$parent_status = (string) $parent->get_stock_status( 'edit' );
			}
			self::checkpoint( 'AFTER_WOO_SAVE_BEFORE_JOURNAL', $id, $attempt );
			$tx->assert_owned();
			$after = self::storage( $tx, $id );
			if ( $quantity_op ) {
				if ( ! Price_Decimal::equal( (string) ( $after['meta']['_stock'][0] ?? '' ), $planned ) ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
				if ( ! Price_Decimal::equal( (string) ( $after['lookup']['stock_quantity'] ?? '' ), $planned ) ) { throw new Price_Apply_Error( 'LOOKUP_MISMATCH' ); }
			} else {
				if ( (string) ( $after['meta']['_stock_status'][0] ?? '' ) !== $planned ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
				if ( (string) ( $after['lookup']['stock_status'] ?? '' ) !== $planned ) { throw new Price_Apply_Error( 'LOOKUP_MISMATCH' ); }
			}
			$evidence = array( 'attempt_id' => $attempt, 'plan_id' => $plan->data()['plan_id'], 'plan_schema_version' => Change_Plan::SCHEMA_VERSION, 'plan_hash_version' => Change_Plan::HASH_VERSION, 'plan_hash' => $plan->hash(), 'product_id' => $id, 'target' => (string) ( $item['planned_regular_price'] ?? '' ), 'asset' => Stock_Operation::ASSET, 'stock_field' => $field, 'field_value' => $planned, 'connection_id' => $tx->id(), 'stock_quantity' => (string) ( $after['meta']['_stock'][0] ?? '' ), 'stock_status' => (string) ( $after['meta']['_stock_status'][0] ?? '' ), 'lookup_stock_quantity' => (string) ( $after['lookup']['stock_quantity'] ?? '' ), 'lookup_stock_status' => (string) ( $after['lookup']['stock_status'] ?? '' ) );
			if ( $is_variation ) { $evidence['parent_id'] = $parent_id; $evidence['parent_stock_status'] = $parent_status; }
			if ( $guard && $guard->connection_id() > 0 ) { $evidence['fence_connection_id'] = $guard->connection_id(); }
			$evidence = Plan_Hasher::canonical_json( $evidence );
			Price_Apply_Journal::transition( $tx, $plan->data()['plan_id'], $id, 'APPLYING', 'APPLIED', 'WOO_CRUD_VERIFIED', $attempt, $evidence );
			if ( $grant && $grant_job ) {
				try {
					if ( Grant_Repository::consume_in_tx( $tx, $grant, (int) $grant_job['id'], $id ) ) {
						Grant_Repository::audit_item_in_tx( $tx, (int) $grant['id'], (int) ( $grant_pin['version'] ?? 0 ), (int) $grant_job['id'], $id, $plan->data()['plan_id'], (int) $plan->data()['actor_id'], (int) $grant['issuer_id'], 0, Grant_Repository::APPROVAL_GRANT, (string) ( $grant_pin['key'] ?? '' ), 'ITEM_APPLIED', array( 'before' => $quantity_op ? $preserved_quantity : $preserved_status, 'after' => $planned, 'field' => $field, 'asset' => Stock_Operation::ASSET, 'attempt' => $attempt ) );
					}
				} catch ( Price_Apply_Error $consume_error ) { throw $consume_error; } catch ( \Throwable $consume_error ) { throw new Price_Apply_Error( 'GRANT_STORE_UNAVAILABLE' ); }
			}
			self::checkpoint( 'AFTER_JOURNAL_BEFORE_COMMIT', $id, $attempt );
			$tx->commit();
			$committed = true;
			$wpdb = $original;
			self::checkpoint( 'AFTER_COMMIT_BEFORE_RESPONSE', $id, $attempt );
			self::verify_applied( $plan, $id );
			return array( 'code' => 'APPLIED', 'reason' => 'DURABLE_APPLIED', 'attempt_id' => $attempt );
		} catch ( \Throwable $e ) {
			$reason = $e instanceof Price_Apply_Error ? $e->getMessage() : 'FAILED';
			if ( $committed && 'FAILED' === $reason ) { $reason = 'AMBIGUOUS_COMMIT'; }
			$had_transaction = $tx && $tx->owns_attempt();
			$rolled_back = $tx ? $tx->rollback() : false;
			if ( $had_transaction && ! $rolled_back && 'AMBIGUOUS_COMMIT' !== $reason ) { $reason = 'TRANSACTION_LOST'; }
			$wpdb = $original;
			$grant_definitive = array( 'GRANT_REVOKED', 'GRANT_EXPIRED', 'GRANT_DISABLED', 'GRANT_SCOPE_DENIED', 'GRANT_BUDGET_EXHAUSTED', 'GRANT_IDENTITY_INVALID', 'GRANT_BINDING_MISMATCH', 'GRANT_IDEMPOTENCY_MISMATCH', 'GRANT_NOT_FOUND', 'GRANT_PLAN_EXISTS' );
			$grant_ambiguous = array( 'GRANT_STORE_UNAVAILABLE' );
			$review = $committed || in_array( $reason, array( 'TRANSACTION_LOST', 'AMBIGUOUS_COMMIT', 'CACHE_VERIFICATION_FAILED', 'LOOKUP_MISMATCH', 'JOURNAL_MISMATCH' ), true ) || in_array( $reason, $grant_ambiguous, true );
			if ( in_array( $reason, $grant_definitive, true ) ) { $code = 'GRANT_DENIED'; }
			else { $code = $review ? 'NEEDS_REVIEW' : ( in_array( $reason, array( 'CONFLICT', 'PERMISSION_DENIED', 'UNSUPPORTED_PRODUCT_STATE', 'TRANSACTION_UNAVAILABLE', 'WOOCOMMERCE_VERSION_UNSUPPORTED', 'MULTISITE_UNSUPPORTED', 'DB_TRANSACTIONS_UNSUPPORTED', 'FENCE_LOST', 'DEACTIVATED' ), true ) ? $reason : 'FAILED' ); }
			try {
				$observer = Price_Cache_Verifier::observer();
				try { $wpdb = $observer; Price_Cache_Verifier::invalidate( $id ); } finally { $wpdb = $original; $observer->close(); }
				if ( $tx && ( $rolled_back || $review ) ) {
					$state = $review ? 'NEEDS_REVIEW' : ( in_array( $code, array( 'FAILED', 'FENCE_LOST', 'DEACTIVATED', 'TRANSACTION_UNAVAILABLE', 'WOOCOMMERCE_VERSION_UNSUPPORTED', 'MULTISITE_UNSUPPORTED', 'DB_TRANSACTIONS_UNSUPPORTED' ), true ) ? 'PENDING' : ( 'CONFLICT' === $code ? 'CONFLICT' : 'FAILED' ) );
					self::refusal( $plan, $id, $state, $reason, $attempt );
				}
			} catch ( \Throwable $cleanup ) {
				$code = 'NEEDS_REVIEW';
				$reason = ( $cleanup instanceof Price_Apply_Error && in_array( $cleanup->getMessage(), array( 'GRANT_STORE_UNAVAILABLE', 'AMBIGUOUS_COMMIT' ), true ) ) ? $cleanup->getMessage() : 'CACHE_VERIFICATION_FAILED';
			}
			return array( 'code' => $code, 'reason' => $reason, 'attempt_id' => $attempt );
		} finally {
			if ( $frame_opened ) {
				Perimeter_Guard::close_frame();
			}
			$wpdb = $original;
		}
	}
	/**
	 * Independent post-commit verification: the durable APPLIED row, its
	 * binding, its evidence and the committed storage must agree with the
	 * frozen planned value. Used after APPLY and on the ALREADY_APPLIED
	 * replay path; any uncertainty fails closed.
	 */
	private static function verify_applied( Change_Plan $plan, int $id ): void {
		$db = Price_Cache_Verifier::observer();
		$original = $GLOBALS['wpdb'];
		try {
			$row = Price_Apply_Journal::read( $db, $plan->data()['plan_id'], $id );
			if ( ! $row || 'APPLIED' !== $row['state'] || ! Price_Apply_Journal::has_valid_applied_at( $row ) || ! $row['attempt_id'] ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			Price_Apply_Journal::assert_binding( $row, $plan, $id );
			$item = $plan->item( $id )->data();
			$evidence = json_decode( $row['evidence'], true );
			$planned = self::planned_value( $item );
			if ( ! is_array( $evidence ) || ( $evidence['plan_id'] ?? '' ) !== $row['plan_id'] || ( $evidence['plan_hash'] ?? '' ) !== $row['plan_hash'] || ( $evidence['attempt_id'] ?? '' ) !== $row['attempt_id'] || ( $evidence['product_id'] ?? 0 ) !== $id || ( $evidence['target'] ?? null ) !== (string) $row['target_price'] || ( $evidence['target'] ?? null ) !== (string) ( $item['planned_regular_price'] ?? '' ) || ( $evidence['asset'] ?? '' ) !== Stock_Operation::ASSET || ( $evidence['connection_id'] ?? 0 ) < 1 ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			$field = (string) $plan->stock_operation()['field'];
			if ( ( $evidence['stock_field'] ?? '' ) !== $field || ( $evidence['field_value'] ?? '' ) !== $planned ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			$truth = self::storage( $db, $id );
			if ( Stock_Operation::FIELD_QUANTITY === $field ) {
				if ( ! Price_Decimal::equal( (string) ( $truth['meta']['_stock'][0] ?? '' ), $planned ) || ! Price_Decimal::equal( (string) ( $truth['lookup']['stock_quantity'] ?? '' ), $planned ) ) { throw new Price_Apply_Error( 'CACHE_VERIFICATION_FAILED' ); }
			} elseif ( (string) ( $truth['meta']['_stock_status'][0] ?? '' ) !== $planned || (string) ( $truth['lookup']['stock_status'] ?? '' ) !== $planned ) {
				throw new Price_Apply_Error( 'CACHE_VERIFICATION_FAILED' );
			}
			if ( ! empty( $item['snapshot']['core_variation'] ) ) {
				$parent_id = (int) ( $item['snapshot']['parent_id'] ?? 0 );
				if ( (int) ( $evidence['parent_id'] ?? 0 ) !== $parent_id || $parent_id < 1 ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
				$parent_rows = $db->get_results( $db->prepare( "SELECT meta_key,meta_value FROM {$db->postmeta} WHERE post_id=%d AND meta_key='_stock_status'", $parent_id ), ARRAY_A );
				$parent_lookup = $db->get_row( $db->prepare( "SELECT stock_status FROM {$db->wc_product_meta_lookup} WHERE product_id=%d", $parent_id ), ARRAY_A );
				if ( 1 !== count( $parent_rows ) || ! $parent_lookup ) { throw new Price_Apply_Error( 'CACHE_VERIFICATION_FAILED' ); }
				if ( (string) $parent_rows[0]['meta_value'] !== (string) $evidence['parent_stock_status'] || (string) $parent_lookup['stock_status'] !== (string) $evidence['parent_stock_status'] ) { throw new Price_Apply_Error( 'CACHE_VERIFICATION_FAILED' ); }
			}
		} catch ( \Throwable $error ) {
			if ( $error instanceof Price_Apply_Error ) { throw $error; }
			throw new Price_Apply_Error( 'CACHE_VERIFICATION_FAILED' );
		} finally { $GLOBALS['wpdb'] = $original; $db->close(); }
	}
}
