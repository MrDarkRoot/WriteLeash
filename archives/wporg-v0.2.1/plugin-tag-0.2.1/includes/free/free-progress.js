/* Saved Apply/Undo observations only. This client never requests execution. */
(function () {
    'use strict';
    var __ = wp.i18n.__, _n = wp.i18n._n, _x = wp.i18n._x, sprintf = wp.i18n.sprintf;
    var root = document.querySelector('[data-writeleash-progress]');
    if (!root || !window.fetch || !window.AbortController) { return; }
    var timer = null, timeout = null, controller = null, generation = 0;
    var failures = 0, polling = true, stopped = false, lastSuccess = '', lastAnnouncement = '';
    var connection = root.querySelector('[data-progress-connection]');
    var announcement = root.querySelector('[data-progress-announcement]');
    function write(selector, value) {
        var node = root.querySelector(selector);
        if (node && node.textContent !== value) { node.textContent = value; }
    }
    function connectionText(text) {
        // translators: 1: refresh status, 2: time of last successful refresh.
        connection.textContent = lastSuccess ? sprintf( __( '%1$s Last successful refresh: %2$s.', 'writeleash' ), text, lastSuccess ) : sprintf( /* translators: %s: refresh status. */ __( '%s No successful automatic refresh yet.', 'writeleash' ), text );
    }
    function outcome(cell, html, text) {
        // Server-escaped outcome markup keeps the polled cells identical to the
        // server-rendered cells (outcome emphasis and conflict next-action links).
        // Plain text is only a fallback for payloads predating the markup fields.
        if (typeof html === 'string' && html !== '' && 'innerHTML' in cell) { cell.innerHTML = html; return; }
        cell.textContent = text;
    }
    function action(selector, available, label, nonce, actionName) {
        var wrapper = root.querySelector(selector);
        if (!wrapper) { return; }
        var button = wrapper.querySelector('button');
        if (!button && available) {
            var form = document.createElement('form'); form.method = 'post'; form.action = root.getAttribute('data-action-url');
            [['action', actionName], ['job', root.getAttribute('data-job')], ['_wpnonce', nonce]].forEach(function (field) {
                var input = document.createElement('input'); input.type = 'hidden'; input.name = field[0]; input.value = field[1]; form.appendChild(input);
            });
            button = document.createElement('button'); button.type = 'submit'; button.className = 'button button-primary'; button.textContent = label; form.appendChild(button); wrapper.appendChild(form);
        }
        if (button) {
            button.disabled = !available;
            if (label) { button.textContent = label; }
        }
        // A focused button stays in the document until focus leaves; it is disabled immediately.
        if (!available && wrapper.contains(document.activeElement)) {
            wrapper.addEventListener('focusout', function hide() {
                wrapper.removeEventListener('focusout', hide);
                if (button.disabled) { wrapper.hidden = true; }
            });
        } else { wrapper.hidden = !available; }
    }
    function updateRows(rows) {
        var body = root.querySelector('[data-writeleash-results] tbody');
        if (!body) { return; }
        var wanted = rows.map(function (row) { return String(row.id); });
        var existing = Array.prototype.slice.call(body.querySelectorAll('tr[data-product-id]'));
        // Retain a focused review link if its filtered row has moved out of this page.
        if (existing.some(function (tr) { return wanted.indexOf(tr.getAttribute('data-product-id')) < 0 && tr.contains(document.activeElement); })) {
            connectionText(__( 'Saved counters updated; the focused review link still shows the previous view. Refresh the product list after leaving the focused link.', 'writeleash' ));
            return;
        }
        existing.forEach(function (tr) { if (wanted.indexOf(tr.getAttribute('data-product-id')) < 0) { tr.remove(); } });
        Array.prototype.slice.call(body.querySelectorAll('tr:not([data-product-id])')).forEach(function (tr) { tr.remove(); });
        var rendered = rows.map(function (row) {
            var tr = existing.find(function (candidate) { return candidate.getAttribute('data-product-id') === String(row.id); });
            if (!tr) {
                tr = document.createElement('tr');
                tr.setAttribute('data-product-id', String(row.id));
                [row.name, row.expected, __( 'Reload to read the current price', 'writeleash' ), row.planned, '', ''].forEach(function (text) {
                    var td = document.createElement('td'); td.textContent = text; tr.appendChild(td);
                });
                body.appendChild(tr);
            }
            if (!tr.children[4].contains(document.activeElement)) { outcome(tr.children[4], row.apply_html, row.apply); }
            else { connectionText(__( 'Saved counters updated; the focused outcome still shows its previous saved details. Leave the focused outcome to refresh it.', 'writeleash' )); }
            tr.children[4].className = row.apply_attention ? 'writeleash-attention' : '';
            if (!tr.children[5].contains(document.activeElement)) { outcome(tr.children[5], row.undo_html, row.undo); }
            else { connectionText(__( 'Saved counters updated; the focused outcome still shows its previous saved details. Leave the focused outcome to refresh it.', 'writeleash' )); }
            tr.children[5].className = row.undo_attention ? 'writeleash-attention' : '';
            return tr;
        });
        if (!rows.length) {
            var empty = document.createElement('tr'), cell = document.createElement('td');
            cell.colSpan = 6; cell.textContent = __( 'No retained products on this page match this view.', 'writeleash' ); empty.appendChild(cell); body.appendChild(empty);
            return;
        }
        // Reflect the durable server page order. Moving a focused row would drop
        // focus, so a focused row keeps its place and the remaining rows are
        // reconciled to the server order around it; without focus this is exact.
        var index = 0;
        rendered.forEach(function (tr) {
            if (body.children[index] === tr) { index += 1; return; }
            if (tr.contains(document.activeElement)) { return; }
            body.insertBefore(tr, body.children[index] || null);
            index += 1;
        });
    }
    function apply(data) {
        write('[data-progress-label]', data.label);
        write('[data-progress-summary]', data.summary);
        write('[data-progress-notice]', data.notice);
        write('[data-progress-undo-label]', data.undo_label);
        write('[data-progress-undo-summary]', data.undo_summary);
        write('[data-progress-undo-notice]', data.undo_notice);
        action('[data-progress-resume]', data.resume_available, __( 'Resume remaining products', 'writeleash' ), data.resume_nonce, 'writeleash_free_resume');
        action('[data-progress-undo]', data.undo_available, data.undo_button, data.undo_nonce, 'writeleash_free_undo');
        Array.prototype.slice.call(root.querySelectorAll('[data-progress-initial-notice], [data-progress-initial-undo]')).forEach(function (node) {
            if (node.contains(document.activeElement)) {
                node.addEventListener('focusout', function hide() { node.removeEventListener('focusout', hide); node.hidden = true; });
                connectionText(__( 'Saved counters updated; the focused support details still show the previous saved values. Leave the focused support details to refresh them.', 'writeleash' ));
            } else { node.hidden = true; }
        });
        updateRows(data.rows);
        var pager = root.querySelector('[data-progress-pager]');
        if (pager) {
            var next = pager.querySelector('p').lastElementChild;
            // Keep focus on pager buttons/links; update availability without replacement.
            if (next && next.tagName === 'A') {
                if (!next.progressClickBound) {
                    next.addEventListener('click', function (event) { if (next.getAttribute('aria-disabled') === 'true') { event.preventDefault(); } });
                    next.progressClickBound = true;
                }
                next.setAttribute('aria-disabled', data.next_url ? 'false' : 'true');
                if (!data.next_url && next === document.activeElement) {
                    next.addEventListener('blur', function hide() { next.removeEventListener('blur', hide); if (next.getAttribute('aria-disabled') === 'true') { next.hidden = true; } });
                } else { next.hidden = !data.next_url; }
                if (data.next_url) { next.href = data.next_url; }
            }
            else if (next && data.next_url && next !== document.activeElement) {
                var link = document.createElement('a'); link.className = 'button'; link.href = data.next_url; link.textContent = __( 'Next page', 'writeleash' ); next.replaceWith(link);
            }
        }
        var concise = sprintf( /* translators: 1: job status, 2: Apply summary, 3: Undo summary. */ __( '%1$s. %2$s Undo: %3$s.', 'writeleash' ), data.label, data.summary, data.undo_summary );
        if (concise !== lastAnnouncement) { announcement.textContent = concise; lastAnnouncement = concise; }
        polling = data.poll === true;
    }
    function schedule(delay) {
        window.clearTimeout(timer);
        if (!stopped && polling && !document.hidden) { timer = window.setTimeout(refresh, delay); }
    }
    function failed(status) {
        failures += 1;
        if (status === 401 || status === 403 || failures >= 4) {
            stopped = true;
            connectionText(status === 401 || status === 403 ? __( 'Session or permission expired. Reload and sign in to refresh saved progress.', 'writeleash' ) : __( 'Automatic updates stopped after repeated errors. Use Refresh saved progress to retry.', 'writeleash' ));
        } else { connectionText(__( 'Saved progress may be stale. Refresh failed; retrying shortly.', 'writeleash' )); schedule(Math.min(60000, 5000 * Math.pow(2, failures))); }
    }
    function refresh() {
        if (document.hidden || stopped || controller) { return; }
        var token = ++generation;
        controller = new window.AbortController();
        var requestController = controller;
        var form = new window.URLSearchParams();
        form.set('action', 'writeleash_free_progress'); form.set('job', root.getAttribute('data-job'));
        form.set('_wpnonce', root.getAttribute('data-nonce')); form.set('offset', root.getAttribute('data-offset')); form.set('filter', root.getAttribute('data-filter'));
        timeout = window.setTimeout(function () {
            if (token !== generation) { return; }
            generation += 1; requestController.abort(); controller = null; failed(0);
        }, 15000);
        window.fetch(root.getAttribute('data-endpoint'), {method: 'POST', credentials: 'same-origin', cache: 'no-store', body: form, signal: requestController.signal})
            .then(function (response) {
                if (!response.ok) { var error = new Error('Refresh refused'); error.status = response.status; throw error; }
                return response.json();
            })
            .then(function (response) {
                if (token !== generation || document.hidden) { return; }
                if (!response.success || !response.data || response.data.status !== 'OK' || !Array.isArray(response.data.rows)) { throw new Error('Saved progress unavailable'); }
                window.clearTimeout(timeout); controller = null; failures = 0;
                lastSuccess = new Date().toLocaleTimeString();
                connectionText(response.data.poll ? __( 'Saved progress refreshed. Automatic updates active.', 'writeleash' ) : __( 'Saved progress refreshed. Automatic updates stopped; refresh manually for later activity.', 'writeleash' ));
                apply(response.data); schedule(5000);
            })
            .catch(function (error) {
                if (token !== generation) { return; }
                window.clearTimeout(timeout); controller = null; failed(error.status || 0);
            });
    }
    document.addEventListener('visibilitychange', function () {
        window.clearTimeout(timer);
        if (document.hidden) {
            generation += 1; window.clearTimeout(timeout);
            if (controller) { controller.abort(); controller = null; }
            if (!stopped && polling) { connectionText(__( 'Automatic updates paused while this page is hidden. Saved progress may be stale.', 'writeleash' )); }
        } else if (!stopped && polling) { refresh(); }
    });
    window.addEventListener('pagehide', function () {
        stopped = true; generation += 1; window.clearTimeout(timer); window.clearTimeout(timeout);
        if (controller) { controller.abort(); controller = null; }
    });
    connectionText(__( 'Checking saved progress.', 'writeleash' ));
    refresh();
}());
