<?php namespace BulkPriceEditor\CLI;

use BulkPriceEditor\Actions\RevertPricesAction;
use BulkPriceEditor\Actions\SchedulePriceUpdatesAction;
use BulkPriceEditor\Actions\UpdateProductPriceAction;
use BulkPriceEditor\History\Run;
use BulkPriceEditor\History\RunsRepository;
use BulkPriceEditor\PriceModifiers\Dispatcher;
use BulkPriceEditor\PriceModifiers\Modifiers\PriceModifier;
use BulkPriceEditor\ProductFilters\FilterManager;
use BulkPriceEditor\ProductFilters\Templates\Template;
use BulkPriceEditor\ProductFilters\Templates\TemplateManager;
use BulkPriceEditor\ProductQuery\ProductQuery;
use WP_CLI;

/**
 * Bulk-update WooCommerce product prices from the command line.
 */
class Commands {

	public static function register(): void {
		WP_CLI::add_command( 'bulk-price-editor', self::class );
	}

	/**
	 * Lists saved templates.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bulk-price-editor templates
	 *
	 * @subcommand templates
	 */
	public function templates( $args, $assocArgs ) {

		$rows = array();

		foreach ( TemplateManager::getInstance()->getTemplates() as $template ) {
			$modifier = $template->getModifier();

			$rows[] = array(
				'slug'     => $template->getSlug(),
				'name'     => $template->getName(),
				'filters'  => count( $template->getFilters() ),
				'modifier' => $modifier ? $modifier['type'] : '—',
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::log( 'No templates saved yet.' );

			return;
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'slug', 'name', 'filters', 'modifier' ) );
	}

	/**
	 * Lists past price updates (the history).
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : How many runs to show. Default 20.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bulk-price-editor history --limit=10
	 *
	 * @subcommand history
	 */
	public function history( $args, $assocArgs ) {

		$limit = isset( $assocArgs['limit'] ) ? max( 1, (int) $assocArgs['limit'] ) : 20;

		$rows = array();

		foreach ( RunsRepository::getRuns( $limit ) as $run ) {

			$createdAt = $run->getTimestamp( 'created_at' );

			$rows[] = array(
				'id'       => $run->getId(),
				'date'     => $createdAt ? wp_date( 'Y-m-d H:i', $createdAt ) : '—',
				'type'     => $run->getType(),
				'summary'  => $run->isRevert() ? 'revert of #' . $run->getParentId() : $run->getModifierType(),
				'products' => $run->getProcessed() . '/' . $run->getTotal(),
				'status'   => $run->getStatus() . ( $run->isReverted() ? ' (reverted)' : '' ),
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::log( 'No price updates yet.' );

			return;
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'date', 'type', 'summary', 'products', 'status' ) );
	}

	/**
	 * Runs a bulk price update.
	 *
	 * ## OPTIONS
	 *
	 * [--template=<slug-or-name>]
	 * : Use a saved template for the filters (and its saved price modification, if any).
	 *
	 * [--filters=<json>]
	 * : Filters as JSON (overrides the template's filters), e.g. '{"bpe_included_category_ids":[15]}'.
	 *
	 * [--modifier=<type>]
	 * : Price modifier type (overrides the template's), e.g. decrease_by_percent.
	 *
	 * [--args=<json>]
	 * : Price modifier arguments as JSON, e.g. '{"bpe_decrease_by_percent":10}'.
	 *
	 * [--dry-run]
	 * : Show what would change without updating anything.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bulk-price-editor run --template=clearance --yes
	 *     wp bulk-price-editor run --modifier=decrease_by_percent --args='{"bpe_decrease_by_percent":10}' --dry-run
	 *
	 * @subcommand run
	 */
	public function run( $args, $assocArgs ) {

		$template = isset( $assocArgs['template'] ) ? $this->findTemplate( (string) $assocArgs['template'] ) : null;

		if ( isset( $assocArgs['template'] ) && ! $template ) {
			WP_CLI::error( 'Template not found. Use `wp bulk-price-editor templates` to list them.' );
		}

		$filters = $template ? $template->getFilters() : array();

		if ( isset( $assocArgs['filters'] ) ) {
			$filters = (array) json_decode( (string) $assocArgs['filters'], true );
		}

		$modifier = $this->resolveModifier( $template, $assocArgs );

		if ( ! $modifier ) {
			WP_CLI::error( 'No price modification given. Pass --modifier/--args or use a template saved with one.' );
		}

		$productIds = $this->queryProducts( $filters );

		if ( empty( $productIds ) ) {
			WP_CLI::error( 'No products match the selected filters.' );
		}

		if ( isset( $assocArgs['dry-run'] ) ) {
			$this->dryRun( $productIds, $modifier );

			return;
		}

		if ( SchedulePriceUpdatesAction::isRunning() ) {
			WP_CLI::error( 'A price update is already in progress.' );
		}

		WP_CLI::confirm( sprintf( 'Update prices for %d products using "%s"?', count( $productIds ),
			$modifier->getName() ), $assocArgs );

		$runId = RunsRepository::insertRun( array(
			'status'        => Run::STATUS_RUNNING,
			'user_id'       => get_current_user_id(),
			'modifier_type' => $modifier->getType(),
			'modifier_args' => wp_json_encode( $modifier->getRawArgs() ),
			'filters'       => wp_json_encode( $filters ),
			'total'         => count( $productIds ),
			'started_at'    => gmdate( 'Y-m-d H:i:s' ),
		) );

		update_option( SchedulePriceUpdatesAction::CURRENT_RUN_OPTION, $runId );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Updating prices', count( $productIds ) );

		foreach ( array_chunk( $productIds, SchedulePriceUpdatesAction::getProductsPerRun() ) as $chunk ) {
			UpdateProductPriceAction::updatePrices( $runId, $chunk );
			$progress->tick( count( $chunk ) );
		}

		$progress->finish();

		WP_CLI::success( sprintf( 'Prices for %d products have been updated (run #%d). Revert with: wp bulk-price-editor revert %d',
			count( $productIds ), $runId, $runId ) );
	}

