<?php

	namespace BulkPriceEditor\Integrations\TieredPricing;

	use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
	use BulkPriceEditor\PriceModifiers\Modifiers\PriceModifier;
	use TierPricingTable\PriceManager;
	use TierPricingTable\PricingRule;
	use TierPricingTable\TierPricingTablePlugin;
	use WC_Product;

	class TieredPricingModifier extends PriceModifier {

		const TYPE = 'tiered_pricing';

		// Tiered pricing rules are meta, not prices — the shared rounding and
		// sale-dates options do not apply here.
		protected array $supports = array(
			'advanced_options' => false,
		);

		public function getType(): string {
			return self::TYPE;
		}

		public function getName(): string {
			return __( 'Tiered Pricing', 'bulk-price-editor' );
		}

		public function getUpdatedSalePrice( WC_Product $product ) {
			return null;
		}

		public function getUpdatedRegularPrice( WC_Product $product ) {
			return null;
		}

		public function getUpdatedTieredPricing( WC_Product $product ): ?PricingRule {
			$args = $this->getRawArgs();

			$modificationType = isset( $args['bpe_tiered_pricing_modification_type'] ) ? sanitize_text_field( $args['bpe_tiered_pricing_modification_type'] ) : null;
			$modificationType = in_array( $modificationType,
					[ 'set_new', 'update_percentage' ] ) ? $modificationType : 'set_new';

			if ( $modificationType === 'set_new' ) {

				$pricingRule = new PricingRule( $product->get_id() );

				$tieredPricingType = isset( $args['bpe_tiered_pricing_type'] ) ? sanitize_text_field( $args['bpe_tiered_pricing_type'] ) : null;
				$tieredPricingType = in_array( $tieredPricingType,
						array( 'fixed', 'percentage' ) ) ? $tieredPricingType : null;

				if ( ! $tieredPricingType ) {
					return null;
				}

				if ( 'fixed' === $tieredPricingType ) {
					$fixedQuantities = isset( $args['bpe_tiered_pricing_fixed_quantities'] ) ? array_map( 'intval',
							(array) $args['bpe_tiered_pricing_fixed_quantities'] ) : array();

					$fixedPrices = isset( $args['bpe_tiered_pricing_fixed_prices'] ) ? array_map( 'wc_format_decimal',
							(array) $args['bpe_tiered_pricing_fixed_prices'] ) : array();

					$rules = array();

					foreach ( $fixedQuantities as $index => $quantity ) {
						if ( ! isset( $fixedPrices[ $index ] ) ) {
							continue;
						}

						$price = $fixedPrices[ $index ];

						if ( ! is_numeric( $price ) ) {
							continue;
						}

						$rules[ (int) $quantity ] = (float) $price;
					}
				} else {
					$percentageQuantities = isset( $args['bpe_tiered_pricing_percentage_quantities'] ) ? array_map( 'intval',
							(array) $args['bpe_tiered_pricing_percentage_quantities'] ) : array();

					$percentageDiscounts = isset( $args['bpe_tiered_pricing_percentage_discounts'] ) ? array_map( 'wc_format_decimal',
							(array) $args['bpe_tiered_pricing_percentage_discounts'] ) : array();

					$rules = array();

					foreach ( $percentageQuantities as $index => $quantity ) {
						if ( ! isset( $percentageDiscounts[ $index ] ) ) {
							continue;
						}

						$discount = $percentageDiscounts[ $index ];

						if ( ! is_numeric( $discount ) ) {
							continue;
						}

						$rules[ (int) $quantity ] = (float) $discount;
					}
				}
			} elseif ( $modificationType === 'update_percentage' ) {

				$pricingRule        = new PricingRule( $product->get_id() );
				$tieredPricingType  = PriceManager::getPricingType( $product->get_id() );
				$tieredPricingRules = PriceManager::getPriceRules( $product->get_id(), $tieredPricingType );
				$pricingRule->setRules( $tieredPricingRules );
				$pricingRule->setType( $tieredPricingType );

				if ( empty( $pricingRule->getRules() ) ) {
					return $pricingRule;
				}

				$updateByPercentage = isset( $args['bpe_tiered_pricing_update_percentage'] ) ? (float) $args['bpe_tiered_pricing_update_percentage'] : 0;

				$updateByPercentage = max( - 100, $updateByPercentage );

				if ( $updateByPercentage == 0 ) {
					return null;
				}

				$rules = array();

				if ( $pricingRule->isPercentage() ) {
					foreach ( $pricingRule->getRules() as $quantity => $percentageDiscount ) {
						$rules[ $quantity ] = round( min( 100,
								$percentageDiscount + ( $percentageDiscount / 100 * $updateByPercentage ) ), 2 );
					}

				} else {
					foreach ( $pricingRule->getRules() as $quantity => $fixedPrice ) {
						$rules[ $quantity ] = round( (float) ( $fixedPrice + ( $fixedPrice / 100 * $updateByPercentage ) ),
								2 );
					}
				}
			}

			$rules = array_filter( $rules );

			$rules = array_filter( $rules, function ( $key ) {
				return $key >= 2;
			}, ARRAY_FILTER_USE_KEY );

			$pricingRule->setRules( $rules );
			$pricingRule->setType( $tieredPricingType );

			return $pricingRule;
		}

		public function renderFields( Widget $widget ) {
			?>
			<div class="bpe-tiered-pricing-mode-selector" style="margin-bottom: 15px;">
				<label style="display: block; margin-bottom: 8px; font-weight: 600;">
					<?php esc_html_e( 'Modification Mode', 'bulk-price-editor' ); ?>
				</label>
				<div style="display: inline-flex; gap: 8px; background: #f0f0f1; padding: 4px; border-radius: 4px;">
					<button type="button" class="bpe-mode-button bpe-mode-button--active" data-mode="set_new"
							style="padding: 6px 12px; border: none; background: #fff; border-radius: 3px; cursor: pointer; font-size: 13px; transition: all 0.2s;">
						<?php esc_html_e( 'Set New Tiers', 'bulk-price-editor' ); ?>
					</button>
					<button type="button" class="bpe-mode-button" data-mode="update_percentage"
							style="padding: 6px 12px; border: none; background: transparent; border-radius: 3px; cursor: pointer; font-size: 13px; transition: all 0.2s;">
						<?php esc_html_e( 'Update Existing by %', 'bulk-price-editor' ); ?>
					</button>
				</div>
				<input type="hidden" name="bpe_tiered_pricing_modification_type" value="set_new"
					   data-price-modificator="yes">
			</div>

			<div class="bpe-tiered-pricing-update-mode" style="display: none;">
				<?php
					$widget->renderHint( __( 'This will update all existing tiered prices for selected products by the specified percentage. Positive values increase prices, negative values decrease them.',
							'bulk-price-editor' ) );
					$widget->renderTextInput( array(
							'id'                => 'bpe_tiered_pricing_update_percentage',
							'label'             => __( 'Percentage Change', 'bulk-price-editor' ),
							'type'              => 'number',
							'placeholder'       => __( 'e.g., 10 for +10% or -10 for -10%', 'bulk-price-editor' ),
							'custom_attributes' => array(
									'step'                   => 'any',
									'data-price-modificator' => 'yes',
									'min'                    => '-100',
							),
					) );
				?>
			</div>

			<div class="bpe-tiered-pricing-set-mode">
				<?php
					$widget->renderHint( __( 'Leave empty to remove tiered pricing for selected products.',
							'bulk-price-editor' ) );
					$this->renderTieredPricingForm();
				?>
			</div>

			<script>
				jQuery(document).ready(function ($) {
					$('.bpe-mode-button').on('click', function (e) {
						e.preventDefault();
						var mode = $(this).data('mode');
						var $selector = $(this).closest('.bpe-tiered-pricing-mode-selector');

						// Update hidden input
						$selector.find('input[name="bpe_tiered_pricing_modification_type"]').val(mode);

						// Update button styles
						$('.bpe-mode-button').removeClass('bpe-mode-button--active').css({
							'background': 'transparent',
							'font-weight': 'normal'
						});
						$(this).addClass('bpe-mode-button--active').css({
							'background': '#fff',
							'font-weight': '600'
						});

						// Toggle content sections
						if (mode === 'set_new') {
							$('.bpe-tiered-pricing-set-mode').show();
							$('.bpe-tiered-pricing-update-mode').hide();
						} else {
							$('.bpe-tiered-pricing-set-mode').hide();
							$('.bpe-tiered-pricing-update-mode').show();
						}

						// Trigger change event to update preview table
						$selector.find('input[name="bpe_tiered_pricing_modification_type"]').trigger('change');
					});
				});
			</script>
			<?php
		}

		protected function renderTieredPricingForm() {
			?>
			<script>
				jQuery(function () {

					jQuery.each(jQuery('.bpe-tiered-pricing-form'), function (i, el) {
						const $form = jQuery(el);

						$form.find('.bpe-tiered-pricing-form__type select').on('change', function () {
							const type = jQuery(this).val();

							if (type === 'fixed') {
								$form.find('.bpe-tiered-pricing-form__percentage').hide();
								$form.find('.bpe-tiered-pricing-form__fixed').show();
							} else {
								$form.find('.bpe-tiered-pricing-form__fixed').hide();
								$form.find('.bpe-tiered-pricing-form__percentage').show();
							}
						}).trigger('change');

						$form.find('.bpe-tiered-pricing-form__button--add-new button').on('click', function () {

							const $rulesContainer = jQuery(this).closest('.bpe-tiered-pricing-form__form');
							const $row = $rulesContainer.find('.bpe-tiered-pricing-form__rule').first().clone();

							$row.find('input').val('');
							$rulesContainer.find('.bpe-tiered-pricing-form__rules').append($row);
						});

						$form.on('click', '.bpe-tiered-pricing-form__button--remove-rule button', function () {

							const $rulesContainer = jQuery(this).closest('.bpe-tiered-pricing-form__form');
							const $rule = jQuery(this).closest('.bpe-tiered-pricing-form__rule');

							if ($rule.find('input').val() !== '') {
								setTimeout(function () {
									$rule.find('[data-price-modificator]').first().trigger('change');
								}, 0);
							}

							if ($rulesContainer.find('.bpe-tiered-pricing-form__rule').length === 1) {
								$rule.find('input').val('');
							} else {
								$rule.remove();
							}
						});
					});

				});
			</script>

			<div class="bpe-tiered-pricing-form">
				<div class="bpe-tiered-pricing-form__type">
					<div class="bulk-price-editor-widget-row">

						<div class="bulk-price-editor-widget-row__name">
							<?php esc_html_e( 'Tiered Pricing Type', 'bulk-price-editor' ); ?>
						</div>

						<div class="bulk-price-editor-widget-row__value">
							<select id="bpe_tiered_pricing_type" data-price-modificator="yes"
									name="bpe_tiered_pricing_type">
								<option value="fixed">
									<?php esc_html_e( 'Fixed', 'bulk-price-editor' ); ?>
								</option>
								<option value="percentage">
									<?php esc_html_e( 'Percentage', 'bulk-price-editor' ); ?>
								</option>
							</select>
						</div>
					</div>
				</div>

				<div class="bpe-tiered-pricing-form__percentage">

					<div class="bulk-price-editor-widget-row">
						<div class="bulk-price-editor-widget-row__name">
							<?php esc_html_e( 'Tiered Pricing', 'bulk-price-editor' ); ?>
						</div>

						<div class="bulk-price-editor-widget-row__value">
							<div class="bpe-tiered-pricing-form__form">
								<div class="bpe-tiered-pricing-form__rules">
									<div class="bpe-tiered-pricing-form__rule">
										<div>
											<input type="number" min="2" data-price-modificator="yes"
												   placeholder="<?php esc_attr_e( 'Quantity', 'bulk-price-editor' ); ?>"
												   name="bpe_tiered_pricing_percentage_quantities[]">
										</div>

										<div>
											<input type="number" min="0" data-price-modificator="yes"
												   placeholder="<?php esc_attr_e( 'Discount', 'bulk-price-editor' ); ?>"
												   name="bpe_tiered_pricing_percentage_discounts[]">
										</div>

										<div class="bpe-tiered-pricing-form__button--remove-rule">
											<button
													class="bulk-price-editor-button bulk-price-editor-button--secondary bulk-price-editor-button--tiny">
												<span class="dashicons dashicons-dismiss"></span>
											</button>
										</div>
									</div>
								</div>
								<div class="bpe-tiered-pricing-form__buttons">
									<div class="bpe-tiered-pricing-form__button--add-new">
										<button
												class="bulk-price-editor-button bulk-price-editor-button--primary bulk-price-editor-button--small">
											<?php esc_html_e( 'Add new tier', 'bulk-price-editor' ); ?>
										</button>
									</div>
								</div>
							</div>

						</div>
					</div>
				</div>

				<div class="bpe-tiered-pricing-form__fixed">

					<div class="bulk-price-editor-widget-row">
						<div class="bulk-price-editor-widget-row__name">
							<?php esc_html_e( 'Tiered Pricing', 'bulk-price-editor' ); ?>
						</div>

						<div class="bulk-price-editor-widget-row__value">
							<div class="bpe-tiered-pricing-form__form">
								<div class="bpe-tiered-pricing-form__rules">
									<div class="bpe-tiered-pricing-form__rule">

										<div>
											<input type="number" min="2" data-price-modificator="yes"
												   placeholder="<?php esc_attr_e( 'Quantity', 'bulk-price-editor' ); ?>"
												   name="bpe_tiered_pricing_fixed_quantities[]">
										</div>

										<div>
											<input type="text" class="wc_input_price" data-price-modificator="yes"
												   placeholder="<?php esc_attr_e( 'Price', 'bulk-price-editor' ); ?>"
												   name="bpe_tiered_pricing_fixed_prices[]">
										</div>

										<div class="bpe-tiered-pricing-form__button--remove-rule">
											<button
													class="bulk-price-editor-button bulk-price-editor-button--secondary bulk-price-editor-button--tiny">
												<span class="dashicons dashicons-dismiss"></span>
											</button>
										</div>
									</div>
								</div>
								<div class="bpe-tiered-pricing-form__buttons ">
									<div class="bpe-tiered-pricing-form__button--add-new">
										<button
												class="bulk-price-editor-button bulk-price-editor-button--primary bulk-price-editor-button--small">
											<?php esc_html_e( 'Add new tier', 'bulk-price-editor' ); ?>
										</button>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<?php
		}

		public function updatePrices( WC_Product $product ): void {

			$pricingRule = $this->getUpdatedTieredPricing( $product );

			if ( is_null( $pricingRule ) ) {
				return;
			}

			$product->update_meta_data( '_tiered_price_rules_type', $pricingRule->getType() );

			if ( $pricingRule->isPercentage() ) {
				$product->update_meta_data( '_percentage_price_rules', $pricingRule->getRules() );
			} else {
				$product->update_meta_data( '_fixed_price_rules', $pricingRule->getRules() );
			}

			$product->save();
		}
	}