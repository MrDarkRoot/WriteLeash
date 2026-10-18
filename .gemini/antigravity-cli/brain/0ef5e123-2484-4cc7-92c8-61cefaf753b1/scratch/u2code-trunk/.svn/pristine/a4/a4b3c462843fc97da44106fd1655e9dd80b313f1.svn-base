/**
 * "Price history" links on the product edit screen open a modal with the
 * product's (or variation's) recorded price changes, loaded over AJAX.
 *
 * The link is rendered server-side as its own row; here it is moved inline
 * next to WooCommerce's "Schedule" link under the sale price so it reads as
 * part of the native pricing fields. Variation panels are rendered by
 * WooCommerce on demand, so both the relocation and the click handler are
 * (re)applied after they load.
 */
(function ($) {
	'use strict';

	var settings = window.bpePriceHistory || {};
	var $modal, $title, $body, lastFocus;

	function relocateLinks() {
		$('.bpe-price-history-field').each(function () {
			var $field = $(this);
			var $link = $field.find('.bpe-price-history-link');

			// The pricing container: the options group (simple products) or the variation panel.
			var $scope = $field.closest('.woocommerce_variation');

			if (!$scope.length) {
				$scope = $field.closest('.options_group');
			}

			var $schedule = $scope.find('a.sale_schedule').first();

			if (!$schedule.length) {
				return; // Unknown markup — leave the fallback row in place.
			}

			// After "Cancel schedule" when present, so the order stays Schedule · Price history.
			var $anchor = $schedule.siblings('a.cancel_sale_schedule').first();

			if (!$anchor.length) {
				$anchor = $schedule;
			}

			$anchor.after($('<span class="bpe-price-history-sep" aria-hidden="true">·</span>'), $link.addClass('bpe-price-history-link--inline'));
			$field.remove();
		});

		updateSeparators();
	}

	/**
	 * WooCommerce hides "Schedule" once a sale schedule is set (and shows
	 * "Cancel schedule" — in simple products in a different row), so the
	 * separator only shows while one of those links is visible beside it.
	 */
	function updateSeparators() {
		$('.bpe-price-history-sep').each(function () {
			var $links = $(this).parent().find('a.sale_schedule, a.cancel_sale_schedule').filter(':visible');

			$(this).toggle($links.length > 0);
		});
	}

	function open() {
		$modal.prop('hidden', false);
		$modal.find('.bpe-price-history-modal__close').trigger('focus');
	}

	function close() {
		$modal.prop('hidden', true);

		if (lastFocus) {
			$(lastFocus).trigger('focus');
		}
	}

	function load(productId) {
		$title.text(settings.i18n ? settings.i18n.loading : 'Loading…');
		$body.html('<p class="bpe-price-history__muted">' + (settings.i18n ? settings.i18n.loading : 'Loading…') + '</p>');

		$.get(settings.ajaxUrl, {
			action: settings.action,
			nonce: settings.nonce,
			product_id: productId
		}).done(function (response) {
			if (response && response.success) {
				$title.text(response.data.title);
				$body.html(response.data.html);
			} else {
				$body.html('<p class="bpe-price-history__muted">' + (settings.i18n ? settings.i18n.error : 'Error') + '</p>');
			}
		}).fail(function () {
			$body.html('<p class="bpe-price-history__muted">' + (settings.i18n ? settings.i18n.error : 'Error') + '</p>');
		});
	}

	$(function () {
		$modal = $('#bpe-price-history-modal');

		if (!$modal.length) {
			return;
		}

		$title = $modal.find('#bpe-price-history-title');
		$body = $modal.find('.bpe-price-history-modal__body');

		relocateLinks();

		// WooCommerce loads variation panels over AJAX.
		$('#variable_product_options').on('woocommerce_variations_loaded woocommerce_variations_added', relocateLinks);

		// Re-evaluate after WooCommerce toggles its schedule links.
		$(document).on('click', 'a.sale_schedule, a.cancel_sale_schedule', function () {
			setTimeout(updateSeparators, 0);
		});

		$(document).on('click', '.bpe-price-history-link', function (e) {
			e.preventDefault();
			lastFocus = this;
			open();
			load($(this).data('product-id'));
		});

		$modal.on('click', '.bpe-price-history-modal__overlay, .bpe-price-history-modal__close', close);

		$(document).on('keydown', function (e) {
			if (e.key === 'Escape' && !$modal.prop('hidden')) {
				close();
			}
		});
	});
})(jQuery);
