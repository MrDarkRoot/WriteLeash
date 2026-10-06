/* Read-only progressive enhancement. Planner authority stays on the server. */
(function ($) {
    'use strict';
    $(function () {
        var form = $('#writeleash-free-selection-form');
        if (!form.length || !$.fn.selectWoo) { return; }
        var maxSelection = parseInt(form.data('max-selection'), 10) || 1000;
        var status = $('#writeleash-free-discovery-status');
        var products = $('#writeleash-free-products');
        var category = $('#writeleash-free-category');
        function selectedList() {
            var list = $('#writeleash-free-selected').empty();
            products.find('option:selected').each(function () {
                var row = $('<li>').text(this.text + ' ');
                $('<button>', { type: 'button', 'class': 'button', text: 'Remove', 'aria-label': 'Remove ' + this.text }).on('click', function () {
                    products.find('option').filter(function () { return this.value === row.data('id'); }).prop('selected', false);
                    products.trigger('change');
                    var search = products.next('.select2-container').find('.select2-search__field');
                    (search.length ? search : products).trigger('focus');
                }).appendTo(row);
                row.data('id', this.value).appendTo(list);
            });
            if (!list.children().length) { $('<li>').text('No products selected. Search and choose products to add them.').appendTo(list); }
        }
        function enhance(control, kind) {
            var sequence = 0;
            var fallback = $('#writeleash-free-' + kind + '-fallback');
            control.selectWoo({
                width: '100%', minimumInputLength: kind === 'products' ? 1 : 0,
                maximumSelectionLength: kind === 'products' ? maxSelection : 0,
                placeholder: kind === 'products' ? 'Search product name or SKU' : 'Choose a named category',
                ajax: {
                    delay: 300,
                    data: function (params) { return { action: form.data('discovery-action'), nonce: form.data('discovery-nonce'), kind: kind, term: params.term || '', page: params.page || 1 }; },
                    transport: function (params, success, failure) {
                        var generation = ++sequence;
                        status.text('Searching…');
                        var request = $.ajax({ url: form.data('discovery-url'), data: params.data, dataType: 'json', timeout: 10000 });
                        request.done(function (response) {
                            if (generation !== sequence) { return; }
                            if (!response.success) { fallback.prop('open', true); status.text('Search is unavailable. Reload if your session expired, or use the native search below.'); failure(); control.selectWoo('close'); return; }
                            status.text(response.data.capped ? 'Search limit reached. Use a more specific name or SKU.' : (response.data.results.length ? 'Choose matches to add them. Search matches are not automatically selected; eligibility is checked in preview.' : 'No matches on this page. Try another name or SKU, or the native search below.'));
                            success(response.data);
                            // An empty/error message is not a listbox option.
                            if (!response.data.results.length) { control.selectWoo('close'); }
                        });
                        request.fail(function (_, reason) {
                            if (generation !== sequence || reason === 'abort') { return; }
                            fallback.prop('open', true);
                            status.text('Search is unavailable. Try again, reload if your session expired, or use the native search below.');
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
