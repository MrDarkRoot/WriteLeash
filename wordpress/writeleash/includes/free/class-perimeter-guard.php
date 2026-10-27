<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * #307 Perimeter Guard + Execution Frame.
 *
 * A restricted principal is a WordPress user who is the actor on a row in
 * `writeleash_grants`. Such a user may only write prices/products through
 * WriteLeash's controlled item transaction, and only while a matching
 * Execution Frame is open. Every other write path (WooCommerce REST,
 * $product->save(), stock helpers, postmeta writes, trash/delete) is denied
 * before persistence.
 *
 * No global "inside WriteLeash" flag, no new table, no new capability. The
 * frame is bound to (actor_id, job_id/plan_id, exact frozen product_ids[],
 * allowed mutation kind/field), opens only after the item transaction
 * acquires its locks, and closes in finally (including forced errors).
 *
 * @package WriteLeash
 */

/**
 * A bounded, matching write window opened only inside WriteLeash's controlled
 * item transaction.
 *
 * Writes to another product, another asset, or another mutation while a frame
 * is open are rejected like no frame. A variable product's parent price sync
 * is authorized only as an explicit extra product id for that item; it never
 * authorizes sibling writes.
 */
final class Execution_Frame {
	public int $actor_id;
	public int $job_id;
	public string $plan_id;
	/** @var int[] exact frozen product ids this frame authorizes. */
	public array $product_ids;
	public string $kind;
	public ?string $field;

	public function __construct( int $actor_id, int $job_id, string $plan_id, array $product_ids, string $kind, ?string $field = null ) {
		$this->actor_id = $actor_id;
		$this->job_id = $job_id;
		$this->plan_id = $plan_id;
		$this->product_ids = array_values( array_map( 'intval', $product_ids ) );
		$this->kind = $kind;
		$this->field = $field;
	}

	/**
	 * True when this frame authorizes a write for $actor_id on $product_id of
	 * $kind. Field is part of the durable binding but is not re-verified at
	 * hook level; the controlled mutator already enforces the exact field.
	 */
	public function matches( int $actor_id, int $product_id, string $kind ): bool {
		return $this->actor_id === $actor_id
			&& in_array( $product_id, $this->product_ids, true )
			&& $this->kind === $kind;
	}
}

/**
 * Fail-closed perimeter for restricted (standing-grant) principals.
 *
 * Hooks apply only to restricted principals (`is_restricted_actor() == true`)
 * when there is no matching active Execution Frame. Denials happen before
 * persistence and are logged.
 */
final class Perimeter_Guard {
	private const DENY_CODE = 'writeleash_perimeter_denied';
	/**
	 * Meta keys WriteLeash's controlled path never writes outside a frame.
	 * Sale-price schedule dates are included: the #298 grant scope freezes
	 * absolute regular/sale targets without an out-of-scope schedule change,
	 * so a restricted principal cannot move sale windows directly.
	 */
	private const PRICE_META_KEYS = array( '_regular_price', '_sale_price', '_price', '_sale_price_dates_from', '_sale_price_dates_to' );
	private const STOCK_META_KEYS = array( '_stock', '_stock_status' );
	/** Product post types in scope. */
	private const PRODUCT_POST_TYPES = array( 'product', 'product_variation' );

	/** @var Execution_Frame[] */
	private static $stack = array();

	public static function open_frame( int $actor_id, int $job_id, string $plan_id, array $product_ids, string $kind, ?string $field = null ): void {
		self::$stack[] = new Execution_Frame( $actor_id, $job_id, $plan_id, $product_ids, $kind, $field );
	}

	public static function close_frame(): void {
		array_pop( self::$stack );
	}

	public static function current_frame(): ?Execution_Frame {
		return self::$stack ? end( self::$stack ) : null;
	}

