<?php namespace BulkPriceEditor\PriceEditorPage;

use Automattic\WooCommerce\Admin\PageController;
use BulkPriceEditor\Actions\SchedulePriceUpdatesAction;
use BulkPriceEditor\Core\ServiceContainerTrait;
use BulkPriceEditor\Freemius;
use BulkPriceEditor\PriceEditorPage\Modals\ConfirmUpdate;
use BulkPriceEditor\PriceEditorPage\Modals\SaveTemplate;
use BulkPriceEditor\PriceEditorPage\Modals\SelectTemplate;
use BulkPriceEditor\PriceEditorPage\Notifications\NotificationManager;
use BulkPriceEditor\PriceEditorPage\Services\LookupService;
use BulkPriceEditor\PriceEditorPage\Widgets\PriceModifiers\PriceModifiersWidget;
use BulkPriceEditor\PriceEditorPage\Widgets\ProductsList;
use BulkPriceEditor\BulkPriceEditorPlugin;

class PriceEditorPage {
	
	use ServiceContainerTrait;
	
	const PAGE_SLUG = 'bulk-price-editor';
	
	public function __construct() {

		new NoticeGuard();

		add_action( 'admin_menu', array( $this, 'registerPage' ) );
		
		add_filter( 'woocommerce_screen_ids', function ( $ids ) {
			$ids[] = 'woocommerce_page_' . self::PAGE_SLUG;
			
			return $ids;
		} );
		
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		
		add_action( 'init', function () {
			$this->getContainer()->initService( LookupService::class );
		} );
	}
	
	public function getModals(): array {
		return array(
			ConfirmUpdate::class,
			SelectTemplate::class,
			SaveTemplate::class,
		);
	}
	
	public function enqueueAssets( $screen ) {
		
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $screen ) {
			return;
		}
		
		wp_enqueue_style( 'bulk-price-editor-page-css',
			$this->getContainer()->getFileManager()->locateAsset( 'admin/bulk-price-editor.css' ), array(),
			BulkPriceEditorPlugin::VERSION );
		
		wp_enqueue_script( 'bulk-price-editor-page-js',
			$this->getContainer()->getFileManager()->locateAsset( 'admin/bulk-price-editor.js' ),
			array( 'jquery-blockui', 'jquery' ), BulkPriceEditorPlugin::VERSION, true );

