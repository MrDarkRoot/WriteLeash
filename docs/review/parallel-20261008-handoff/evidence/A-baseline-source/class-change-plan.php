<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

final class Change_Plan_Item {
	use Immutable_Price_Value;
	public const RESULTS = array( 'CHANGING', 'UNCHANGED', 'UNSUPPORTED' );
	private array $values;
	private function __construct( array $values ) { $this->values = $values; }
	/**
	 * Trusted persistence rehydration. Validates stored shape; never recomputes a target.
	 * `expected_regular_price` / `planned_regular_price` are the expected and planned
	 * values of the plan's price field (`regular_price` or `sale_price`); the names are
	 * frozen for hash compatibility.
	 */
	public static function hydrate( array $values, string $field = Price_Operation::FIELD_REGULAR ): self {
		Price_Operation::assert_field( $field );
		foreach ( array( 'product_id', 'snapshot', 'expected_regular_price', 'planned_regular_price', 'result', 'eligibility', 'blockers', 'warnings', 'absolute_delta', 'percentage_delta' ) as $key ) {
			if ( ! array_key_exists( $key, $values ) ) { throw new Price_Validation_Error( 'invalid_plan_item' ); }
		}
		$s = $values['snapshot'];
		if ( ! is_int( $values['product_id'] ) || $values['product_id'] < 1 || ! is_array( $s ) || ( $s['product_id'] ?? null ) !== $values['product_id'] ||
			! is_bool( $s['exists'] ?? null ) || ! is_string( $s['type'] ?? null ) || ! is_bool( $s['core_simple'] ?? null ) || ! is_string( $s['status'] ?? null ) ||
			! is_string( $s['regular_price'] ?? null ) || ! is_string( $s['sale_price'] ?? null ) ||
			! ( null === ( $s['sale_from'] ?? null ) || is_string( $s['sale_from'] ) ) || ! ( null === ( $s['sale_to'] ?? null ) || is_string( $s['sale_to'] ) ) ) {
			throw new Price_Validation_Error( 'invalid_plan_item' );
		}
		// Expected prices are stored in parse() canonical form; an empty sale
		// field means "no stored sale yet". Planned targets keep the
		// store-decimal formatting from Price_Calculator::target().
		$expected = $values['expected_regular_price'];
		if ( null !== $expected ) {
			if ( ! is_string( $expected ) ) { throw new Price_Validation_Error( 'invalid_plan_item' ); }
			if ( '' === $expected ) {
				if ( Price_Operation::FIELD_SALE !== $field ) { throw new Price_Validation_Error( 'invalid_plan_item' ); }
			} elseif ( Price_Decimal::parse( $expected ) !== $expected ) {
				throw new Price_Validation_Error( 'invalid_plan_item' );
			}
		}
		$planned = $values['planned_regular_price'];
		if ( null !== $planned ) {
			try { Price_Decimal::parse( $planned ); }
			catch ( \Throwable $error ) { throw new Price_Validation_Error( 'invalid_plan_item' ); }
		}
		$eligibility = $values['eligibility'];
		if ( ! in_array( $values['result'], self::RESULTS, true ) || ! is_array( $eligibility ) ||
			! in_array( $eligibility['state'] ?? null, array( Eligibility_Result::ELIGIBLE, Eligibility_Result::UNSUPPORTED ), true ) ||
			! ( null === ( $eligibility['reason'] ?? null ) || is_string( $eligibility['reason'] ) ) ||
			! self::reason_list( $values['blockers'] ) || ! self::reason_list( $values['warnings'] ) ||
			! ( null === $values['absolute_delta'] || is_string( $values['absolute_delta'] ) ) ) {
			throw new Price_Validation_Error( 'invalid_plan_item' );
		}
		$percentage = $values['percentage_delta'];
		if ( null !== $percentage && ( ! is_array( $percentage ) || ! is_string( $percentage['numerator'] ?? null ) || ! is_string( $percentage['denominator'] ?? null ) || ! is_string( $percentage['display'] ?? null ) ) ) { throw new Price_Validation_Error( 'invalid_plan_item' ); }
		if ( 'UNSUPPORTED' === $values['result'] ) {
			if ( null !== $planned ) { throw new Price_Validation_Error( 'invalid_plan_item' ); }
		} elseif ( null === $expected || null === $planned || ( 'CHANGING' === $values['result'] ) === Price_Decimal::equal( $expected, $planned ) ) {
			throw new Price_Validation_Error( 'invalid_plan_item' );
		}
		return new self( $values );
	}
	private static function reason_list( $value ): bool {
		if ( ! is_array( $value ) ) { return false; }
		foreach ( $value as $reason ) { if ( ! is_string( $reason ) ) { return false; } }
		return true;
	}
	/** Trusted #107 factory arithmetic. The only place a target price is computed. */
	public static function compute( Product_Price_Snapshot $snapshot, Price_Store_Context $context, Price_Operation $operation, Safety_Policy $policy ): self {
		$s = $snapshot->data();
		$field = $operation->data()['field'];
		$meta = Price_Operation::meta_key( $field );
		$eligibility = Product_Price_Eligibility::evaluate( $snapshot, $context, $field, $operation->data()['type'] )->data();
		$target = null;
		$expected = null;
		$delta = array( 'absolute_delta' => null, 'percentage_delta' => null );
		$result = 'UNSUPPORTED';
		$p = new Policy_Result( array(), array() );
		if ( Eligibility_Result::ELIGIBLE === $eligibility['state'] ) {
			try {
				// Expected (and target) are values of the plan's price field.
				$expected = '' === $s[ $meta ] ? '' : Price_Decimal::parse( $s[ $meta ] ); // Preserve raw stored value in snapshot as well.
				$target = Price_Calculator::calculate( $expected, $operation, $context->data()['price_decimals'] );
				$eligibility = Product_Price_Eligibility::evaluate( $snapshot, $context, $field, $operation->data()['type'], $target )->data();
				if ( Eligibility_Result::ELIGIBLE === $eligibility['state'] ) {
					$delta = '' === $expected ? array( 'absolute_delta' => null, 'percentage_delta' => null ) : Price_Calculator::delta( $expected, $target );
					$result = Price_Decimal::equal( $expected, $target ) ? 'UNCHANGED' : 'CHANGING';
					$p = Policy_Evaluator::item( $expected, $target, $policy );
				} else {
					$target = null;
				}
			} catch ( Price_Validation_Error $e ) {
				$eligibility = ( new Eligibility_Result( $e->reason() ) )->data();
				$target = null;
			}
		}
		return new self( array_merge( array(
			'product_id' => $s['product_id'], 'snapshot' => $s, 'expected_regular_price' => $expected,
			'planned_regular_price' => $target, 'result' => $result, 'eligibility' => $eligibility,
			'blockers' => $p->data()['blockers'], 'warnings' => $p->data()['warnings'],
		), $delta ) );
	}
	public function data(): array { return $this->values; }
}

