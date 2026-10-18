<?php namespace BulkPriceEditor\PriceEditorPage\Notifications;

class NotificationManager {
	
	const NOTIFICATION_OPTION_KEY = 'bulk_price_editor_price_notifications';
	
	protected static ?self $instance;
	protected array $nextRequestNotifications = array();
	
	protected function __construct() {
		add_action( 'shutdown', function () {
			$this->handleFlashNotifications();
		} );
	}
	
	public static function getInstance(): self {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		
		return self::$instance;
	}
	
	/**
	 * Notifications are stored per admin so concurrent users do not see each
	 * other's messages.
	 */
	protected function getOptionKey( ?int $userId = null ): string {

		$userId = is_null( $userId ) ? get_current_user_id() : $userId;

		return self::NOTIFICATION_OPTION_KEY . '_' . $userId;
	}

	protected function _addNotification( Notification $notification ) {
		$notifications = $this->getAllNotifications();

		if ( $notification->isFlash() ) {
			$this->nextRequestNotifications[] = $notification;
		} else {
			$notifications[] = $notification;
			$this->updateNotifications( $notifications );
		}
	}

	public function updateNotifications( array $notifications, ?int $userId = null ) {

		$notifications = array_filter( $notifications, function ( $notification ) {
			return $notification instanceof Notification && $notification->getMessage() !== '';
		} );

		$notifications = array_map( function ( $notification ) {
			return $notification->getAsArray();
		}, $notifications );

		update_option( $this->getOptionKey( $userId ), $notifications );
	}

	/**
	 * Get all notifications
	 *
	 * @return Notification[]
	 */
	public function getAllNotifications( ?int $userId = null ): array {
		$notifications = get_option( $this->getOptionKey( $userId ), array() );

		return array_map( function ( $notification ) {
			return Notification::build( $notification );
		}, (array) $notifications );
	}
	
	protected function handleFlashNotifications() {
		
		$notifications = $this->getAllNotifications();
		
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			// Remove all notifications that are not permanent
			$notifications = array_filter( $notifications, function ( $notification ) {
				return $notification->isPermanent();
			} );
		}
		
		foreach ( $this->nextRequestNotifications as $notification ) {
			$notifications[] = $notification;
		}
		
		$this->updateNotifications( $notifications );
	}
	
	public static function addNotification( Notification $notification ) {
		self::getInstance()->_addNotification( $notification );
	}

	/**
	 * Queue a persistent notification for a specific user — used from
	 * background processing (Action Scheduler runs with no current user) to
	 * notify whoever started the run.
	 */
	public static function addNotificationForUser( int $userId, Notification $notification ) {

		$instance = self::getInstance();

		$notification->setIsFlash( false );

		$notifications   = $instance->getAllNotifications( $userId );
		$notifications[] = $notification;

		$instance->updateNotifications( $notifications, $userId );
	}
}
