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
	public static function hydrate( array $values, string $field = Price_Operation::FIELD_REGULAR, ?string $operation_type = null ): self {
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
		if ( '' === $planned ) {
			if ( Price_Operation::FIELD_SALE !== $field || Price_Operation::CLEAR_SALE !== $operation_type ) { throw new Price_Validation_Error( 'invalid_plan_item' ); }
		} elseif ( null !== $planned ) {
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
				$target = Price_Calculator::calculate( $expected, $operation, $context->data()['price_decimals'], $s['regular_price'] );
				$eligibility = Product_Price_Eligibility::evaluate( $snapshot, $context, $field, $operation->data()['type'], $target )->data();
				if ( Eligibility_Result::ELIGIBLE === $eligibility['state'] ) {
					$delta = '' === $expected || '' === $target ? array( 'absolute_delta' => null, 'percentage_delta' => null ) : Price_Calculator::delta( $expected, $target );
					$result = Price_Decimal::equal( $expected, $target ) ? 'UNCHANGED' : 'CHANGING';
					// Clearing is not a zero-price discount. Conservatively check the
					// return from the configured sale to the reviewed regular price.
					// Already blank is a no-op, with no numeric delta or policy warning.
					// A first relative sale has a reviewed regular basis for safety
					// caps; legacy first-sale SET keeps its absent-ratio contract.
					$policy_expected = '' === $expected && Price_Operation::SALE_DISCOUNT_PERCENT === $operation->data()['type'] ? $s['regular_price'] : $expected;
					$p = '' === $target ? ( '' === $expected ? new Policy_Result( array(), array() ) : Policy_Evaluator::item( $expected, $s['regular_price'], $policy ) ) : Policy_Evaluator::item( $policy_expected, $target, $policy );
					$op = $operation->data();
					if ( isset( $op['ending'] ) && '' !== $expected && '' !== $target ) {
						$direction = Price_Decimal::compare( Price_Decimal::units( $target ), Price_Decimal::units( $expected ) );
						if ( ( $direction > 0 && in_array( $op['type'], array( Price_Operation::DECREASE_FIXED, Price_Operation::DECREASE_PERCENT ), true ) ) || ( $direction < 0 && in_array( $op['type'], array( Price_Operation::INCREASE_FIXED, Price_Operation::INCREASE_PERCENT ), true ) ) ) {
							$p = new Policy_Result( array_merge( $p->data()['blockers'], array( 'price_ending_direction' ) ), $p->data()['warnings'] );
						}
					}
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

	/**
	 * Trusted domain factory; all snapshots are immutable Woo public-API reads.
	 *
	 * `$range_outcome` is the #234 selector outcome for this exact snapshot
	 * population (`matched` must equal the snapshot count). It is frozen
	 * into the hashed material as `price_range` provenance so the review
	 * explains what the filter selected; Apply consumes only these frozen
	 * IDs and never reselects.
	 */
	public static function create( string $id, string $created_at, int $actor, Price_Store_Context $context, Price_Selection_Spec $selection, Price_Operation $operation, Safety_Policy $policy, array $snapshots, ?array $range_outcome = null ): self {
		if ( ! preg_match( '/\A[a-zA-Z0-9_-]{1,64}\z/D', $id ) || ! preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $created_at ) || $actor < 1 ) { throw new Price_Validation_Error( 'invalid_plan_identity' ); }
		if ( count( $snapshots ) > Product_Price_Selector::MAX_SELECTED ) { throw new Price_Validation_Error( 'selection_limit_exceeded' ); }
		$range_outcome = self::range_provenance( $selection, $snapshots, $range_outcome );
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
			'status' => Policy_Result::BLOCKED === $decision['state'] ? 'BLOCKED' : 'PREVIEW', 'price_range' => $range_outcome, 'items' => $item_data,
		);
		$plan->hash = Plan_Hasher::hash( $plan->material );
		return $plan;
	}
	/**
	 * Validate and normalize the #234 range provenance block. An omitted
	 * outcome means "no filtering happened": the disabled filter with the
	 * population fully matched. A supplied outcome must agree with the
	 * frozen snapshots exactly, so planner wiring mistakes refuse loudly
	 * instead of persisting inventive counts.
	 */
	private static function range_provenance( Price_Selection_Spec $selection, array $snapshots, ?array $range_outcome ): array {
		$filter = $selection->price_range();
		if ( null === $range_outcome ) {
			return array( 'filter' => $filter, 'matched' => count( $snapshots ), 'excluded_by_range' => 0, 'unsupported' => 0 );
		}
		foreach ( array( 'matched', 'excluded_by_range', 'unsupported' ) as $key ) {
			if ( ! array_key_exists( $key, $range_outcome ) || ! is_int( $range_outcome[ $key ] ) || $range_outcome[ $key ] < 0 ) {
				throw new Price_Validation_Error( 'invalid_snapshot' );
			}
		}
		if ( $range_outcome['matched'] !== count( $snapshots ) ) { throw new Price_Validation_Error( 'invalid_snapshot' ); }
		return array( 'filter' => $filter, 'matched' => $range_outcome['matched'], 'excluded_by_range' => $range_outcome['excluded_by_range'], 'unsupported' => $range_outcome['unsupported'] );
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
		if ( array_key_exists( 'source_job', $data ) && ( ! is_string( $data['source_job'] ) || ! preg_match( Job_Repository::PUBLIC_ID_REGEX, $data['source_job'] ) ) ) { throw new Price_Validation_Error( 'invalid_source_job' ); }
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
		// #234 range provenance is absent on plans frozen before the filter
		// existed; a present block must carry a well-formed filter and counts.
		if ( array_key_exists( 'price_range', $material ) ) {
			$provenance = $material['price_range'];
			if ( ! is_array( $provenance ) || ! is_array( $provenance['filter'] ?? null )
				|| ! is_int( $provenance['matched'] ?? null ) || ! is_int( $provenance['excluded_by_range'] ?? null ) || ! is_int( $provenance['unsupported'] ?? null )
				|| $provenance['matched'] < 0 || $provenance['excluded_by_range'] < 0 || $provenance['unsupported'] < 0 ) {
				throw new Price_Validation_Error( 'invalid_plan_material' );
			}
			try { Price_Range_Filter::from_data( $provenance['filter'] ); }
			catch ( Price_Validation_Error $error ) { throw new Price_Validation_Error( 'invalid_plan_material' ); }
		}
		// Legacy plans have no `field` key: default to the regular price and
		// never inject the key into the hashed material (old plan_hash values
		// must keep verifying byte-for-byte).
		if ( array_key_exists( 'field', $material['operation'] ) ) {
			try { Price_Operation::assert_field( $material['operation']['field'] ); }
			catch ( Price_Validation_Error $error ) { throw new Price_Validation_Error( 'invalid_plan_material' ); }
		}
		if ( array_key_exists( 'ending', $material['operation'] ) ) {
			try {
				$operation = new Price_Operation( $material['operation']['type'] ?? '', $material['operation']['input'] ?? null, $material['operation']['field'] ?? Price_Operation::FIELD_REGULAR, $material['operation']['ending'] );
				if ( $operation->data() !== $material['operation'] && Plan_Hasher::canonical_json( $operation->data() ) !== Plan_Hasher::canonical_json( $material['operation'] ) ) { throw new Price_Validation_Error( 'invalid_plan_material' ); }
				if ( ! Price_Operation::ending_supported( $material['operation']['ending'], $material['store']['price_decimals'] ) ) { throw new Price_Validation_Error( 'invalid_plan_material' ); }
			} catch ( \Throwable $error ) { throw new Price_Validation_Error( 'invalid_plan_material' ); }
		}
		$plan = new self();
		$plan->identity = array( 'plan_id' => $data['plan_id'], 'created_at' => $data['created_at'] );
		$plan->material = $material;
		$field = $plan->price_field();
		$plan->items = array();
		foreach ( $material['items'] as $stored ) {
			if ( ! is_array( $stored ) ) { throw new Price_Validation_Error( 'invalid_plan_material' ); }
			$item = Change_Plan_Item::hydrate( $stored, $field, $material['operation']['type'] ?? null );
			if ( in_array( $material['operation']['type'] ?? null, array( Price_Operation::CLEAR_SALE, Price_Operation::SALE_DISCOUNT_PERCENT ), true ) ) {
				try { $operation = new Price_Operation( $material['operation']['type'], $material['operation']['input'] ?? null, $field, $material['operation']['ending'] ?? 'default' ); }
				catch ( Price_Validation_Error $error ) { throw new Price_Validation_Error( 'invalid_plan_material' ); }
				if ( Plan_Hasher::canonical_json( $operation->data() ) !== Plan_Hasher::canonical_json( $material['operation'] ) || ( 'UNSUPPORTED' !== $stored['result'] && ( '' === $stored['snapshot']['regular_price'] || ( Price_Operation::CLEAR_SALE === $material['operation']['type'] && '' !== $stored['planned_regular_price'] ) ) ) ) { throw new Price_Validation_Error( 'invalid_plan_material' ); }
			}
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
	/** Attach validated provenance to a fresh immutable preview, never to an approved job. */
	public function with_source_job( string $public_id ): self {
		if ( ! preg_match( Job_Repository::PUBLIC_ID_REGEX, $public_id ) || isset( $this->material['source_job'] ) ) { throw new Price_Validation_Error( 'invalid_source_job' ); }
		$copy = clone $this;
		$copy->material['source_job'] = $public_id;
		$copy->hash = Plan_Hasher::hash( $copy->material );
		return $copy;
	}
	public function hash(): string { return $this->hash; }
	/** Target price field of the frozen plan; legacy plans default to the regular price. */
	public function price_field(): string {
		$field = $this->material['operation']['field'] ?? Price_Operation::FIELD_REGULAR;
		return Price_Operation::assert_field( $field );
	}
	/**
	 * #234 frozen range provenance (`filter` plus matched/excluded_by_range/
	 * unsupported counts), or null for plans frozen before the filter
	 * existed. Review rendering must handle null by showing no range context.
	 */
	public function price_range_context(): ?array {
		$stored = $this->material['price_range'] ?? null;
		return is_array( $stored ) ? $stored : null;
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
			|| (int) ( $old['parent_id'] ?? 0 ) !== (int) ( $now['parent_id'] ?? 0 )
			|| ( ! empty( $old['core_variation'] ) && ( empty( $now['parent_core_variable'] ) || 'publish' !== ( $now['parent_status'] ?? '' ) ) ) ) { $reasons[] = 'product_type_changed'; }
		if ( $old['status'] !== $now['status'] ) { $reasons[] = 'product_status_changed'; }
		if ( Price_Operation::FIELD_SALE === $field ) {
			// Guard the sale price itself. Sale dates are not a conflict:
			// WriteLeash only writes the sale price and preserves the schedule.
			if ( ! Price_Decimal::equal( $now['sale_price'], $item['expected_regular_price'] ) ) { $reasons[] = 'sale_price_changed'; }
			if ( in_array( $this->material['operation']['type'], array( Price_Operation::CLEAR_SALE, Price_Operation::SALE_DISCOUNT_PERCENT ), true ) && ! Price_Decimal::equal( $now['regular_price'], $old['regular_price'] ) ) { $reasons[] = 'regular_price_changed'; }
			// The planned sale must stay strictly below the current regular price.
			try {
				if ( '' !== $item['planned_regular_price'] ) {
					if ( Price_Decimal::compare( Price_Decimal::units( Price_Decimal::parse( $item['planned_regular_price'] ) ), Price_Decimal::units( Price_Decimal::parse( $now['regular_price'] ) ) ) >= 0 ) { $reasons[] = 'sale_price_not_below_regular'; }
				}
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
		// Refuse a forged/unavailable control choice with its actionable reason,
		// before resolving products or persisting an unsupported frozen plan.
		if ( ! Price_Operation::ending_supported( $operation->data()['ending'] ?? 'default', $context->data()['price_decimals'] ) ) { throw new Price_Validation_Error( 'price_ending_precision' ); }
		$resolved = Product_Price_Selector::resolve_with_outcome( $selection );
		$snapshots = $resolved['snapshots'];
		// A selected variable parent is replaced by its exact variation IDs
		// before the plan exists, so the frozen population and the IDS
		// invariant both operate on children only.
		$resolved_selection = Product_Price_Selector::resolved_selection( $selection, $snapshots );
		// Authorize every resolvable source ID before the range narrows
		// the population: the matched subset alone must never decide
		// visibility, or an excluded product would become a price/count
		// oracle. Unreadable rows resolve to a real product, so a denied
		// unreadable ID refuses here instead of leaking through the
		// unsupported count. Missing products have no post row and no
		// prices to disclose (WordPress maps edit_post on them to
		// do_not_allow for every actor, so gating on them would refuse
		// all stale-ID selections); they freeze as explained missing rows
		// when the actor named them.
		foreach ( $resolved['population'] as $snapshot ) {
			$row = $snapshot->data();
			if ( empty( $row['exists'] ) && empty( $row['unreadable'] ) ) { continue; }
			if ( ! current_user_can( 'edit_post', (int) $row['product_id'] ) ) { throw new Price_Validation_Error( 'permission_denied' ); }
		}
		if ( $context->data() !== Price_Store_Context::current()->data() ) { throw new Price_Validation_Error( 'store_context_changed_during_planning' ); }
		return Change_Plan::create( wp_generate_uuid4(), gmdate( 'Y-m-d\TH:i:s\Z' ), $actor, $context, $resolved_selection, $operation, $policy, $snapshots, $resolved['outcome'] );
	}
}