final class Plan_Hasher {
	/** Recursively sort object keys, preserve list order; JSON, never PHP serialization. */
	public static function canonical_json( array $values ): string {
		$normalize = static function ( $value ) use ( &$normalize ) {
			if ( ! is_array( $value ) ) {
				if ( is_float( $value ) || is_object( $value ) ) { throw new Price_Validation_Error( 'noncanonical_plan_value' ); }
				return $value;
			}
			if ( array_values( $value ) !== $value ) { ksort( $value, SORT_STRING ); }
			foreach ( $value as $key => $child ) { $value[$key] = $normalize( $child ); }
			return $value;
		};
		$json = json_encode( $normalize( $values ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) { throw new Price_Validation_Error( 'invalid_plan_encoding' ); }
		return $json;
	}
	public static function hash( array $material ): string { return hash( 'sha256', self::canonical_json( $material ) ); }
}

/** Sealed preview contract. Approval/authorization persistence belongs to later issues. */
final class Change_Plan {
	use Immutable_Price_Value;
	public const SCHEMA_VERSION = 1;
	public const HASH_VERSION = 'sha256-canonical-json-v1';
	private array $material;
	private array $identity;
	private array $items;
	private array $summary;
	private string $hash;
	private function __construct() {}

	/** Trusted domain factory; all snapshots are immutable Woo public-API reads. */
	public static function create( string $id, string $created_at, int $actor, Price_Store_Context $context, Price_Selection_Spec $selection, Price_Operation $operation, Safety_Policy $policy, array $snapshots ): self {
		if ( ! preg_match( '/\A[a-zA-Z0-9_-]{1,64}\z/D', $id ) || ! preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $created_at ) || $actor < 1 ) { throw new Price_Validation_Error( 'invalid_plan_identity' ); }
		if ( count( $snapshots ) > Product_Price_Selector::MAX_SELECTED ) { throw new Price_Validation_Error( 'selection_limit_exceeded' ); }
		$plan = new self();
		$plan->identity = array( 'plan_id' => $id, 'created_at' => $created_at );
		$plan->items = array();
		foreach ( $snapshots as $snapshot ) {
			if ( ! $snapshot instanceof Product_Price_Snapshot ) { throw new Price_Validation_Error( 'invalid_snapshot' ); }
			$product_id = $snapshot->data()['product_id'];
			if ( isset( $plan->items[$product_id] ) ) { throw new Price_Validation_Error( 'duplicate_selection' ); }
			$plan->items[$product_id] = Change_Plan_Item::compute( $snapshot, $context, $operation, $policy );
		}
		ksort( $plan->items, SORT_NUMERIC );
		if ( 'IDS' === $selection->data()['type'] && array_keys( $plan->items ) !== $selection->data()['ids'] ) { throw new Price_Validation_Error( 'selection_snapshot_mismatch' ); }
		$decision = Policy_Evaluator::plan( $plan->items, $policy )->data();
		$plan->summary = self::summarize( $plan->items, $decision, $selection->data()['warnings'] );
		$item_data = array();
		foreach ( $plan->items as $item ) { $item_data[] = $item->data(); }
		$plan->material = array(
			'schema_version' => self::SCHEMA_VERSION, 'hash_version' => self::HASH_VERSION, 'actor_id' => $actor,
			'store' => $context->data(), 'selection' => $selection->data(), 'resolved_product_ids' => array_keys( $plan->items ),
			'operation' => $operation->data(), 'policy_snapshot' => $policy->data(), 'policy_result' => $decision,
			'status' => Policy_Result::BLOCKED === $decision['state'] ? 'BLOCKED' : 'PREVIEW', 'items' => $item_data,
		);
		$plan->hash = Plan_Hasher::hash( $plan->material );
		return $plan;
	}
	/**
	 * Trusted persistence rehydration of previously stored canonical material.
	 * This is not an import or authorization boundary: the supplied fingerprint is
	 * verified against the recomputed material, but a writer who controls both is
	 * outside this threat model. Execution consumes only rehydrated frozen values.
	 */
	public static function hydrate( array $data ): self {
		foreach ( array( 'plan_id', 'created_at', 'schema_version', 'hash_version', 'actor_id', 'store', 'selection', 'resolved_product_ids', 'operation', 'policy_snapshot', 'policy_result', 'status', 'items', 'plan_hash' ) as $key ) {
			if ( ! array_key_exists( $key, $data ) ) { throw new Price_Validation_Error( 'invalid_plan_material' ); }
		}
		if ( ! preg_match( '/\A[a-zA-Z0-9_-]{1,64}\z/D', $data['plan_id'] ) || ! preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $data['created_at'] ) ||
			! is_int( $data['actor_id'] ) || $data['actor_id'] < 1 || $data['schema_version'] !== self::SCHEMA_VERSION || $data['hash_version'] !== self::HASH_VERSION ||
			! is_string( $data['plan_hash'] ) || ! preg_match( '/\A[0-9a-f]{64}\z/D', $data['plan_hash'] ) ) {
			throw new Price_Validation_Error( 'invalid_plan_material' );
		}
		$material = $data;
		unset( $material['plan_id'], $material['created_at'], $material['plan_hash'], $material['summary'] );
		if ( ! hash_equals( $data['plan_hash'], Plan_Hasher::hash( $material ) ) ) { throw new Price_Validation_Error( 'plan_hash_mismatch' ); }
		if ( ! is_array( $material['items'] ) || array_values( $material['items'] ) !== $material['items'] || ! is_array( $material['resolved_product_ids'] ) ) { throw new Price_Validation_Error( 'invalid_plan_material' ); }
		$decision = $material['policy_result'];
		if ( ! is_array( $decision ) || ! in_array( $decision['state'] ?? null, array( Policy_Result::ALLOW, Policy_Result::ALLOW_WITH_WARNINGS, Policy_Result::BLOCKED ), true ) || ! is_array( $decision['warnings'] ?? null ) ) { throw new Price_Validation_Error( 'invalid_plan_material' ); }
		$expected_status = Policy_Result::BLOCKED === $decision['state'] ? 'BLOCKED' : 'PREVIEW';
		if ( $expected_status !== ( $material['status'] ?? null ) ) { throw new Price_Validation_Error( 'invalid_plan_material' ); }
		$selection = $material['selection'];
		if ( ! is_array( $selection ) || ! is_array( $selection['warnings'] ?? null ) || ! is_array( $material['store'] ?? null ) || ! is_array( $material['operation'] ?? null ) || ! is_array( $material['policy_snapshot'] ?? null ) ) { throw new Price_Validation_Error( 'invalid_plan_material' ); }
		// Legacy plans have no `field` key: default to the regular price and
		// never inject the key into the hashed material (old plan_hash values
		// must keep verifying byte-for-byte).
		if ( array_key_exists( 'field', $material['operation'] ) ) {
			try { Price_Operation::assert_field( $material['operation']['field'] ); }
			catch ( Price_Validation_Error $error ) { throw new Price_Validation_Error( 'invalid_plan_material' ); }
		}
		$plan = new self();
		$plan->identity = array( 'plan_id' => $data['plan_id'], 'created_at' => $data['created_at'] );
		$plan->material = $material;
		$field = $plan->price_field();
		$plan->items = array();
		foreach ( $material['items'] as $stored ) {
			if ( ! is_array( $stored ) ) { throw new Price_Validation_Error( 'invalid_plan_material' ); }
			$item = Change_Plan_Item::hydrate( $stored, $field );
			$product_id = $stored['product_id'];
			if ( isset( $plan->items[$product_id] ) ) { throw new Price_Validation_Error( 'invalid_plan_material' ); }
			$plan->items[$product_id] = $item;
		}
		ksort( $plan->items, SORT_NUMERIC );
		if ( array_keys( $plan->items ) !== $material['resolved_product_ids'] ) { throw new Price_Validation_Error( 'invalid_plan_material' ); }
		$plan->summary = self::summarize( $plan->items, $decision, $selection['warnings'] );
		if ( isset( $data['summary'] ) && Plan_Hasher::canonical_json( $data['summary'] ) !== Plan_Hasher::canonical_json( $plan->summary ) ) { throw new Price_Validation_Error( 'plan_summary_mismatch' ); }
		$plan->hash = $data['plan_hash'];
		return $plan;
	}
	private static function summarize( array $items, array $decision, array $selection_warnings ): array {
		$summary = array( 'selected' => count( $items ), 'eligible' => 0, 'changing' => 0, 'unchanged' => 0, 'unsupported' => 0, 'blocked' => 0, 'conflicted' => 0, 'warning_items' => 0, 'warning_count' => count( $decision['warnings'] ), 'selection_warning_count' => count( $selection_warnings ) );
		foreach ( $items as $item ) {
			$d = $item->data();
			++$summary[ strtolower( $d['result'] ) ];
			if ( 'UNSUPPORTED' !== $d['result'] ) { ++$summary['eligible']; }
			if ( $d['warnings'] ) { ++$summary['warning_items']; }
		}
		// A plan-level blocker authorizes none of the changing items, including clean ones.
		$summary['blocked'] = Policy_Result::BLOCKED === $decision['state'] ? $summary['changing'] : 0;
		return $summary;
	}
	public function hash(): string { return $this->hash; }
	/** Target price field of the frozen plan; legacy plans default to the regular price. */
	public function price_field(): string {
		$field = $this->material['operation']['field'] ?? Price_Operation::FIELD_REGULAR;
		return Price_Operation::assert_field( $field );
	}
	public function data(): array { return array_merge( $this->identity, $this->material, array( 'plan_hash' => $this->hash, 'summary' => $this->summary ) ); }
	public function json(): string { return Plan_Hasher::canonical_json( $this->data() ); }
	public function summary(): array { return $this->summary; }
	public function item( int $id ): Change_Plan_Item {
		if ( ! isset( $this->items[$id] ) ) { throw new Price_Validation_Error( 'unpreviewed_product' ); }
		return $this->items[$id];
	}
	/** Preview pages contain copies, bounded independently of summary access. No HTML. */
	public function preview_page( int $offset = 0, int $limit = 50 ): array {
		if ( $offset < 0 || $limit < 1 || $limit > 100 ) { throw new Price_Validation_Error( 'invalid_preview_page' ); }
		$field = $this->price_field();
		$meta = Price_Operation::meta_key( $field );
		$rows = array();
		foreach ( array_slice( $this->items, $offset, $limit ) as $item ) {
			$d = $item->data();
			$s = $d['snapshot'];
			$rows[] = array_merge( $d, array( 'name' => $s['name'], 'sku' => $s['sku'], 'stored_regular_price' => $s['regular_price'], 'stored_price' => $s[ $meta ], 'price_field' => $field ) );
		}
		return array( 'offset' => $offset, 'limit' => $limit, 'items' => $rows, 'next_offset' => $offset + count( $rows ) < count( $this->items ) ? $offset + count( $rows ) : null );
	}
	/** This is a precondition contract, not approval, locking, or mutation. */
	public function precondition( int $id, Product_Price_Snapshot $current, Price_Store_Context $context ): array {
		if ( 'BLOCKED' === $this->material['status'] ) { return array( 'state' => 'BLOCKED', 'reasons' => array( 'plan_policy_blocked' ) ); }
		$field = $this->price_field();
		$item = $this->item( $id )->data();
		if ( 'CHANGING' !== $item['result'] ) { return array( 'state' => 'NOT_CHANGING', 'reasons' => array( $item['eligibility']['reason'] ?? 'unchanged' ) ); }
		$old = $item['snapshot'];
		$now = $current->data();
		$settings = $context->data();
		$expected = $this->material['store'];
		$reasons = array();
		if ( $now['product_id'] !== $id || ! $now['exists'] ) { $reasons[] = 'missing_product'; }
		if ( (string) ( $old['type'] ?? '' ) !== (string) ( $now['type'] ?? '' )
			|| ! empty( $old['core_simple'] ) !== ! empty( $now['core_simple'] )
			|| ! empty( $old['core_variation'] ) !== ! empty( $now['core_variation'] )
			|| (int) ( $old['parent_id'] ?? 0 ) !== (int) ( $now['parent_id'] ?? 0 ) ) { $reasons[] = 'product_type_changed'; }
		if ( $old['status'] !== $now['status'] ) { $reasons[] = 'product_status_changed'; }
		if ( Price_Operation::FIELD_SALE === $field ) {
			// Guard the sale price itself. Sale dates are not a conflict:
			// WriteLeash only writes the sale price and preserves the schedule.
			if ( ! Price_Decimal::equal( $now['sale_price'], $item['expected_regular_price'] ) ) { $reasons[] = 'sale_price_changed'; }
			// The planned sale must stay strictly below the current regular price.
			try {
				if ( Price_Decimal::compare( Price_Decimal::units( Price_Decimal::parse( $item['planned_regular_price'] ) ), Price_Decimal::units( Price_Decimal::parse( $now['regular_price'] ) ) ) >= 0 ) { $reasons[] = 'sale_price_not_below_regular'; }
			} catch ( Price_Validation_Error $e ) { $reasons[] = 'sale_price_not_below_regular'; }
		} else {
			try { $price = Price_Decimal::parse( $now['regular_price'] ); }
			catch ( Price_Validation_Error $e ) { $price = null; }
			if ( $price !== $item['expected_regular_price'] ) { $reasons[] = 'regular_price_changed'; }
		}
		if ( $settings['currency'] !== $expected['currency'] || ! $settings['base_currency_context'] ) { $reasons[] = 'currency_context_changed'; }
		if ( $settings['price_decimals'] !== $expected['price_decimals'] ) { $reasons[] = 'price_decimals_changed'; }
		// Routine supported patch/minor updates do not change any guarded
		// product/price fact, so they are not a conflict. Drift outside the
		// supported range (or from an unparseable version) still is.
		if ( ! Free_Support_Contract::versions_compatible( $expected['wordpress_version'], $expected['woocommerce_version'], $settings['wordpress_version'], $settings['woocommerce_version'] ) ) { $reasons[] = 'software_version_changed'; }
		return array( 'state' => $reasons ? 'CONFLICT' : 'MATCH', 'reasons' => $reasons );
	}
}

