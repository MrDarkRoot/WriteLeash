<?php namespace BulkPriceEditor\ProductFilters\Templates;

use BulkPriceEditor\PriceEditorPage\Notifications\Notification;
use BulkPriceEditor\PriceEditorPage\Notifications\NotificationManager;
use BulkPriceEditor\PriceEditorPage\PriceEditorPage;
use BulkPriceEditor\PriceModifiers\Dispatcher;
use BulkPriceEditor\Request\Request;

class TemplateManager {
	
	const TEMPLATES_DATA_KEY = 'bulk_price_editor_templates';
	
	const ADD_TEMPLATE_ACTION = 'bulk_price_editor_add_template';
	const REMOVE_TEMPLATE_ACTION = 'bulk_price_editor_remove_template';
	const UPDATE_TEMPLATE_ACTION = 'bulk_price_editor_update_template';
	const SELECT_TEMPLATE_ACTION = 'bulk_price_editor_select_template';
	
	protected static self $instance;
	
	protected function __construct() {
		add_action( 'wp_ajax_' . self::ADD_TEMPLATE_ACTION, array( $this, 'handleAddTemplate' ) );
		add_action( 'wp_ajax_' . self::UPDATE_TEMPLATE_ACTION, array( $this, 'handleUpdateTemplate' ) );
		
		add_action( 'admin_post_' . self::REMOVE_TEMPLATE_ACTION, function () {

			if ( ! current_user_can( \BulkPriceEditor\BulkPriceEditorPlugin::capability() ) ) {
				wp_die( 'Access denied' );
			}

			$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : null;
			
			if ( ! wp_verify_nonce( $nonce, self::REMOVE_TEMPLATE_ACTION ) ) {
				wp_die( 'Access denied' );
			}
			
			$slug = isset( $_REQUEST['slug'] ) ? sanitize_text_field( $_REQUEST['slug'] ) : null;
			
			if ( ! $slug ) {
				wp_die( 'Invalid request' );
			}
			
			$template = $this->getTemplateBySlug( $slug );
			
			if ( ! $template ) {
				wp_die( 'Invalid request' );
			}
			
			$template->delete();

			$notification = new Notification( __( 'Template has been removed successfully', 'bulk-price-editor' ),
				'success', true, true );
			NotificationManager::addNotification( $notification );

			wp_safe_redirect( PriceEditorPage::getPageURL() );
			exit;
		} );
		add_action( 'admin_post_' . self::SELECT_TEMPLATE_ACTION, function () {

			if ( ! current_user_can( \BulkPriceEditor\BulkPriceEditorPlugin::capability() ) ) {
				wp_die( 'Access denied' );
			}

			$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : null;
			
			if ( ! wp_verify_nonce( $nonce, self::SELECT_TEMPLATE_ACTION ) ) {
				wp_die( 'Access denied' );
			}
			
			$slug = isset( $_REQUEST['slug'] ) ? sanitize_text_field( $_REQUEST['slug'] ) : null;
			
			if ( ! $slug ) {
				wp_die( 'Invalid request' );
			}
			
			$template = $this->getTemplateBySlug( $slug );
			
			if ( ! $template ) {
				wp_die( 'Invalid request' );
			}
			
			// translators: %s: template name
			$notification = new Notification( sprintf( __( 'Using `%s` template', 'bulk-price-editor' ),
				$template->getName() ), 'notice', true, true );

			NotificationManager::addNotification( $notification );

			wp_safe_redirect( $template->getURL( false ) );
			exit;
		} );
	}
	
	public static function getInstance(): self {
		
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		
		return self::$instance;
	}
	
	public function handleUpdateTemplate() {

		check_ajax_referer( self::UPDATE_TEMPLATE_ACTION, 'nonce' );

		if ( ! current_user_can( \BulkPriceEditor\BulkPriceEditorPlugin::capability() ) ) {
			wp_send_json_error( array( 'message' => 'Access denied' ) );
		}
		
		$slug    = isset( $_REQUEST['slug'] ) ? sanitize_text_field( $_REQUEST['slug'] ) : null;
		$filters = Request::getFilters( $_REQUEST );
		
		if ( ! $slug || ! $filters ) {
			wp_send_json_error( array( 'message' => 'Invalid template data' ) );
		}
		
		$template = $this->getTemplateBySlug( $slug );
		
		if ( ! $template ) {
			wp_send_json_error( array( 'message' => 'Invalid template data' ) );
		}
		
		$template->setFilters( $filters );

		$data               = $template->getData();
		$data['updated_at'] = time();

		$modifier = $this->getModifierFromRequest( $_REQUEST );

		if ( ! is_null( $modifier ) ) {
			$data['modifier'] = $modifier;
		}

		$template->setData( $data );
		$template->save();

		wp_send_json_success( array(
			// translators: %s: template name
			'message'  => sprintf( __( 'template %s updated successfully.', 'bulk-price-editor' ), $template->getName() ),
			'redirect' => add_query_arg( array( 'page' => 'bulk-price-editor', 'template' => $slug ),
				admin_url( 'admin.php' ) ),
		) );
	}