	/**
	 * True when an active frame authorizes this write. Iterates the whole
	 * stack so nested frames cannot be bypassed by writing to a product a
	 * different (inner) frame covers.
	 */
	public static function has_matching_frame( int $actor_id, int $product_id, string $kind ): bool {
		foreach ( self::$stack as $frame ) {
			if ( $frame->matches( $actor_id, $product_id, $kind ) ) {
				return true;
			}
		}
		return false;
	}

	public static function boot(): void {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest_pre_dispatch' ), 10, 3 );
		add_action( 'woocommerce_before_product_object_save', array( __CLASS__, 'before_product_save' ), 10, 1 );
		add_action( 'woocommerce_before_product_variation_object_save', array( __CLASS__, 'before_variation_save' ), 10, 1 );
		add_action( 'woocommerce_product_before_set_stock', array( __CLASS__, 'product_before_set_stock' ), 10, 1 );
		add_action( 'woocommerce_variation_before_set_stock', array( __CLASS__, 'variation_before_set_stock' ), 10, 1 );
		add_filter( 'woocommerce_update_product_stock_query', array( __CLASS__, 'update_product_stock_query' ), 10, 4 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'update_post_metadata' ), 10, 5 );
		add_filter( 'add_post_metadata', array( __CLASS__, 'add_post_metadata' ), 10, 5 );
		add_filter( 'delete_post_metadata', array( __CLASS__, 'delete_post_metadata' ), 10, 5 );
		add_filter( 'pre_trash_post', array( __CLASS__, 'pre_trash_post' ), 10, 2 );
		add_filter( 'pre_delete_post', array( __CLASS__, 'pre_delete_post' ), 10, 3 );
	}

	// ------------------------------------------------------------------
	// REST: wc/v1+ products/catalog write surfaces blocked for restricted
	// principals (products, per-id CRUD, batch, variations, categories,
	// attributes, reviews, shipping classes, tags).
	// ------------------------------------------------------------------

	/**
	 * True when the request targets a WooCommerce products write surface
	 * (wc/v1-v3 products and every catalog surface under its prefix). REST
	 * routes carry a leading slash; normalize before matching.
	 *
	 * The products prefix and everything below it is denied in one pattern:
	 * single create/get/update/delete per id, /batch, per-id /variations,
	 * /categories, /attributes (+ attribute terms), /reviews,
	 * /shipping_classes and /tags. Additional write surfaces keep matching
	 * under the broad prefix; the multi-digit tail accepts namespaced minor
	 * versions beyond v3 so a new namespace cannot silently open a path.
	 * Intentionally fail-closed: enumerating routes would deny less on every
	 * future WooCommerce route addition.
	 */
	private static function is_restricted_product_route( $request ): bool {
		if ( ! $request instanceof \WP_REST_Request ) {
			return false;
		}
		$route = '/' . ltrim( (string) $request->get_route(), '/' );
		return (bool) preg_match( '#\A/wc/v[1-9][0-9]*/products(?:/.*)?\z#D', $route );
	}

	/**
	 * WordPress runs `rest_pre_dispatch` after `serve_request()` has already
	 * resolved authentication, so the current user is authoritative here.
	 * A restricted principal is refused on every WooCommerce products write
	 * route before the handler or permission callback runs; the controlled
	 * path writes through Woo CRUD, never REST, so no frame authorizes REST.
	 *
	 * @param mixed            $result  Short-circuit result from an earlier filter, else null.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request Request used to generate the response.
	 * @return mixed Non-null only when a restricted principal must be refused.
	 */
	public static function rest_pre_dispatch( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result;
		}
		if ( ! self::is_restricted_product_route( $request ) ) {
			return $result;
		}
		$method = (string) $request->get_method();
		if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			return $result;
		}
		if ( ! Grant_Repository::is_restricted_actor() ) {
			return $result;
		}
		$route = (string) $request->get_route();
		self::deny( 'rest', (int) get_current_user_id(), 0, $route );
		return new \WP_Error(
			self::DENY_CODE,
			'WriteLeash restricted actors must write products through the grant job route (POST /wp-json/writeleash/v1/grants/{uuid}/jobs); direct WooCommerce REST product writes are denied.',
			array( 'status' => 403, 'writeleash' => self::DENY_CODE )
		);
	}

	// ------------------------------------------------------------------
	// Woo CRUD product saves (price/stock mutation paths).
	// ------------------------------------------------------------------

	public static function before_product_save( \WC_Product $product ): void {
		if ( ! self::should_enforce_save( (int) $product->get_id() ) ) {
			return;
		}
		self::throw_denied( 'product_save', $product->get_id(), 'price' );
	}

	public static function before_variation_save( \WC_Product $product ): void {
		if ( ! self::should_enforce_save( (int) $product->get_id() ) ) {
			return;
		}
		self::throw_denied( 'variation_save', $product->get_id(), 'price' );
	}

	// ------------------------------------------------------------------
	// Stock writes.
	// ------------------------------------------------------------------

	public static function product_before_set_stock( $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		if ( ! self::should_enforce( $product, 'stock' ) ) {
			return;
		}
		self::throw_denied( 'product_set_stock', $product->get_id(), 'stock' );
	}

	public static function variation_before_set_stock( $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		if ( ! self::should_enforce( $product, 'stock' ) ) {
			return;
		}
		self::throw_denied( 'variation_set_stock', $product->get_id(), 'stock' );
	}

	public static function update_product_stock_query( $sql, $product_id, $quantity, $decrease ) {
		if ( ! self::should_enforce_id( (int) $product_id, 'stock' ) ) {
			return $sql;
		}
		self::throw_denied( 'update_product_stock_query', $product_id, 'stock' );
	}

	// ------------------------------------------------------------------
	// Postmeta writes on protected price/stock keys.
	// ------------------------------------------------------------------

	public static function update_post_metadata( $check, $object_id, $meta_key, $meta_value, $prev_value = '' ) {
		if ( null !== $check ) {
			return $check;
		}
		$kind = self::meta_kind( $meta_key );
		if ( null === $kind || ! self::should_enforce_id( (int) $object_id, $kind ) ) {
			return null;
		}
		self::deny( 'update_post_metadata', (int) $object_id, $kind, (string) $meta_key );
		return false;
	}

	public static function add_post_metadata( $check, $object_id, $meta_key, $meta_value, $unique = false ) {
		if ( null !== $check ) {
			return $check;
		}
		$kind = self::meta_kind( $meta_key );
		if ( null === $kind || ! self::should_enforce_id( (int) $object_id, $kind ) ) {
			return null;
		}
		self::deny( 'add_post_metadata', (int) $object_id, $kind, (string) $meta_key );
		return false;
	}

	public static function delete_post_metadata( $check, $object_id, $meta_key, $meta_value = '', $delete_all = false ) {
		if ( null !== $check ) {
			return $check;
		}
		$kind = self::meta_kind( $meta_key );
		if ( null === $kind || ! self::should_enforce_id( (int) $object_id, $kind ) ) {
			return null;
		}
		self::deny( 'delete_post_metadata', (int) $object_id, $kind, (string) $meta_key );
		return false;
	}

	// ------------------------------------------------------------------
	// Trash / delete.
	// ------------------------------------------------------------------

	public static function pre_trash_post( $check, $post ) {
		// WP applies `pre_trash_post` as a filter with a null sentinel; any
		// non-null return short-circuits `wp_trash_post()`. Pass through when
		// the principal is not restricted or a matching frame is active.
		if ( null !== $check ) {
			return $check;
		}
		$post_id = self::post_id_from( $post );
		if ( $post_id < 1 || ! self::is_product_post_type( $post_id ) || ! self::should_enforce_id( $post_id, 'trash' ) ) {
			return null;
		}
		// Throwing (not returning a WP_Error) keeps `WC_Product::delete()`
		// honest: its data store ignores a returned value and would still
		// report the product as deleted.
		self::throw_denied( 'pre_trash_post', $post_id, 'trash' );
	}

	public static function pre_delete_post( $check, $post = null, $force_delete = null ) {
		// Current WP applies `pre_delete_post` as a filter with a null
		// sentinel: ( $check, $post, $force_delete ). Tolerate the legacy
		// action form where the first argument is the post object.
		$post_id = self::post_id_from( $check instanceof \WP_Post ? $check : $post );
		if ( $post_id < 1 || ! self::is_product_post_type( $post_id ) || ! self::should_enforce_id( $post_id, 'delete' ) ) {
			return $check;
		}
		self::throw_denied( 'pre_delete_post', $post_id, 'delete' );
	}

	// ------------------------------------------------------------------
	// Internals.
	// ------------------------------------------------------------------

	private static function should_enforce( \WC_Product $product, string $kind ): bool {
		return self::should_enforce_id( (int) $product->get_id(), $kind );
	}

	/**
	 * The object-save event is asset-agnostic (one event fires for any
	 * product save, before the data store decides which props changed), so
	 * the save-level gate accepts an Execution Frame matching the product for
	 * either catalog asset kind ('price' or 'stock'). The exact per-kind
	 * authority stays with the meta-key hooks below and the stock-specific
	 * hooks: a stock frame never authorizes a price meta write and a price
	 * frame never authorizes a stock meta write — those remain denied like no
	 * frame. #310.
	 *
	 * WooCommerce fires this hook before `wp_insert_post` and the new product
	 * id is still 0. No Execution Frame can name that insert, so a restricted
	 * principal is denied before the post row exists. #306.
	 */
	private static function should_enforce_save( int $object_id ): bool {
		if ( ! Grant_Repository::is_restricted_actor() ) {
			return false;
		}
		if ( $object_id < 1 ) {
			return true;
		}
		$actor = (int) get_current_user_id();
		foreach ( self::$stack as $frame ) {
			if ( $frame->matches( $actor, $object_id, 'price' ) || $frame->matches( $actor, $object_id, 'stock' ) ) {
				return false;
			}
		}
		return true;
	}

	private static function should_enforce_id( int $object_id, string $kind ): bool {
		if ( $object_id < 1 ) {
			return false;
		}
		if ( ! Grant_Repository::is_restricted_actor() ) {
			return false;
		}
		return ! self::has_matching_frame( (int) get_current_user_id(), $object_id, $kind );
	}

	private static function meta_kind( string $meta_key ): ?string {
		if ( in_array( $meta_key, self::PRICE_META_KEYS, true ) ) {
			return 'price';
		}
		if ( in_array( $meta_key, self::STOCK_META_KEYS, true ) ) {
			return 'stock';
		}
		return null;
	}

	private static function is_product_post_type( int $post_id ): bool {
		$type = get_post_type( $post_id );
		return is_string( $type ) && in_array( $type, self::PRODUCT_POST_TYPES, true );
	}

	private static function post_id_from( $post ): int {
		if ( is_object( $post ) && isset( $post->ID ) ) {
			return (int) $post->ID;
		}
		if ( is_array( $post ) && isset( $post['ID'] ) ) {
			return (int) $post['ID'];
		}
		return (int) $post;
	}

	private static function deny( string $where, int $object_id, string $kind, string $detail = '' ): void {
		$actor = (int) get_current_user_id();
		do_action( 'writeleash_perimeter_denied', self::DENY_CODE, $where, $actor, $object_id, $kind, $detail );
	}

	/**
	 * Log the refusal, then throw. Never returns; the caller's write never
	 * reaches persistence.
	 */
	private static function throw_denied( string $where, int $object_id, string $kind ): void {
		self::deny( $where, $object_id, $kind );
		if ( class_exists( 'WC_Data_Exception' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Typed machine reason code; not HTML output.
			throw new \WC_Data_Exception( self::DENY_CODE, 'WriteLeash denied this write outside a matching Execution Frame.' );
		}
		throw new \RuntimeException( 'WriteLeash denied this write outside a matching Execution Frame.' );
	}
}
