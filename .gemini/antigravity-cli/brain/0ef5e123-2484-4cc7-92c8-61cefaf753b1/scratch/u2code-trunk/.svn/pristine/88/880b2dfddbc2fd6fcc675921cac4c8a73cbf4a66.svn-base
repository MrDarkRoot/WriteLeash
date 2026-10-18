<?php namespace BulkPriceEditor\PriceEditorPage\Widgets;

use BulkPriceEditor\Core\ServiceContainerTrait;

abstract class Widget {
	
	use ServiceContainerTrait;
	
	abstract protected function renderContent();
	
	abstract protected function renderFooter();
	
	abstract public function getTitle(): string;
	
	abstract public function getDescription(): string;
	
	abstract public function getStepNumber(): string;
	
	public function getSize(): int {
		return 50;
	}
	
	public function display() {
		?>
		<div class="bulk-price-editor-widget">
			<div class="bulk-price-editor-widget__header">

				<div class="bulk-price-editor-widget-title">
					<div class="bulk-price-editor-widget-step">
						<?php echo esc_html( $this->getStepNumber() ); ?>
					</div>

					<div class="bulk-price-editor-widget-title__name">
						<?php echo esc_html( $this->getTitle() ); ?>
					</div>

					<div class="bulk-price-editor-widget-description">
						<?php echo esc_html( $this->getDescription() ); ?>
					</div>
				</div>
				
				<?php $this->renderActions(); ?>

			</div>
			<div class="bulk-price-editor-widget__content">
				<?php $this->renderContent(); ?>
			</div>

			<div class="bulk-price-editor-widget__footer"><?php $this->renderFooter(); ?></div>
		</div>
		<?php
	}
	
	public function renderActions() {}
	
	public function renderHint( $hint ) {
		?>
		<div class="bulk-price-editor-widget-hint">
			<span class="dashicons dashicons-editor-help"></span>
			<?php echo esc_html( $hint ); ?>
		</div>
		<?php
	}
	
	public function renderSelect2( $args = array() ) {
		
		$args = wp_parse_args( $args, array(
			'id'                   => '',
			'search_action'        => '',
			'value'                => '',
			'options'              => array(),
			'placeholder'          => '',
			'multiple'             => true,
			'width'                => '100%',
			'description'          => '',
			'desc_tip'             => true,
			'minimum_input_length' => 1,
			'css_class'            => 'wc-product-search',
			'custom_attributes'    => array(),
		) );
		
		$args = $this->parseArguments( $args );
		
		?>
		<div class="bulk-price-editor-widget-row">

			<div class="bulk-price-editor-widget-row__name">
				<?php echo esc_html( $args['label'] ); ?>
			</div>

			<div class="bulk-price-editor-widget-row__value">
				<select class="<?php echo esc_attr( $args['css_class'] ); ?>"<?php echo $args['multiple'] ? ' multiple' : ''; ?>
						id="<?php echo esc_attr( $args['id'] ); ?>"
					<?php echo wp_kses_post( $args['custom_attributes_string'] ); ?>
						style="width: <?php echo esc_attr( $args['width'] ); ?>"
						name="<?php echo esc_attr( $args['multiple'] ? $args['id'] . '[]' : $args['id'] ); ?>"
						data-placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
						data-action="<?php echo esc_attr( $args['search_action'] ); ?>"
						data-minimum_input_length="<?php echo esc_attr( $args['minimum_input_length'] ); ?>">
					<?php if ( $args['options'] ) : ?>

						<?php foreach ( $args['options'] as $optionId => $label ) : ?>
							<option
								<?php selected( in_array( $optionId, $args['value'] ) ); ?>
									value="<?php echo esc_attr( $optionId ); ?>">
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>

					<?php else : ?>

						<?php foreach ( $args['value'] as $optionId => $label ) : ?>
							<option selected
									value="<?php echo esc_attr( $optionId ); ?>">
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
				
				<?php if ( $args['description'] ) : ?>
					<?php if ( $args['desc_tip'] ) : ?>
						<?php echo wp_kses_post( wc_help_tip( $args['description'] ) ); ?>
					<?php else : ?>
						<p class="description" style="margin:0">
							<?php echo esc_html( $args['description'] ); ?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>

		</div>
		<?php
	}
	