		wp_localize_script( 'bulk-price-editor-page-js', 'bulkPriceEditorData', array(
			'exportPreviewURL' => \BulkPriceEditor\Actions\ExportCsvAction::getExportPreviewURL(),
			'i18n'             => array(
				'selectModification' => __( 'Please select a price modification and input data.', 'bulk-price-editor' ),
				'noProducts'         => __( 'No products match the selected filters.', 'bulk-price-editor' ),
				'genericError'       => __( 'Something went wrong. Please try again.', 'bulk-price-editor' ),
				'tableError'         => __( 'Failed to load table data', 'bulk-price-editor' ),
				/* translators: %d: number of products */
				'confirmText'        => __( 'Please confirm that you want to update prices for the selected products. This action will affect %d products.', 'bulk-price-editor' ),
				'confirmUndoNote'    => __( 'You will be able to revert this update from the History tab.', 'bulk-price-editor' ),
				'scheduleMissing'    => __( 'Please pick a date and time for the scheduled update.', 'bulk-price-editor' ),
			),
		) );
	}
	
	public function registerPage() {

		if ( class_exists( '\Automattic\WooCommerce\Admin\PageController' ) ) {
			PageController::get_instance()->connect_page( array(
				'id'        => self::PAGE_SLUG,
				'title'     => array( 'Bulk Price Editor' ),
				'screen_id' => self::PAGE_SLUG,
			) );
		}

		add_submenu_page( 'woocommerce', __( 'Bulk Price Editor', 'bulk-price-editor' ),
			__( 'Bulk Price Editor', 'bulk-price-editor' ), BulkPriceEditorPlugin::capability(), self::PAGE_SLUG,
			array( $this, 'render' ) );
	}

	public static function isHistoryTab(): bool {
		return isset( $_GET['tab'] ) && 'history' === $_GET['tab'];
	}

	public static function isScheduledTab(): bool {
		return isset( $_GET['tab'] ) && 'scheduled' === $_GET['tab'];
	}

	public static function isSettingsTab(): bool {
		return isset( $_GET['tab'] ) && 'settings' === $_GET['tab'];
	}

	public function render() {
		?>
		<div id="bulk-price-editor-page">

			<?php $this->renderHeader(); ?>

			<div class="bulk-price-editor">
				<div class="bulk-price-editor__inner">

					<?php if ( self::isSettingsTab() ) : ?>
						<?php ( new SettingsTab() )->render(); ?>
					<?php elseif ( self::isHistoryTab() || self::isScheduledTab() ) : ?>
						<?php ( new HistoryTab( self::isScheduledTab() ) )->render(); ?>
					<?php elseif ( SchedulePriceUpdatesAction::isRunning() ) : ?>
						<?php $this->renderLoadingState(); ?>
					<?php else : ?>
						<div class="bulk-price-editor-widgets">
							<?php
								( new Widgets\ProductFilters\ProductFiltersWidget() )->display();
								( new PriceModifiersWidget() )->display();
								( new ProductsList\ProductsListWidget() )->display();
							?>
						</div>
					<?php endif; ?>

				</div>
			</div>

			<?php $this->renderSnackBar(); ?>

			<?php $this->renderModalBoxes(); ?>
		</div>
		<?php
	}
	
	protected function renderSnackBar() {
		?>
		<div class="bulk-price-editor-snackbar" id="bulk-price-editor-snackbar">
			<div class="bulk-price-editor-snackbar__inner">
				
				<?php foreach ( NotificationManager::getInstance()->getAllNotifications() as $notification ) : ?>
					<div class="bulk-price-editor-snackbar-message
							 bulk-price-editor-snackbar-message--<?php echo esc_attr( $notification->getType() ); ?>
							 <?php echo $notification->isAutoHide() ? 'bulk-price-editor-snackbar-message--auto-close' : ''; ?>">

						<div class="bulk-price-editor-snackbar-message__content">
							<div class="bulk-price-editor-snackbar-message__icon">
								<span class="dashicons"></span>
							</div>
							<div class="bulk-price-editor-snackbar-message__text">
								<?php echo wp_kses_post( $notification->getMessage() ); ?>
							</div>
						</div>

						<div class="bulk-price-editor-snackbar-message__close">
							<span class="dashicons dashicons-no-alt"></span>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
	
	protected function renderHeader() {

		$isEditorTab    = ! self::isHistoryTab() && ! self::isScheduledTab() && ! self::isSettingsTab();
		$scheduledCount = \BulkPriceEditor\History\RunsRepository::countRuns( true );
		$isPremium      = bpefw_fs()->can_use_premium_code();

		?>
		<div class="bulk-price-editor-header">

			<div class="bulk-price-editor-header__brand">
				<div class="bulk-price-editor-header__title">Bulk Price Editor</div>
			</div>

			<nav class="bulk-price-editor-header-tabs" aria-label="<?php esc_attr_e( 'Bulk Price Editor sections', 'bulk-price-editor' ); ?>">
				<?php $this->renderHeaderTab( self::getPageURL(), __( 'Editor', 'bulk-price-editor' ), 'edit', $isEditorTab ); ?>
				<?php $this->renderHeaderTab( self::getScheduledURL(), __( 'Scheduled', 'bulk-price-editor' ), 'clock', self::isScheduledTab(), $scheduledCount ); ?>
				<?php $this->renderHeaderTab( self::getHistoryURL(), __( 'History', 'bulk-price-editor' ), 'backup', self::isHistoryTab() ); ?>
				<?php $this->renderHeaderTab( self::getSettingsURL(), __( 'Settings', 'bulk-price-editor' ), 'admin-generic', self::isSettingsTab() ); ?>
			</nav>

			<div class="bulk-price-editor-header__actions">

				<?php if ( $isPremium ) : ?>
					<span class="bulk-price-editor-header__plan bulk-price-editor-header__plan--premium">
						<span class="dashicons dashicons-yes-alt"></span>
						<?php esc_html_e( 'Premium', 'bulk-price-editor' ); ?>
					</span>
					<a href="<?php echo esc_url( Freemius::getAccountURL() ); ?>"
					   class="bulk-price-editor-button bulk-price-editor-button--secondary bulk-price-editor-button--small">
						<?php esc_html_e( 'My Account', 'bulk-price-editor' ); ?>
					</a>
				<?php else : ?>
					<span class="bulk-price-editor-header__plan">
						<?php esc_html_e( 'Free plan', 'bulk-price-editor' ); ?>
					</span>
					<a href="<?php echo esc_url( bpefw_fs_upgrade_url() ); ?>" target="_blank"
					   class="bulk-price-editor-button bulk-price-editor-button--upgrade">
						<span class="dashicons dashicons-star-filled"></span>
						<?php esc_html_e( 'Upgrade', 'bulk-price-editor' ); ?>
					</a>
				<?php endif; ?>

				<a href="<?php echo esc_url( BulkPriceEditorPlugin::getDocumentationURL() ); ?>" target="_blank"
				   class="bulk-price-editor-header__link">
					<?php esc_html_e( 'Docs', 'bulk-price-editor' ); ?>
				</a>

				<a href="<?php echo esc_url( Freemius::getContactURL() ); ?>"
				   class="bulk-price-editor-header__link">
					<?php esc_html_e( 'Support', 'bulk-price-editor' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	protected function renderHeaderTab( string $url, string $label, string $icon, bool $active, int $badge = 0 ) {
		?>
		<a href="<?php echo esc_url( $url ); ?>"
		   class="bulk-price-editor-header-tab <?php echo $active ? 'bulk-price-editor-header-tab--active' : ''; ?>"
			<?php echo $active ? 'aria-current="page"' : ''; ?>>
			<span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>"></span>
			<?php echo esc_html( $label ); ?>
			<?php if ( $badge ) : ?>
				<span class="bulk-price-editor-header-tab__badge"><?php echo esc_html( $badge ); ?></span>
			<?php endif; ?>
		</a>
		<?php
	}

	protected function renderModalBoxes() {
		?>
		<div class="bulk-price-editor-modals">
			<?php
				foreach ( $this->getModals() as $modal ) :
					$instance = new $modal();
					?>
					<div class="bulk-price-editor-modal"
						 id="bulk-price-editor-modal--<?php echo esc_attr( $instance->getSLUG() ); ?>">
						<div class="bulk-price-editor-modal__overlay"></div>
						<div class="bulk-price-editor-modal__inner">
							<?php
								$instance->render();
							?>
						</div>
					</div>
				<?php endforeach; ?>
		</div>
		<?php
	}
	
	public function renderLoadingState() {
		
		$progress   = SchedulePriceUpdatesAction::getProgressData();

		$progress['processed'] = isset( $progress['processed'] ) ? intval( $progress['processed'] ) : 0;
		$progress['total']     = isset( $progress['total'] ) ? intval( $progress['total'] ) : 0;

		$percentage = $progress['total'] > 0 ? round( $progress['processed'] / $progress['total'] * 100 ) : 0;
		?>

		<div id="bulk-price-editor-update-in-progress"
			 class="bulk-price-editor-update-in-progress"
			 data-get-progress-url="<?php echo esc_attr( SchedulePriceUpdatesAction::getProgressURL() ); ?>">
			<div class="bulk-price-editor-update-in-progress__inner">
				<div class="bulk-price-editor-update-in-progress__title">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">
						<circle fill="#333333" stroke="#333333" stroke-width="15" r="15" cx="40" cy="100">
							<animate attributeName="opacity" calcMode="spline" dur="2" values="1;0;1;"
									 keySplines=".5 0 .5 1;.5 0 .5 1" repeatCount="indefinite" begin="-.4"></animate>
						</circle>
						<circle fill="#333333" stroke="#333333" stroke-width="15" r="15" cx="100" cy="100">
							<animate attributeName="opacity" calcMode="spline" dur="2" values="1;0;1;"
									 keySplines=".5 0 .5 1;.5 0 .5 1" repeatCount="indefinite" begin="-.2"></animate>
						</circle>
						<circle fill="#333333" stroke="#333333" stroke-width="15" r="15" cx="160" cy="100">
							<animate attributeName="opacity" calcMode="spline" dur="2" values="1;0;1;"
									 keySplines=".5 0 .5 1;.5 0 .5 1" repeatCount="indefinite" begin="0"></animate>
						</circle>
					</svg>
					<?php esc_html_e( 'Prices are being updated', 'bulk-price-editor' ); ?>
				</div>

				<div class="bulk-price-editor-update-in-progress__progress">
					<div class="bulk-price-editor-update-in-progress__info">
						<div>
							<span class="bulk-price-editor-update-in-progress__current">
								<?php echo esc_html( $progress['processed'] ); ?>
							</span>
							<span class="bulk-price-editor-update-in-progress__separator">/</span>
							<span class="bulk-price-editor-update-in-progress__total">
								<?php echo esc_html( $progress['total'] ); ?>
							</span>
						</div>
						<div>
							
							<span class="bulk-price-editor-update-in-progress__percentage">
								<?php echo esc_html( $percentage ); ?>%
							</span>

						</div>
					</div>
					<div class="bulk-price-editor-progress-bar">
						<div class="bulk-price-editor-progress-bar__inner"
							 style="width: <?php echo esc_attr( $percentage ); ?>%"></div>
					</div>
				</div>

				<div class="bulk-price-editor-update-in-progress__description">
					<?php
						esc_html_e( 'This may take a while depending on the number of products being updated.',
							'bulk-price-editor' );
					?>
					<br>
					<?php
						esc_html_e( 'The page will refresh automatically when the process is complete.',
							'bulk-price-editor' );
					?>
				</div>
			</div>

			<div class="bulk-price-editor-update-in-progress__footer">
				
				<?php
					$viewQueueURL = add_query_arg( array(
						'page'   => 'wc-status',
						'tab'    => 'action-scheduler',
						'status' => 'pending',
						'group'  => 'bulk-price-editor__prices',
					), admin_url( 'admin.php' ) );
					
					$stopUpdatingURL = add_query_arg( array(
						'action'   => SchedulePriceUpdatesAction::STOP_UPDATING__ACTION_NAME,
						'_wpnonce' => wp_create_nonce( SchedulePriceUpdatesAction::STOP_UPDATING__ACTION_NAME ),
					), admin_url( 'admin-post.php' ) );
					
					$stopUpdatingConfirmationMessage = esc_attr( __( 'Are you sure you want to stop updating prices?',
						'bulk-price-editor' ) );
				?>

				<div class="bulk-price-editor-update-in-progress__actions">
					<div class="bulk-price-editor-buttons">
						<a href="<?php echo esc_attr( $viewQueueURL ); ?>"
						   target="_blank"
						   class="bulk-price-editor-button bulk-price-editor-button--secondary"
						>
							<?php esc_html_e( 'View queue', 'bulk-price-editor' ); ?>
						</a>

						<a href="<?php echo esc_attr( $stopUpdatingURL ); ?>"
						   onclick="return confirm('<?php echo esc_attr( $stopUpdatingConfirmationMessage ); ?>')"
						   class="bulk-price-editor-button bulk-price-editor-button--primary">
							<?php esc_html_e( 'Stop updating', 'bulk-price-editor' ); ?>
						</a>
					</div>

				</div>
			</div>

		</div>
		
		
		<?php
	}
	
	public static function getPageURL(): string {
		return add_query_arg( array(
			'page' => self::PAGE_SLUG,
		), admin_url( 'admin.php' ) );
	}

	public static function getHistoryURL(): string {
		return add_query_arg( array(
			'page' => self::PAGE_SLUG,
			'tab'  => 'history',
		), admin_url( 'admin.php' ) );
	}

	public static function getScheduledURL(): string {
		return add_query_arg( array(
			'page' => self::PAGE_SLUG,
			'tab'  => 'scheduled',
		), admin_url( 'admin.php' ) );
	}

	public static function getSettingsURL(): string {
		return add_query_arg( array(
			'page' => self::PAGE_SLUG,
			'tab'  => 'settings',
		), admin_url( 'admin.php' ) );
	}
	
	public static function isCurrentPage(): bool {
		return isset( $_GET['page'] ) && self::PAGE_SLUG === $_GET['page'];
	}
}