/* Read-only progressive enhancement. Planner authority stays on the server. */
(function ($) {
    'use strict';
    var __ = wp.i18n.__, _n = wp.i18n._n, _x = wp.i18n._x, sprintf = wp.i18n.sprintf;
    $(function () {
        var form = $('#writeleash-free-selection-form');
        if (!form.length) { return; }
        var method = $('#writeleash-free-selector');
        function selectionMethod() {
            var kind = method.val();
            $('#writeleash-free-product-picker').prop('hidden', kind !== 'ids');
            $('#writeleash-free-category-picker').prop('hidden', kind !== 'category');
            $('#writeleash-free-advanced-selection').prop('hidden', kind !== 'sku' && kind !== 'manual_ids').prop('open', kind === 'sku' || kind === 'manual_ids');
            $('#writeleash-free-sku').closest('p').prop('hidden', kind !== 'sku');
            $('#writeleash-free-ids').closest('p').prop('hidden', kind !== 'manual_ids');
            $('#writeleash-free-discovery-status').text(kind === 'ids' ? __( 'Search and choose products to add them.', 'writeleash' ) : (kind === 'category' ? ($('#writeleash-free-include-subcategories').prop('checked') ? __( 'Direct members and all nested subcategories will be checked in Preview, including variations. Each target is included once.', 'writeleash' ) : __( 'All direct members of this category will be checked in Preview, including variations. Subcategories are not included.', 'writeleash' )) : ''));
        }
        function amountLabel() {
            var operation = $('#writeleash-free-operation').val();
            var clear = operation === 'CLEAR_SALE';
			$('#writeleash-free-ending').prop('disabled', clear);
            var amount = $('#writeleash-free-amount');
            amount.prop('required', !clear).prop('disabled', clear);
            if (clear) { amount.val(''); }
            amount.closest('p').prop('hidden', clear);
            $('#writeleash-free-amount-help').prop('hidden', clear);
            if (clear || operation === 'SALE_DISCOUNT_PERCENT') {
                $('#writeleash-free-price-field').val('sale_price');
            }
            $('label[for="writeleash-free-amount"]').text(operation === 'SET' ? __( 'New price', 'writeleash' ) : (operation.indexOf('PERCENT') !== -1 ? __( 'Percentage (e.g. 8 for 8%)', 'writeleash' ) : (operation.indexOf('INCREASE') === 0 ? __( 'Amount to increase by', 'writeleash' ) : __( 'Amount to decrease by', 'writeleash' ))));
        }
        method.on('change', selectionMethod);
        $('#writeleash-free-include-subcategories').on('change', selectionMethod);
        form.find('[name=selector], [name=category], [name=include_subcategories], [name="product_ids[]"], [name=ids], [name=sku]').on('change input', function () {
            $('#writeleash-free-selection-count').text(__( 'Selection changed. Check selection count again; Preview resolves and freezes the products independently.', 'writeleash' ));
        });
        $('#writeleash-free-operation').on('change', amountLabel);
        selectionMethod();
        amountLabel();
        if (!$.fn.selectWoo) { return; }
        var maxSelection = parseInt(form.data('max-selection'), 10) || 1000;
        var status = $('#writeleash-free-discovery-status');
        var products = $('#writeleash-free-products');
        var category = $('#writeleash-free-category');
        function selectedList() {
            var list = $('#writeleash-free-selected').empty();
            products.find('option:selected').each(function () {
                var row = $('<li>').text(this.text + ' ');
                $('<button>', { type: 'button', 'class': 'button', text: __( 'Remove', 'writeleash' ), 'aria-label': sprintf( /* translators: %s: product description. */ __( 'Remove %s', 'writeleash' ), this.text ) }).on('click', function () {
                    products.find('option').filter(function () { return this.value === row.data('id'); }).prop('selected', false);
                    products.trigger('change');
                    var search = products.next('.select2-container').find('.select2-search__field');
                    (search.length ? search : products).trigger('focus');
                }).appendTo(row);
                row.data('id', this.value).appendTo(list);
            });
            if (!list.children().length) { $('<li>').text(__( 'No products selected. Search and choose products to add them.', 'writeleash' )).appendTo(list); }
        }
        function enhance(control, kind) {
            var sequence = 0;
            var fallback = $('#writeleash-free-' + kind + '-fallback');
            control.selectWoo({
                width: '100%', minimumInputLength: kind === 'products' ? 1 : 0,
                maximumSelectionLength: kind === 'products' ? maxSelection : 0,
                // SelectWoo's default English announcements belong to this workflow too.
                language: {
                    errorLoading: function () { return __( 'Search is unavailable. Try again.', 'writeleash' ); },
                    inputTooShort: function (args) { var count = args.minimum - args.input.length; return sprintf( /* translators: %d: additional characters needed. */ _n( 'Enter %d more character.', 'Enter %d more characters.', count, 'writeleash' ), count ); },
                    inputTooLong: function (args) { var count = args.input.length - args.maximum; return sprintf( /* translators: %d: characters to remove. */ _n( 'Remove %d character.', 'Remove %d characters.', count, 'writeleash' ), count ); },
                    maximumSelected: function (args) { return sprintf( /* translators: %d: maximum selected products. */ _n( 'Choose at most %d product.', 'Choose at most %d products.', args.maximum, 'writeleash' ), args.maximum ); },
                    noResults: function () { return __( 'No matches found.', 'writeleash' ); },
                    searching: function () { return __( 'Searching…', 'writeleash' ); },
                    loadingMore: function () { return __( 'Loading more matches…', 'writeleash' ); }
                },
                placeholder: kind === 'products' ? __( 'Search product name or SKU', 'writeleash' ) : __( 'Choose a named category', 'writeleash' ),
                ajax: {
                    delay: 300,
                    data: function (params) { return { action: form.data('discovery-action'), nonce: form.data('discovery-nonce'), kind: kind, term: params.term || '', page: params.page || 1 }; },
                    transport: function (params, success, failure) {
                        var generation = ++sequence;
                        status.text(__( 'Searching…', 'writeleash' ));
                        var request = $.ajax({ url: form.data('discovery-url'), data: params.data, dataType: 'json', timeout: 10000 });
                        request.done(function (response) {
                            if (generation !== sequence) { return; }
                            if (!response.success) { fallback.prop('open', true); status.text(__( 'Search is unavailable. Reload if your session expired, or use the native search below.', 'writeleash' )); failure(); control.selectWoo('close'); return; }
                            status.text(response.data.capped ? __( 'Search limit reached. Use a more specific name or SKU.', 'writeleash' ) : (response.data.results.length ? __( 'Choose matches to add them. Search matches are not automatically selected; eligibility is checked in preview.', 'writeleash' ) : __( 'No matches on this page. Try another name or SKU, or the native search below.', 'writeleash' )));
                            success(response.data);
                            // An empty/error message is not a listbox option.
                            if (!response.data.results.length) { control.selectWoo('close'); }
                        });
                        request.fail(function (_, reason) {
                            if (generation !== sequence || reason === 'abort') { return; }
                            fallback.prop('open', true);
                            status.text(__( 'Search is unavailable. Try again, reload if your session expired, or use the native search below.', 'writeleash' ));
                            failure();
                            control.selectWoo('close');
                        });
                        return { abort: function () { ++sequence; request.abort(); } };
                    },
                    processResults: function (data) { return { results: data.results, pagination: { more: data.more } }; }
                }
            });
            // Name the enhanced controls from the native label. SelectWoo's
            // multiple wrapper otherwise exposes expanded state without a role,
            // and its category value textbox has no accessible name.
            var label = $('label[for="' + control.attr('id') + '"]');
            label.attr('id', control.attr('id') + '-label');
            var selection = control.next('.select2-container').find('.select2-selection');
            selection.attr({ role: 'combobox', 'aria-haspopup': 'listbox', 'aria-labelledby': label.attr('id') });
            selection.find('[role="textbox"]').attr('aria-labelledby', label.attr('id'));
            control.on('select2:open', function () {
                var results = selection.attr('aria-owns');
                selection.attr('aria-controls', results);
                $('#' + results).closest('.select2-dropdown').find('.select2-search__field').attr({ 'aria-labelledby': label.attr('id'), 'aria-controls': results });
            });
            control.on('select2:close', function () { selection.removeAttr('aria-controls'); });
            fallback.prop('open', false);
        }
        // A native search must keep its server-rendered matches selectable
        // even when the live suggestions endpoint is unavailable.
        if (!$('#writeleash-free-product_search').val()) { enhance(products, 'products'); }
        if (!$('#writeleash-free-category_search').val()) { enhance(category, 'categories'); }
        products.on('change', selectedList);
        $('#writeleash-free-clear').on('click', function (event) { event.preventDefault(); products.val([]).trigger('change'); });
        selectedList();
    });
})(jQuery);
