<?php namespace BulkPriceEditor\ProductFilters\Templates;

class Template {
	
	protected string $slug;
	protected string $name;
	protected array $filter;
	protected array $data;
	
	public function __construct( string $slug, string $name, array $filters, array $data = array() ) {
		$this->slug   = $slug;
		$this->name   = $name;
		$this->filter = $filters;
		$this->data   = $data;
	}
	
	public function getSlug(): string {
		return $this->slug;
	}
	
	public function setSlug( string $slug ): void {
		$this->slug = $slug;
	}
	
	public function getName(): string {
		return $this->name;
	}
	
	public function setName( string $name ): void {
		$this->name = $name;
	}
	
	public function getFilters(): array {
		return $this->filter;
	}
	
	public function setFilters( array $filter ): void {
		$this->filter = $filter;
	}
	
	public function getData(): array {
		return $this->data;
	}
	
	public function setData( array $data ): void {
		$this->data = $data;
	}
	
	public function getValueForFilter( $name ) {
		$filters = $this->getFilters();

		return isset( $filters[ $name ] ) ? $filters[ $name ] : null;
	}

	/**
	 * The price modification saved with this template (templates saved with a
	 * modifier are complete reusable jobs), or null for filters-only templates.
	 */
	public function getModifier(): ?array {

		$modifier = $this->data['modifier'] ?? null;

		if ( ! is_array( $modifier ) || empty( $modifier['type'] ) ) {
			return null;
		}

		return array(
			'type' => (string) $modifier['type'],
			'args' => isset( $modifier['args'] ) ? (array) $modifier['args'] : array(),
		);
	}
	
	public function save() {
		if ( ! $this->slug ) {
			return;
		}
		
		TemplateManager::getInstance()->saveTemplate( $this->slug, $this->name, $this->filter, $this->data );
	}
	
	public function delete() {
		if ( ! $this->slug ) {
			return;
		}
		
		TemplateManager::getInstance()->deleteTemplate( $this->slug );
	}
	
	public static function build( array $data ): ?self {
		
		$slug   = isset( $data['slug'] ) ? sanitize_text_field( $data['slug'] ) : null;
		$name   = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : null;
		$filter = isset( $data['filters'] ) ? (array) $data['filters'] : array();
		$data   = isset( $data['data'] ) ? (array) $data['data'] : array();
		
		if ( ! $slug || ! $name ) {
			return null;
		}
		
		return new self( $slug, $name, $filter, $data );
	}
	
	public function getRemoveActionURL(): string {
		return add_query_arg( array(
			'action' => TemplateManager::REMOVE_TEMPLATE_ACTION,
			'slug'   => $this->slug,
			'nonce'  => wp_create_nonce( TemplateManager::REMOVE_TEMPLATE_ACTION ),
		), admin_url( 'admin-post.php' ) );
	}
	
	public function getURL( $withNotification = true ): string {
		return TemplateManager::getTemplateURL( $this->slug, $withNotification );
	}
}
