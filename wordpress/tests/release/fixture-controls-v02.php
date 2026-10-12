<?php
// Assertion-strength controls, NOT a substitute for installed WordPress/DB tests.
// Execute the actual subject fixtures; only their dependencies are test doubles.
namespace WriteLeash {
class Price_Operation {
	public const FIELD_REGULAR = 'regular_price', FIELD_SALE = 'sale_price', SET = 'SET';
	public $target, $field;
	public function __construct( $type, $target, $field = self::FIELD_REGULAR ) { $this->target = $target; $this->field = $field; }
}
class Price_Selection_Spec { public static function ids( $ids ) { return $ids; } }
class Safety_Policy { public function __construct( ...$args ) {} }
class Control_Plan {
	public $ids, $op, $pid;
	public function __construct( $ids, $op, $pid ) { $this->ids = $ids; $this->op = $op; $this->pid = $pid; }
	public function data() { return array( 'plan_id' => $this->pid, 'resolved_product_ids' => $this->ids ); }
	public function summary() { return array( 'selected' => count( $this->ids ) ); }
}
class Woo_Price_Planner {
	public static function preview( $ids, $op, $policy ) { return new Control_Plan( $ids, $op, 'plan-' . count( Job_Repository::$jobs ) ); }
}
class Job_Repository {
	public static $jobs = array();
	public static function create_from_plan( $plan, $actor ) {
		$id = count( self::$jobs ) + 1;
		return self::$jobs[$id] = array( 'id' => $id, 'public_id' => (string) $id, 'plan_id' => $plan->pid, 'plan' => $plan, 'status' => 'READY', 'counts' => array( 'applied' => 0, 'unchanged' => 0 ) );
	}
	public static function approve( $id, $actor ) {
		$plan = self::$jobs[$id]['plan'];
		foreach ( $plan->ids as $pid ) {
			$old = $GLOBALS['products253'][$pid]->field( $plan->op->field );
			if ( $old !== $plan->op->target ) { Price_Apply_Journal::$rows[$plan->pid][$pid] = array( 'product_id' => $pid, 'state' => 'PENDING' ); }
		}
		return self::$jobs[$id];
	}
	public static function read( $id ) { return self::$jobs[$id]; }
	public static function hydrate_plan( $job ) { return $job['plan']; }
	public static function counts( $id ) { return self::$jobs[$id]['counts']; }
}
class Job_State { public static function is_terminal( $state ) { return 'COMPLETED' === $state; } }
class Job_Worker {
	public static function run( $id, $limits, $manual ) {
		$job = &Job_Repository::$jobs[$id]; $plan = $job['plan'];
		foreach ( $plan->ids as $pid ) {
			$p = $GLOBALS['products253'][$pid];
			if ( $p->parent && 'draft' === $GLOBALS['products253'][$p->parent]->status ) {
				if ( 'blind-variations' === $GLOBALS['fault253'] ) { $p->save(); }
				Price_Apply_Journal::$rows[$plan->pid][$pid] = array( 'product_id' => $pid, 'state' => 'CONFLICT' ); continue;
			}
			$old = $p->field( $plan->op->field );
			if ( $old === $plan->op->target ) { ++$job['counts']['unchanged']; continue; }
			$p->assign( $plan->op->field, $plan->op->target ); $p->save();
			++$job['counts']['applied'];
			Price_Apply_Journal::$rows[$plan->pid][$pid] = array( 'product_id' => $pid, 'state' => 'APPLIED', 'expected_price' => $old, 'target_price' => $plan->op->target );
			$job['original'][$pid] = array( 'price' => $old, 'parent' => $p->parent, 'field' => $plan->op->field );
		}
		$job['status'] = 'COMPLETED';
	}
}
class Price_Apply_Journal {
	public static $rows = array();
	public static function read( $db, $plan, $id ) { return self::$rows[$plan][$id] ?? null; }
	public static function table( $db ) { return 'apply_items'; }
}
class Product_Price_Snapshot { public static function fresh_product( $id ) { return $GLOBALS['products253'][$id]; } }
class Price_Cache_Verifier { public static function invalidate( $id ) {} public static function assert_parent_range( ...$args ) {} }
class Price_Decimal { public static function parse( $value ) { return (string) $value; } }
class Undo_Item_State { public const CONFLICT = 'CONFLICT', UNDONE = 'UNDONE'; }
class Undo_State { public static function is_terminal( $state ) { return 'COMPLETED' === $state; } }
class Undo_Schema { public static function items_table( $db ) { return 'undo_items'; } }
class Undo_Repository {
	public static $ops = array(), $rows = array();
	public static function initiate( $job, $actor ) { $id = count( self::$ops ) + 1; return self::$ops[$id] = array( 'id' => $id, 'job' => $job, 'status' => 'READY', 'undone' => 0 ); }
	public static function read_operation( $id ) { return self::$ops[$id]; }
	public static function read_item( $db, $job, $product ) { return self::$rows[$job][$product] ?? null; }
	public static function counts( $id ) { return array( 'undone' => self::$ops[$id]['undone'] ); }
}
class Undo_Worker {
	public static function run( $id, $limits, $manual ) {
		$op = &Undo_Repository::$ops[$id]; $job = Job_Repository::$jobs[$op['job']];
		foreach ( $job['original'] ?? array() as $pid => $old ) {
			$p = $GLOBALS['products253'][$pid];
			if ( $p->parent !== $old['parent'] ) {
				if ( 'blind-variations' === $GLOBALS['fault253'] ) { $p->save(); }
				Undo_Repository::$rows[$op['job']][$pid] = array( 'product_id' => $pid, 'state' => 'CONFLICT' ); continue;
			}
			// Deliberate B3 fault: claim successful durable outcomes but do not restore.
			if ( 'false-undone' !== $GLOBALS['fault253'] ) { $p->assign( $old['field'], $old['price'] ); $p->save(); }
			++$op['undone']; Undo_Repository::$rows[$op['job']][$pid] = array( 'product_id' => $pid, 'state' => 'UNDONE' );
		}
		$op['status'] = 'COMPLETED';
	}
}
class Free_Admin {
	public const ACTION_PREVIEW = 'preview', ACTION_APPROVE = 'approve';
	public static function process_preview( $post, $method ) {
		$ids = array_map( 'intval', explode( ',', $post['ids'] ) );
		if ( count( $ids ) > 1000 ) { return array( 'status' => 'INVALID', 'reason' => 'supported_job_limit_exceeded' ); }
		$plan = Woo_Price_Planner::preview( $ids, new Price_Operation( 'SET', $post['amount'] ), null );
		$job = Job_Repository::create_from_plan( $plan, 1 ); return array( 'status' => 'OK', 'job_id' => $job['id'] );
	}
	public static function process_approve( $post, $method ) { Job_Repository::approve( (int) $post['job'], 1 ); return array( 'status' => 'OK' ); }
}
}
namespace {
$products253 = array(); $next253 = 1; $hooks253 = array(); $fault253 = $argv[3];
function wp_set_current_user( $id ) {}
function wp_generate_uuid4() { return uniqid(); }
function wp_create_nonce( $action ) { return 'nonce'; }
function wc_get_product( $id ) { return $GLOBALS['products253'][$id]; }
function add_action( $hook, $callback, ...$args ) { $GLOBALS['hooks253'][$hook][] = $callback; }
function remove_action( $hook, $callback, ...$args ) { $GLOBALS['hooks253'][$hook] = array_filter( $GLOBALS['hooks253'][$hook], static function ( $cb ) use ( $callback ) { return $cb !== $callback; } ); }
class WC_Product_Simple {
	public $id = 0, $parent = 0, $regular = '', $sale = '', $status = 'publish';
	public function set_name( $value ) {} public function set_status( $value ) { $this->status = $value; }
	public function set_regular_price( $value ) { $this->regular = $value; } public function set_sale_price( $value ) { $this->sale = $value; }
	public function set_parent_id( $value ) { $this->parent = $value; } public function get_parent_id( $context = '' ) { return $this->parent; }
	public function get_id() { return $this->id; } public function get_regular_price( $context = '' ) { return $this->regular; }
	public function get_sale_price( $context = '' ) { return $this->sale; }
	public function field( $field ) { return 'regular_price' === $field ? $this->regular : $this->sale; }
	public function assign( $field, $value ) { if ( 'regular_price' === $field ) { $this->regular = $value; } else { $this->sale = $value; } }
	public function save() {
		// B2: preserve unrelated parent events while blinding all variation events.
		if ( ! ( $this instanceof WC_Product_Variation && 'blind-variations' === $GLOBALS['fault253'] ) ) {
			foreach ( $GLOBALS['hooks253']['woocommerce_before_product_object_save'] ?? array() as $cb ) { $cb( $this ); }
		}
		if ( ! $this->id ) { $this->id = $GLOBALS['next253']++; }
		$GLOBALS['products253'][$this->id] = $this;
	}
}
class WC_Product_Variation extends WC_Product_Simple {}
class WC_Product_Variable extends WC_Product_Simple { public static function sync( $product ) { if ( is_int( $product ) ) { $product = wc_get_product( $product ); } $product->save(); } }
class Control_DB {
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	public function get_col( $query ) {
		list( $sql, $args ) = $query;
		$rows = 'apply_items' === $args[0] ? ( \WriteLeash\Price_Apply_Journal::$rows[$args[1]] ?? array() ) : ( \WriteLeash\Undo_Repository::$rows[$args[1]] ?? array() );
		$ids = array_keys( $rows ); sort( $ids, SORT_NUMERIC ); return $ids;
	}
}
$wpdb = new Control_DB();
require $argv[1] . '/wordpress/tests/release/' . ( 'parent' === $argv[2] ? 'parent-state-v02.php' : 'scale-v02.php' );
}
