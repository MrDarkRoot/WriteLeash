/* Read-only progressive enhancement. Planner authority stays on the server. */
(function ($) {
    'use strict';
    $(function () {
        var form = $('#writeleash-free-selection-form');
        if (!form.length || !$.fn.selectWoo) { return; }
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
            control.selectWoo({
                width: '100%', minimumInputLength: kind === 'products' ? 1 : 0,
                maximumSelectionLength: kind === 'products' ? 100 : 0,
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
                            if (!response.success) { status.text('Search is unavailable. Reload if your session expired, or use the native search below.'); failure(); return; }
                            status.text(response.data.capped ? 'Search limit reached. Use a more specific name or SKU.' : (response.data.results.length ? 'Choose matches to add them. Search matches are not automatically selected; eligibility is checked in preview.' : 'No matches on this page. Try another name or SKU, or the native search below.'));
                            success(response.data);
                        });
                        request.fail(function (_, reason) {
                            if (generation !== sequence || reason === 'abort') { return; }
                            status.text('Search is unavailable. Try again, reload if your session expired, or use the native search below.');
                            failure();
                        });
                        return { abort: function () { ++sequence; request.abort(); } };
                    },
                    processResults: function (data) { return { results: data.results, pagination: { more: data.more } }; }
                }
            });
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
