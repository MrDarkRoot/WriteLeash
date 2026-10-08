/* Saved Apply/Undo observations only. This client never requests execution. */
(function () {
    'use strict';
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
        connection.textContent = text + (lastSuccess ? ' Last successful refresh: ' + lastSuccess + '.' : ' No successful automatic refresh yet.');
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
            connectionText('Saved counters updated. Refresh the product list after leaving the focused link.');
            return;
        }
        existing.forEach(function (tr) { if (wanted.indexOf(tr.getAttribute('data-product-id')) < 0) { tr.remove(); } });
        Array.prototype.slice.call(body.querySelectorAll('tr:not([data-product-id])')).forEach(function (tr) { tr.remove(); });
        rows.forEach(function (row) {
            var tr = existing.find(function (candidate) { return candidate.getAttribute('data-product-id') === String(row.id); });
            if (!tr) {
                tr = document.createElement('tr');
                tr.setAttribute('data-product-id', String(row.id));
                [row.name, row.expected, 'Reload to read the current price', row.planned, '', ''].forEach(function (text) {
                    var td = document.createElement('td'); td.textContent = text; tr.appendChild(td);
                });
                body.appendChild(tr);
            }
            if (!tr.children[4].contains(document.activeElement)) { tr.children[4].textContent = row.apply; }
            else { connectionText('Saved counters updated. Leave the focused outcome to refresh its details.'); }
            tr.children[4].className = row.apply_attention ? 'writeleash-attention' : '';
            if (!tr.children[5].contains(document.activeElement)) { tr.children[5].textContent = row.undo; }
            else { connectionText('Saved counters updated. Leave the focused outcome to refresh its details.'); }
            tr.children[5].className = row.undo_attention ? 'writeleash-attention' : '';
        });
        if (!rows.length) {
            var empty = document.createElement('tr'), cell = document.createElement('td');
            cell.colSpan = 6; cell.textContent = 'No retained products on this page match this view.'; empty.appendChild(cell); body.appendChild(empty);
        }
    }
    function apply(data) {
        write('[data-progress-label]', data.label);
        write('[data-progress-summary]', data.summary);
        write('[data-progress-notice]', data.notice);
        write('[data-progress-undo-label]', data.undo_label);
        write('[data-progress-undo-summary]', data.undo_summary);
        write('[data-progress-undo-notice]', data.undo_notice);
        action('[data-progress-resume]', data.resume_available, 'Resume remaining products', data.resume_nonce, 'writeleash_free_resume');
        action('[data-progress-undo]', data.undo_available, data.undo_button, data.undo_nonce, 'writeleash_free_undo');
        Array.prototype.slice.call(root.querySelectorAll('[data-progress-initial-notice], [data-progress-initial-undo]')).forEach(function (node) {
            if (node.contains(document.activeElement)) {
                node.addEventListener('focusout', function hide() { node.removeEventListener('focusout', hide); node.hidden = true; });
                connectionText('Saved counters updated. Leave the focused support details to refresh them.');
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
                var link = document.createElement('a'); link.className = 'button'; link.href = data.next_url; link.textContent = 'Next page'; next.replaceWith(link);
            }
        }
        var concise = data.label + '. ' + data.summary + ' Undo: ' + data.undo_summary + '.';
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
            connectionText(status === 401 || status === 403 ? 'Session or permission expired. Reload and sign in to refresh saved progress.' : 'Automatic updates stopped after repeated errors. Use Refresh saved progress to retry.');
        } else { connectionText('Saved progress may be stale. Refresh failed; retrying shortly.'); schedule(Math.min(60000, 5000 * Math.pow(2, failures))); }
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
                connectionText(response.data.poll ? 'Saved progress refreshed. Automatic updates active.' : 'Saved progress refreshed. Automatic updates stopped; refresh manually for later activity.');
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
            if (!stopped && polling) { connectionText('Automatic updates paused while this page is hidden. Saved progress may be stale.'); }
        } else if (!stopped && polling) { refresh(); }
    });
    window.addEventListener('pagehide', function () {
        stopped = true; generation += 1; window.clearTimeout(timer); window.clearTimeout(timeout);
        if (controller) { controller.abort(); controller = null; }
    });
    connectionText('Checking saved progress.');
    refresh();
}());