	/**
	 * Reverts a past price update from its snapshot.
	 *
	 * ## OPTIONS
	 *
	 * <run-id>
	 * : The run to revert (see `wp bulk-price-editor history`).
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bulk-price-editor revert 12
	 *
	 * @subcommand revert
	 */
	public function revert( $args, $assocArgs ) {

		$runId = isset( $args[0] ) ? (int) $args[0] : 0;
		$run   = $runId ? RunsRepository::getRun( $runId ) : null;

		if ( ! $run ) {
			WP_CLI::error( 'Run not found. Use `wp bulk-price-editor history` to list runs.' );
		}

		$changed = count( RunsRepository::getChangedRowIds( $runId ) );

		if ( ! $changed ) {
			WP_CLI::error( 'This run did not change any prices, so there is nothing to revert.' );
		}

		WP_CLI::confirm( sprintf( 'Restore the previous prices for %d products changed by run #%d?', $changed,
			$runId ), $assocArgs );

		$result = RevertPricesAction::startRevert( $run, get_current_user_id(), true );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Previous prices for %d products have been restored.', $changed ) );
	}

	protected function findTemplate( string $slugOrName ): ?Template {

		$manager  = TemplateManager::getInstance();
		$template = $manager->getTemplateBySlug( $slugOrName );

		if ( $template ) {
			return $template;
		}

		foreach ( $manager->getTemplates() as $candidate ) {
			if ( 0 === strcasecmp( $candidate->getName(), $slugOrName ) ) {
				return $candidate;
			}
		}

		return null;
	}

	protected function resolveModifier( ?Template $template, array $assocArgs ): ?PriceModifier {

		$type     = isset( $assocArgs['modifier'] ) ? (string) $assocArgs['modifier'] : '';
		$rawArgs  = isset( $assocArgs['args'] ) ? (array) json_decode( (string) $assocArgs['args'], true ) : array();

		if ( '' === $type && $template && $template->getModifier() ) {
			$saved   = $template->getModifier();
			$type    = $saved['type'];
			$rawArgs = empty( $rawArgs ) ? $saved['args'] : $rawArgs;
		}

		if ( '' === $type ) {
			return null;
		}

		return Dispatcher::getInstance()->dispatchModifier( $type, $rawArgs );
	}

	protected function queryProducts( array $filters ): array {

		$productQuery = new ProductQuery( new FilterManager( $filters ), array(
			'posts_per_page' => - 1,
			'limit'          => - 1,
			'return'         => 'ids',
			'paginate'       => false,
		) );

		return SchedulePriceUpdatesAction::excludeVariationsCoveredByParent(
			$productQuery->build()->get_products() );
	}

	protected function dryRun( array $productIds, PriceModifier $modifier ): void {

		$rows  = array();
		$limit = 25;

		foreach ( array_slice( $productIds, 0, $limit ) as $productId ) {

			$product = wc_get_product( $productId );

			if ( ! $product ) {
				continue;
			}

			$newRegular = $modifier->getFinalRegularPrice( $product );
			$newSale    = $modifier->getFinalSalePrice( $product );

			$rows[] = array(
				'id'          => $product->get_id(),
				'product'     => $product->get_name(),
				'regular'     => (string) $product->get_regular_price( 'edit' ),
				'new regular' => is_null( $newRegular ) ? '(unchanged)' : (string) $newRegular,
				'sale'        => (string) $product->get_sale_price( 'edit' ),
				'new sale'    => is_null( $newSale ) ? '(unchanged)' : (string) $newSale,
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows,
			array( 'id', 'product', 'regular', 'new regular', 'sale', 'new sale' ) );

		if ( count( $productIds ) > $limit ) {
			WP_CLI::log( sprintf( '… and %d more products.', count( $productIds ) - $limit ) );
		}

		WP_CLI::success( sprintf( 'Dry run: %d products would be updated. Nothing was changed.',
			count( $productIds ) ) );
	}
}