	public function renderSelect( $args = array() ) {

		$args = wp_parse_args( $args, array(
			'id'                => '',
			'value'             => array(),
			'options'           => array(),
			'multiple'          => false,
			'description'       => '',
			'desc_tip'          => true,
			'css_class'         => '',
			'custom_attributes' => array(),
			'only_for_premium'  => false,
		) );

		$args['value'] = (array) $args['value'];

		$args = $this->parseArguments( $args );

		$isBlocked = $args['only_for_premium'] && ! bpefw_fs()->can_use_premium_code();

		?>
		<div class="bulk-price-editor-widget-row <?php echo esc_attr( $isBlocked ? 'bulk-price-editor-widget-row--blocked' : '' ); ?>">

			<div class="bulk-price-editor-widget-row__name">
				<?php echo esc_html( $args['label'] ); ?>
			</div>

			<div class="bulk-price-editor-widget-row__value">
				<select class="<?php echo esc_attr( $args['css_class'] ); ?>"<?php echo $args['multiple'] ? ' multiple' : ''; ?>
						id="<?php echo esc_attr( $args['id'] ); ?>"
					<?php echo wp_kses_post( $args['custom_attributes_string'] ); ?>
						name="<?php echo esc_attr( $args['multiple'] ? $args['id'] . '[]' : $args['id'] ); ?>">
					<?php if ( $args['options'] ) : ?>

						<?php foreach ( $args['options'] as $optionId => $label ) : ?>
							<option
								<?php selected( in_array( $optionId, $args['value'] ) ); ?>
									value="<?php echo esc_attr( $optionId ); ?>">
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>

					<?php else : ?>

						<?php foreach ( $args['value'] as $optionId => $label ) : ?>
							<option selected
									value="<?php echo esc_attr( $optionId ); ?>">
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
				
				<?php if ( $args['description'] ) : ?>
					<?php if ( $args['desc_tip'] ) : ?>
						<?php echo wp_kses_post( wc_help_tip( $args['description'] ) ); ?>
					<?php else : ?>
						<p class="description" style="margin:0">
							<?php echo esc_html( $args['description'] ); ?>
						</p>
					<?php endif; ?>
				<?php endif; ?>

				<?php if ( $isBlocked ) : ?>
					<p class="description">
						<?php esc_html_e( 'This feature is available in the premium version.',
							'bulk-price-editor-for-woocommerce' ); ?>
						<a href="<?php echo esc_attr( bpefw_fs_upgrade_url() ) ?>" target="_blank">
							<?php esc_html_e( 'Upgrade your plan', 'bulk-price-editor-for-woocommerce' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>

		</div>
		<?php
	}
	
	public function renderTextInput( $args = array() ) {
		
		$args = wp_parse_args( $args, array(
			'id'                   => '',
			'value'                => '',
			'type'                 => 'text',
			'placeholder'          => '',
			'width'                => '100%',
			'description'          => '',
			'desc_tip'             => false,
			'minimum_input_length' => 1,
			'css_class'            => '',
			'custom_attributes'    => array(),
			'only_for_premium'     => false,
		) );
		
		$args = $this->parseArguments( $args );
		
		$isBlocked = $args['only_for_premium'] && ! bpefw_fs()->can_use_premium_code();
		
		?>
		<div class="bulk-price-editor-widget-row <?php echo esc_attr( $isBlocked ? 'bulk-price-editor-widget-row--blocked' : '' ); ?>">

			<div class="bulk-price-editor-widget-row__name">
				<?php echo esc_html( $args['label'] ); ?>
			</div>

			<div class="bulk-price-editor-widget-row__value">
				
				<?php if ( 'number' === $args['type'] ) : ?>

					<input type="number"
						<?php echo wp_kses_post( $args['custom_attributes_string'] ); ?>
						   id="<?php echo esc_attr( $args['id'] ); ?>"
						   name="<?php echo esc_attr( $args['id'] ); ?>"
						   class="<?php echo esc_attr( $args['css_class'] ); ?>"
						   value="<?php echo esc_attr( $args['value'] ); ?>"
						   placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>">
				
				<?php else : ?>

					<input type="<?php echo esc_attr( $args['type'] ? $args['type'] : 'text' ); ?>"
						<?php echo wp_kses_post( $args['custom_attributes_string'] ); ?>
						   id="<?php echo esc_attr( $args['id'] ); ?>"
						   name="<?php echo esc_attr( $args['id'] ); ?>"
						   class="<?php echo esc_attr( $args['css_class'] ); ?>"
						   value="<?php echo esc_attr( $args['value'] ); ?>"
						   placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>">

				<?php endif; ?>
				
				<?php if ( $args['description'] ) : ?>
					<?php if ( $args['desc_tip'] ) : ?>
						<?php echo wp_kses_post( wc_help_tip( $args['description'] ) ); ?>
					<?php else : ?>
						<p class="description">
							<?php echo esc_html( $args['description'] ); ?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
				
				<?php if ( $isBlocked ) : ?>
					<p class="description">
						<?php esc_html_e( 'This feature is available in the premium version.',
							'bulk-price-editor-for-woocommerce' ); ?>
						<a href="<?php echo esc_attr( bpefw_fs_upgrade_url() ) ?>" target="_blank">
							<?php esc_html_e( 'Upgrade your plan', 'bulk-price-editor-for-woocommerce' ); ?>
						</a>

					</p>
				
				<?php endif; ?>
			</div>

		</div>
		<?php
	}
	
	public function renderPremiumNotice() {}
	
	public function renderRadioButtons( $args = array() ) {
		
		$args = wp_parse_args( $args, array(
			'id'                => '',
			'value'             => array(),
			'options'           => array(),
			'multiple'          => false,
			'description'       => '',
			'desc_tip'          => true,
			'css_class'         => '',
			'custom_attributes' => array(),
		) );
		
		$args['value'] = (array) $args['value'];
		
		$args = $this->parseArguments( $args );
		
		?>
		<div class="bulk-price-editor-widget-row">

			<div class="bulk-price-editor-widget-row__name">
				<?php echo esc_html( $args['label'] ); ?>
			</div>

			<div class="bulk-price-editor-widget-row__value">
				
				<?php foreach ( $args['options'] as $optionId => $label ) : ?>
					<div style="margin-bottom: 10px;">
						<label>
							<input type="radio"
								<?php echo wp_kses_post( $args['custom_attributes_string'] ); ?>
								   id="<?php echo esc_attr( $args['id'] . '--' . $optionId ); ?>"
								   name="<?php echo esc_attr( $args['id'] ); ?>"
								<?php checked( in_array( $optionId, $args['value'] ) ); ?>
								   value="<?php echo esc_attr( $optionId ); ?>">
							<?php echo esc_html( $label ); ?>
						</label>
					</div>

				<?php endforeach; ?>
				
				
				<?php if ( $args['description'] ) : ?>
					<?php if ( $args['desc_tip'] ) : ?>
						<?php echo wp_kses_post( wc_help_tip( $args['description'] ) ); ?>
					<?php else : ?>
						<p class="description" style="margin:0">
							<?php echo esc_html( $args['description'] ); ?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>

		</div>
		<?php
	}
	
	public function renderCheckboxGroup( $args ) {
		
		$args = wp_parse_args( $args, array(
			'id'                => '',
			'value'             => array(),
			'options'           => array(),
			'multiple'          => false,
			'description'       => '',
			'desc_tip'          => true,
			'css_class'         => '',
			'custom_attributes' => array(),
		) );
		
		$args['value'] = (array) $args['value'];
		
		$args = $this->parseArguments( $args );
		
		?>
		<div class="bulk-price-editor-widget-row">

			<div class="bulk-price-editor-widget-row__name">
				<?php echo esc_html( $args['label'] ); ?>
			</div>

			<div class="bulk-price-editor-widget-row__value">
				
				<?php foreach ( $args['options'] as $optionId => $label ) : ?>
					<div style="margin-bottom: 10px;">
						<label>
							<input type="checkbox"
								<?php echo wp_kses_post( $args['custom_attributes_string'] ); ?>
								   id="<?php echo esc_attr( $args['id'] . '--' . $optionId ); ?>"
								   name="<?php echo esc_attr( $args['id'] ); ?>[]"
								<?php checked( in_array( $optionId, $args['value'] ) ); ?>
								   value="<?php echo esc_attr( $optionId ); ?>">
							<?php echo esc_html( $label ); ?>
						</label>
					</div>
				<?php endforeach; ?>
				
				<?php if ( $args['description'] ) : ?>
					<?php if ( $args['desc_tip'] ) : ?>
						<?php echo wp_kses_post( wc_help_tip( $args['description'] ) ); ?>
					<?php else : ?>
						<p class="description" style="margin:0">
							<?php echo esc_html( $args['description'] ); ?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>

		</div>
		<?php
	}
	
	public function renderCheckbox( $args ) {
		
		$args = wp_parse_args( $args, array(
			'id'                => '',
			'label'             => '',
			'checked'           => false,
			'description'       => '',
			'desc_tip'          => true,
			'css_class'         => '',
			'custom_attributes' => array(),
		) );
		
		$args['checked'] = (bool) $args['checked'];
		
		$args = $this->parseArguments( $args );
		
		?>
		<div class="bulk-price-editor-widget-row">

			<div class="bulk-price-editor-widget-row__name">
				<?php echo esc_html( $args['label'] ); ?>
			</div>

			<div class="bulk-price-editor-widget-row__value">


				<div style="margin-bottom: 10px;">
					<label>
						<input type="checkbox"
							<?php echo wp_kses_post( $args['custom_attributes_string'] ); ?>
							   id="<?php echo esc_attr( $args['id'] ); ?>"
							   name="<?php echo esc_attr( $args['id'] ); ?>"
							<?php checked( $args['checked'] ); ?>
							   value="<?php echo esc_attr( $args['id'] ); ?>">
					</label>
				</div>
				
				<?php if ( $args['description'] ) : ?>
					<?php if ( $args['desc_tip'] ) : ?>
						<?php echo wp_kses_post( wc_help_tip( $args['description'] ) ); ?>
					<?php else : ?>
						<p class="description" style="margin:0">
							<?php echo esc_html( $args['description'] ); ?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>

		</div>
		<?php
	}
	
	protected function parseArguments( array $args ): array {
		
		if ( ! isset( $args['custom_attributes'] ) ) {
			$args['custom_attributes'] = array();
		}
		
		$args['custom_attributes_string'] = implode( ' ', array_map( function ( $key, $value ) {
			return esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
		}, array_keys( $args['custom_attributes'] ), $args['custom_attributes'] ) );
		
		return $args;
	}
}
