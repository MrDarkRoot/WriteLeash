<?php namespace BulkPriceEditor\History;

/**
 * Records every product price change into the price log — not only the bulk
 * editor's. Capture happens at the postmeta level (_regular_price /
 * _sale_price), which every write path ends in: the product edit screen,
 * CSV importers, the REST API, other plugins, and this plugin's own runs
 * (tagged with their run id via HistoryManager::getCurrentRunId()).
 */
class PriceLogger {

	const META_KEYS = array(
		'_regular_price' => 'regular',
		'_sale_price'    => 'sale',
	);

	/**
	 * Products currently being deleted — the meta cleanup of a deleted
	 * product is not a price change.
	 */
	protected static array $deletingPosts = array();

	public static function init(): void {

		add_action( 'add_post_meta', array( self::class, 'onAddMeta' ), 10, 3 );
		add_action( 'update_post_meta', array( self::class, 'onUpdateMeta' ), 10, 4 );
		add_action( 'delete_post_meta', array( self::class, 'onDeleteMeta' ), 10, 4 );

		add_action( 'before_delete_post', function ( $postId ) {
			self::$deletingPosts[ (int) $postId ] = true;
		} );

		add_action( 'deleted_post', function ( $postId, $post ) {
			unset( self::$deletingPosts[ (int) $postId ] );

			if ( $post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
				PriceLogRepository::deleteForProduct( (int) $postId );
			}
		}, 10, 2 );
	}

	public static function onAddMeta( $objectId, $metaKey, $metaValue ): void {

		if ( ! isset( self::META_KEYS[ $metaKey ] ) ) {
			return;
		}

		self::record( (int) $objectId, self::META_KEYS[ $metaKey ], '', (string) $metaValue );
	}

	public static function onUpdateMeta( $metaId, $objectId, $metaKey, $metaValue ): void {

		if ( ! isset( self::META_KEYS[ $metaKey ] ) ) {
			return;
		}

		$old = (string) get_post_meta( (int) $objectId, $metaKey, true );

		self::record( (int) $objectId, self::META_KEYS[ $metaKey ], $old, (string) $metaValue );
	}

	public static function onDeleteMeta( $metaIds, $objectId, $metaKey, $metaValue ): void {

		if ( ! isset( self::META_KEYS[ $metaKey ] ) ) {
			return;
		}

		// Deleting ALL meta of a key ($metaValue = '') during other flows is
		// rare; treat as clearing the price.
		$old = (string) get_post_meta( (int) $objectId, $metaKey, true );

		if ( '' === $old ) {
			return;
		}

		self::record( (int) $objectId, self::META_KEYS[ $metaKey ], $old, '' );
	}

	protected static function record( int $productId, string $field, string $old, string $new ): void {

		if ( $old === $new || ! PriceLogRepository::isEnabled() ) {
			return;
		}

		if ( isset( self::$deletingPosts[ $productId ] ) ) {
			return;
		}

		$postType = get_post_type( $productId );

		if ( ! in_array( $postType, array( 'product', 'product_variation' ), true ) ) {
			return;
		}

		$source = self::detectSource();

		PriceLogRepository::insert( array(
			'product_id'       => $productId,
			'old_' . $field    => $old,
			'new_' . $field    => $new,
			'source'           => $source['source'],
			'source_id'        => $source['source_id'],
			'user_id'          => get_current_user_id(),
		) );
	}

	/**
	 * Where the change came from. Our own runs are precise (run id); the
	 * rest is a best-effort context label.
	 */
	protected static function detectSource(): array {

		$runId = HistoryManager::getCurrentRunId();

		if ( $runId ) {
			return array( 'source' => 'run', 'source_id' => (int) $runId );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return array( 'source' => 'cli', 'source_id' => 0 );
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return array( 'source' => 'cron', 'source_id' => 0 );
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return array( 'source' => 'api', 'source_id' => 0 );
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return array( 'source' => 'ajax', 'source_id' => 0 );
		}

		if ( is_admin() ) {
			return array( 'source' => 'admin', 'source_id' => 0 );
		}

		return array( 'source' => 'other', 'source_id' => 0 );
	}

	/**
	 * Human label for a log row's source (linked run summaries are built by
	 * the metabox, which has the run context).
	 */
	public static function getSourceLabel( string $source ): string {

		$labels = array(
			'run'   => __( 'Bulk Price Editor', 'bulk-price-editor' ),
			'admin' => __( 'Manual edit', 'bulk-price-editor' ),
			'api'   => __( 'REST API', 'bulk-price-editor' ),
			'ajax'  => __( 'Store request', 'bulk-price-editor' ),
			'cron'  => __( 'Scheduled task', 'bulk-price-editor' ),
			'cli'   => __( 'WP-CLI', 'bulk-price-editor' ),
			'other' => __( 'Other', 'bulk-price-editor' ),
		);

		return $labels[ $source ] ?? $source;
	}
}