final class Woo_Price_Planner {
	/** No HTTP entry point. Calling adapters must authenticate and bind a nonce. */
	public static function preview( Price_Selection_Spec $selection, Price_Operation $operation, Safety_Policy $policy ): Change_Plan {
		$actor = get_current_user_id();
		if ( ! $actor || ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_products' ) ) { throw new Price_Validation_Error( 'permission_denied' ); }
		$context = Price_Store_Context::current();
		$snapshots = Product_Price_Selector::resolve( $selection );
		// A selected variable parent is replaced by its exact variation IDs
		// before the plan exists, so the frozen population and the IDS
		// invariant both operate on children only.
		$resolved_selection = Product_Price_Selector::resolved_selection( $selection, $snapshots );
		foreach ( $snapshots as $snapshot ) {
			if ( $snapshot->data()['exists'] && ! current_user_can( 'edit_post', $snapshot->data()['product_id'] ) ) { throw new Price_Validation_Error( 'permission_denied' ); }
		}
		if ( $context->data() !== Price_Store_Context::current()->data() ) { throw new Price_Validation_Error( 'store_context_changed_during_planning' ); }
		return Change_Plan::create( wp_generate_uuid4(), gmdate( 'Y-m-d\TH:i:s\Z' ), $actor, $context, $resolved_selection, $operation, $policy, $snapshots );
	}
}
