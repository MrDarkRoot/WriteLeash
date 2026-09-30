<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

final class Change_Plan_Item {
	use Immutable_Price_Value;
	private array $values;
	public function __construct( Product_Price_Snapshot $snapshot, Price_Store_Context $context, Price_Operation $operation, Safety_Policy $policy ) {
		$s = $snapshot->data();
		$eligibility = Product_Price_Eligibility::evaluate( $snapshot, $context )->data();
		$target = null;
		$expected = null;
		$delta = array( 'absolute_delta' => null, 'percentage_delta' => null );
		$result = 'UNSUPPORTED';
		$p = new Policy_Result( array(), array() );
		if ( Eligibility_Result::ELIGIBLE === $eligibility['state'] ) {
			try {
				$expected = Price_Decimal::parse( $s['regular_price'] ); // Preserve raw stored value in snapshot as well.
				$target = Price_Calculator::calculate( $expected, $operation, $context->data()['price_decimals'] );
				$delta = Price_Calculator::delta( $expected, $target );
				$result = '0' === $delta['absolute_delta'] ? 'UNCHANGED' : 'CHANGING';
				$p = Policy_Evaluator::item( $expected, $target, $policy );
			} catch ( Price_Validation_Error $e ) {
				$eligibility = ( new Eligibility_Result( $e->reason() ) )->data();
			}
		}
		$this->values = array_merge( array(
			'product_id' => $s['product_id'], 'snapshot' => $s, 'expected_regular_price' => $expected,
			'planned_regular_price' => $target, 'result' => $result, 'eligibility' => $eligibility,
			'blockers' => $p->data()['blockers'], 'warnings' => $p->data()['warnings'],
		), $delta );
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
			$plan->items[$product_id] = new Change_Plan_Item( $snapshot, $context, $operation, $policy );
		}
		ksort( $plan->items, SORT_NUMERIC );
		if ( 'IDS' === $selection->data()['type'] && array_keys( $plan->items ) !== $selection->data()['ids'] ) { throw new Price_Validation_Error( 'selection_snapshot_mismatch' ); }
		$decision = Policy_Evaluator::plan( $plan->items, $policy )->data();
		$plan->summary = array( 'selected' => count( $plan->items ), 'eligible' => 0, 'changing' => 0, 'unchanged' => 0, 'unsupported' => 0, 'blocked' => 0, 'conflicted' => 0, 'warning_items' => 0, 'warning_count' => count( $decision['warnings'] ), 'selection_warning_count' => count( $selection->data()['warnings'] ) );
		$item_data = array();
		foreach ( $plan->items as $item ) {
			$d = $item->data();
			$item_data[] = $d;
			++$plan->summary[ strtolower( $d['result'] ) ];
			if ( 'UNSUPPORTED' !== $d['result'] ) { ++$plan->summary['eligible']; }
			if ( $d['warnings'] ) { ++$plan->summary['warning_items']; }
		}
		// A plan-level blocker authorizes none of the changing items, including clean ones.
		$plan->summary['blocked'] = Policy_Result::BLOCKED === $decision['state'] ? $plan->summary['changing'] : 0;
		$plan->material = array(
			'schema_version' => self::SCHEMA_VERSION, 'hash_version' => self::HASH_VERSION, 'actor_id' => $actor,
			'store' => $context->data(), 'selection' => $selection->data(), 'resolved_product_ids' => array_keys( $plan->items ),
			'operation' => $operation->data(), 'policy_snapshot' => $policy->data(), 'policy_result' => $decision,
			'status' => Policy_Result::BLOCKED === $decision['state'] ? 'BLOCKED' : 'PREVIEW', 'items' => $item_data,
		);
		$plan->hash = Plan_Hasher::hash( $plan->material );
		return $plan;
	}
	public function hash(): string { return $this->hash; }
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
		$rows = array();
		foreach ( array_slice( $this->items, $offset, $limit ) as $item ) {
			$d = $item->data();
			$s = $d['snapshot'];
			$rows[] = array_merge( $d, array( 'name' => $s['name'], 'sku' => $s['sku'], 'stored_regular_price' => $s['regular_price'] ) );
		}
		return array( 'offset' => $offset, 'limit' => $limit, 'items' => $rows, 'next_offset' => $offset + count( $rows ) < count( $this->items ) ? $offset + count( $rows ) : null );
	}
	/** This is a precondition contract, not approval, locking, or mutation. */
	public function precondition( int $id, Product_Price_Snapshot $current, Price_Store_Context $context ): array {
		if ( 'BLOCKED' === $this->material['status'] ) { return array( 'state' => 'BLOCKED', 'reasons' => array( 'plan_policy_blocked' ) ); }
		$item = $this->item( $id )->data();
		if ( 'CHANGING' !== $item['result'] ) { return array( 'state' => 'NOT_CHANGING', 'reasons' => array( $item['eligibility']['reason'] ?? 'unchanged' ) ); }
		$old = $item['snapshot'];
		$now = $current->data();
		$settings = $context->data();
		$expected = $this->material['store'];
		$reasons = array();
		if ( $now['product_id'] !== $id || ! $now['exists'] ) { $reasons[] = 'missing_product'; }
		if ( $old['type'] !== $now['type'] || $old['core_simple'] !== $now['core_simple'] ) { $reasons[] = 'product_type_changed'; }
		if ( $old['status'] !== $now['status'] ) { $reasons[] = 'product_status_changed'; }
		foreach ( array( 'sale_price', 'sale_from', 'sale_to' ) as $field ) {
			if ( $old[$field] !== $now[$field] ) { $reasons[] = 'sale_configuration_changed'; break; }
		}
		try { $price = Price_Decimal::parse( $now['regular_price'] ); }
		catch ( Price_Validation_Error $e ) { $price = null; }
		if ( $price !== $item['expected_regular_price'] ) { $reasons[] = 'regular_price_changed'; }
		if ( $settings['currency'] !== $expected['currency'] || ! $settings['base_currency_context'] ) { $reasons[] = 'currency_context_changed'; }
		if ( $settings['price_decimals'] !== $expected['price_decimals'] ) { $reasons[] = 'price_decimals_changed'; }
		if ( $settings['wordpress_version'] !== $expected['wordpress_version'] || $settings['woocommerce_version'] !== $expected['woocommerce_version'] ) { $reasons[] = 'software_version_changed'; }
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
		foreach ( $snapshots as $snapshot ) {
			if ( $snapshot->data()['exists'] && ! current_user_can( 'edit_post', $snapshot->data()['product_id'] ) ) { throw new Price_Validation_Error( 'permission_denied' ); }
		}
		if ( $context->data() !== Price_Store_Context::current()->data() ) { throw new Price_Validation_Error( 'store_context_changed_during_planning' ); }
		return Change_Plan::create( wp_generate_uuid4(), gmdate( 'Y-m-d\TH:i:s\Z' ), $actor, $context, $selection, $operation, $policy, $snapshots );
	}
}