	/**
	 * The selected price modification from the request, when the user chose to
	 * save it with the template (making the template a complete reusable job).
	 */
	protected function getModifierFromRequest( array $request ): ?array {

		$modifierData = Request::getPriceModifierData( $request );

		$type = isset( $modifierData['type'] ) ? sanitize_text_field( (string) $modifierData['type'] ) : '';
		$args = isset( $modifierData['args'] ) ? (array) $modifierData['args'] : array();

		if ( '' === $type || empty( $args ) ) {
			return null;
		}

		// Only known modifiers are stored.
		if ( ! Dispatcher::getInstance()->dispatchModifier( $type, $args ) ) {
			return null;
		}

		return array(
			'type' => $type,
			'args' => map_deep( $args, 'sanitize_text_field' ),
		);
	}
	
	public function handleAddTemplate() {

		check_ajax_referer( self::ADD_TEMPLATE_ACTION, 'nonce' );

		if ( ! current_user_can( \BulkPriceEditor\BulkPriceEditorPlugin::capability() ) ) {
			wp_send_json_error( array( 'message' => 'Access denied' ) );
		}
		
		$name    = isset( $_REQUEST['name'] ) ? sanitize_text_field( $_REQUEST['name'] ) : '';
		$name    = substr( $name, 0, 200 );
		$filters = Request::getFilters( $_REQUEST );
		$data    = isset( $_REQUEST['data'] ) ? array_map( 'sanitize_text_field', (array) $_REQUEST['data'] ) : array();

		if ( ! $name || ! $filters ) {
			wp_send_json_error( array( 'message' => 'Invalid template data' ) );
		}

		$data['created_at'] = time();
		$data['updated_at'] = time();

		$modifier = $this->getModifierFromRequest( $_REQUEST );

		if ( ! is_null( $modifier ) ) {
			$data['modifier'] = $modifier;
		}

		$slug = uniqid();

		$this->saveTemplate( $slug, $name, $filters, $data );

		// translators: %s: template name
		$notification = new Notification( sprintf( __( 'template %s saved successfully.', 'bulk-price-editor' ), $name ) );
		NotificationManager::addNotification( $notification );

		wp_send_json_success( array(
			// translators: %s: template name
			'message'  => sprintf( __( 'template %s saved successfully.', 'bulk-price-editor' ), $name ),
			'redirect' => add_query_arg( array( 'page' => 'bulk-price-editor', 'template' => $slug ),
				admin_url( 'admin.php' ) ),
		) );
	}
	
	public function saveTemplate( string $slug, string $name, array $filters, ?array $data = null ) {
		
		$templates = get_option( self::TEMPLATES_DATA_KEY, array() );
		
		$templates[ $slug ] = array(
			'slug'    => $slug,
			'name'    => $name,
			'filters' => $filters,
			'data'    => $data,
		);
		
		update_option( self::TEMPLATES_DATA_KEY, $templates );
	}
	
	public function deleteTemplate( $slug ) {
		
		$templates = get_option( self::TEMPLATES_DATA_KEY, array() );
		
		unset( $templates[ $slug ] );
		
		update_option( self::TEMPLATES_DATA_KEY, $templates );
	}
	
	public function getTemplateFromURL(): ?Template {
		
		$slug = isset( $_REQUEST['template'] ) ? sanitize_text_field( $_REQUEST['template'] ) : null;
		
		if ( ! $slug ) {
			return null;
		}
		
		return $this->getTemplateBySlug( $slug );
	}
	
	public function getTemplateBySlug( string $slug ): ?Template {
		
		$templates = $this->getTemplates();
		
		return $templates[ $slug ] ?? null;
	}
	
	/**
	 * Get all templates
	 *
	 * @return Template[]
	 */
	public function getTemplates(): array {
		
		$templates = get_option( self::TEMPLATES_DATA_KEY, array() );
		
		return array_filter( array_map( function ( $template ) {
			return Template::build( $template );
		}, $templates ) );
	}
	
	public static function getTemplateURL( string $slug, $withNotification = false ): string {
		
		if ( $withNotification ) {
			return add_query_arg( array(
				'action'   => self::SELECT_TEMPLATE_ACTION,
				'_wpnonce' => wp_create_nonce( self::SELECT_TEMPLATE_ACTION ),
				'slug'     => $slug,
			), admin_url( 'admin-post.php' ) );
		}
		
		return add_query_arg( array(
			'page'     => 'bulk-price-editor',
			'template' => $slug,
		), admin_url( 'admin.php' ) );
	}
}
