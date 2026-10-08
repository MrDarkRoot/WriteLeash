/* Executes the shipped client with deterministic DOM/transport/timer boundaries.
 * This is client logic coverage, not a WooCommerce or accessibility browser pass. */
'use strict';
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../writeleash/includes/free/free-progress.js'), 'utf8');
const compiled = new vm.Script(source);
const admin = fs.readFileSync(path.join(__dirname, '../../writeleash/includes/free/class-free-admin.php'), 'utf8');
function phpConstant(name) {
    const match = admin.match(new RegExp(`const ${name}\\s*=\\s*'([^']+)'`));
    assert(match, `PHP constant ${name} present`);
    return match[1];
}
function phpConflictCopy(state) {
    const pattern = state === 'CONFLICT'
        ? /'(This product\u2019s price or other conditions changed after you reviewed the preview\. WriteLeash left the newer value unchanged\.)'/
        : /'(This product changed after WriteLeash applied its price\. WriteLeash preserved the newer value instead of restoring over it\.)'/;
    const match = admin.match(pattern);
    assert(match, `PHP conflict copy for ${state} present`);
    return match[1];
}
class Element {
    constructor(tag = 'P') { this.tagName = tag.toUpperCase(); this.children = []; this.textContent = ''; this.attrs = {}; this.events = {}; this.hidden = false; }
    appendChild(child) { this.children.push(child); child.parent = this; return child; }
    insertBefore(child, reference) {
        this.children = this.children.filter(x => x !== child);
        const index = reference == null ? this.children.length : this.children.indexOf(reference);
        this.children.splice(index < 0 ? this.children.length : index, 0, child);
        child.parent = this; return child;
    }
    replaceWith(child) {
        const index = this.parent.children.indexOf(this);
        this.parent.children[index] = child; child.parent = this.parent; return child;
    }
    setAttribute(name, value) { this.attrs[name] = value; }
    getAttribute(name) { return this.attrs[name] ?? null; }
    contains(child) { return this === child || this.children.some(x => x.contains(child)); }
    addEventListener(name, cb) { (this.events[name] ||= []).push(cb); }
    removeEventListener(name, cb) { this.events[name] = (this.events[name] || []).filter(x => x !== cb); }
    dispatch(name, event) { (this.events[name] || []).slice().forEach(cb => cb(event)); }
    querySelector(selector) { return selector === 'button' ? this.children.flatMap(x => x.children).find(x => x.tagName === 'BUTTON') || this.children.find(x => x.tagName === 'BUTTON') || null : null; }
    querySelectorAll(selector) { return this.children.filter(tr => selector.includes(':not') ? !tr.attrs['data-product-id'] : tr.attrs['data-product-id']); }
    remove() { this.parent.children = this.parent.children.filter(x => x !== this); }
}
function harness({withMount = true, nextTag = 'A'} = {}) {
    const root = new Element('DIV'), body = new Element('TBODY');
    const nodes = {};
    const moves = [];
    const insert = body.insertBefore.bind(body), append = body.appendChild.bind(body);
    body.insertBefore = (child, reference) => { moves.push({op: 'insertBefore', node: child}); return insert(child, reference); };
    body.appendChild = child => { moves.push({op: 'appendChild', node: child}); return append(child); };
    ['connection', 'announcement', 'label', 'summary', 'notice', 'undo-label', 'undo-summary', 'undo-notice', 'resume', 'undo'].forEach(name => nodes[`[data-progress-${name}]`] = new Element());
    root.attrs = {'data-job': 'job-204', 'data-nonce': 'nonce-204', 'data-offset': '0', 'data-filter': '', 'data-endpoint': '/ajax', 'data-action-url': '/post'};
    root.querySelector = selector => selector === '[data-writeleash-results] tbody' ? body : nodes[selector] || null;
    root.querySelectorAll = () => [];
    const document = new Element(); document.hidden = false; document.activeElement = new Element('A');
    document.querySelector = () => withMount ? root : null; document.createElement = tag => new Element(tag);
    const requests = [], timers = new Map(); let next = 0;
    const pager = new Element('DIV'), paragraph = new Element('P'), previous = new Element('A'), nextLink = new Element(nextTag);
    if (nextTag !== 'A') { nextLink.disabled = true; nextLink.setAttribute('aria-disabled', 'true'); }
    paragraph.appendChild(previous); paragraph.appendChild(nextLink); pager.appendChild(paragraph);
    Object.defineProperty(paragraph, 'lastElementChild', {get: () => paragraph.children.at(-1)}); pager.querySelector = () => paragraph; nodes['[data-progress-pager]'] = pager;
    const window = new Element(); window.URLSearchParams = URLSearchParams; window.AbortController = AbortController;
    window.setTimeout = (fn, delay) => { timers.set(++next, {fn, delay}); return next; }; window.clearTimeout = id => timers.delete(id);
    window.fetch = (url, opts) => new Promise((resolve, reject) => requests.push({url, opts, resolve, reject}));
    compiled.runInNewContext({window, document, Date, Error, Array, String, Math});
    return {nodes, root, body, document, window, requests, timers, nextLink, moves,
        timer(delay) { const entry = [...timers.entries()].find(([, x]) => x.delay === delay); assert(entry, `timer ${delay}`); timers.delete(entry[0]); entry[1].fn(); }};
}
const flush = async () => { for (let n = 0; n < 8; n++) { await Promise.resolve(); } };
const row = (id, overrides = {}) => ({id, name: `Saved product ${id}`, expected: '10 USD', planned: '20 USD', apply: 'Changed', undo: 'Not started', apply_attention: false, undo_attention: false, ...overrides});
const ids = body => body.children.filter(tr => tr.attrs['data-product-id']).map(tr => tr.attrs['data-product-id']);
function seedRow(h, id, apply = 'Old apply', undo = 'Old undo') {
    const tr = new Element('TR'); tr.setAttribute('data-product-id', String(id));
    for (let n = 0; n < 6; n++) { tr.appendChild(new Element('TD')); }
    tr.children[4].textContent = apply; tr.children[5].textContent = undo; h.body.appendChild(tr); return tr;
}
function snapshot(overrides = {}) {
    return {status: 'OK', label: 'In progress', summary: '1 changed · 2 remaining', notice: '', undo_label: 'Undo unavailable', undo_summary: '0 restored', undo_notice: '', next_url: '/next', resume_available: true, undo_available: false, resume_nonce: 'bound-resume', undo_nonce: null, undo_button: 'Continue Undo', poll: true, rows: [row(4, {name: '<script>literal</script>'})], ...overrides};
}
async function respond(h, index, data) { h.requests[index].resolve({ok: true, status: 200, json: async () => ({success: true, data})}); await flush(); }
(async () => {
    // Shipped selectors and action/nonce names must match the PHP renderer and constants.
    assert.equal(phpConstant('ACTION_PROGRESS'), 'writeleash_free_progress');
    assert.equal(phpConstant('ACTION_RESUME'), 'writeleash_free_resume');
    assert.equal(phpConstant('ACTION_UNDO'), 'writeleash_free_undo');
    const selectors = [...new Set([...source.matchAll(/\[(data-[a-z-]+)\]/g)].map(match => match[1]))];
    assert(selectors.length >= 10, `selectors extracted: ${selectors.length}`);
    selectors.forEach(name => assert(admin.includes(name), `PHP renderer provides ${name}`));
    assert(!/\b(jQuery|React|Vue|Angular|alpinejs?)\b/i.test(source), 'no framework introduced');
    let h = harness(); assert.equal(h.requests.length, 1); assert.equal(h.requests[0].opts.method, 'POST');
    assert.equal(h.requests[0].opts.body.get('action'), phpConstant('ACTION_PROGRESS'));
    assert.equal(h.requests[0].opts.body.get('job'), 'job-204'); assert.equal(h.requests[0].opts.body.get('_wpnonce'), 'nonce-204');
    assert.equal(h.requests[0].opts.body.get('offset'), '0'); assert.equal(h.requests[0].opts.body.get('filter'), '');
    assert.equal(h.requests[0].opts.credentials, 'same-origin'); assert.equal(h.requests[0].opts.cache, 'no-store');
    await respond(h, 0, snapshot());
    assert.equal(h.nodes['[data-progress-summary]'].textContent, '1 changed · 2 remaining');
    assert.equal(h.body.children[0].children[0].textContent, '<script>literal</script>');
    assert.equal(h.body.children[0].children[2].textContent, 'Reload to read the current price');
    assert.match(h.nodes['[data-progress-connection]'].textContent, /Last successful refresh/);
    const button = h.nodes['[data-progress-resume]'].querySelector('button');
    assert(button); assert.equal(button.type, 'submit');
    const form = h.nodes['[data-progress-resume]'].children[0]; assert.equal(form.action, '/post');
    assert.equal(form.children[0].name, 'action'); assert.equal(form.children[0].value, phpConstant('ACTION_RESUME'));
    assert.equal(form.children[1].name, 'job'); assert.equal(form.children[1].value, 'job-204');
    assert.equal(form.children[2].name, '_wpnonce'); assert.equal(form.children[2].value, 'bound-resume');
    h.document.activeElement = button;
    h.timer(5000); await respond(h, 1, snapshot({label: 'Finished with products needing attention', summary: '1 changed · 1 conflict · 1 uncertain', resume_available: false, undo_available: true, undo_nonce: 'bound-undo', poll: false, rows: [row(4, {apply: 'Outcome uncertain; needs checking', apply_attention: true})]}));
    assert.equal(h.document.activeElement, button); assert.equal(button.disabled, true); assert.equal(h.nodes['[data-progress-resume]'].hidden, false);
    h.document.activeElement = new Element('A'); h.nodes['[data-progress-resume]'].dispatch('focusout'); assert.equal(h.nodes['[data-progress-resume]'].hidden, true);
    const undoForm = h.nodes['[data-progress-undo]'].children[0];
    assert.equal(undoForm.children[0].name, 'action'); assert.equal(undoForm.children[0].value, phpConstant('ACTION_UNDO'));
    assert.equal(undoForm.children[2].name, '_wpnonce'); assert.equal(undoForm.children[2].value, 'bound-undo');
    assert.equal(h.nodes['[data-progress-undo]'].querySelector('button').textContent, 'Continue Undo');
    assert.equal(h.body.children[0].children[4].className, 'writeleash-attention'); assert.equal([...h.timers.values()].filter(x => x.delay === 5000).length, 0);
    assert.match(h.nodes['[data-progress-announcement]'].textContent, /1 uncertain/);
    assert.match(h.nodes['[data-progress-connection]'].textContent, /Automatic updates stopped; refresh manually/);
    // A focused outcome cell keeps its previous saved text and gets an explicit stale-detail message.
    h = harness(); await respond(h, 0, snapshot());
    const cell = h.body.children[0].children[4], focusedSummary = new Element('SUMMARY'); cell.appendChild(focusedSummary); h.document.activeElement = focusedSummary;
    h.timer(5000); await respond(h, 1, snapshot({rows: [row(4, {apply: 'New saved outcome'})]}));
    assert.equal(h.document.activeElement, focusedSummary); assert.equal(cell.textContent, 'Changed');
    assert.match(h.nodes['[data-progress-connection]'].textContent, /focused outcome still shows its previous saved details/);
    // Round 4: the endpoint-provided conflict guidance must reach the cells
    // verbatim (the polled DOM must not shorten the server-rendered sentences),
    // and a focused outcome still defers to its previous saved copy.
    const applyCopy = phpConflictCopy('CONFLICT'), undoCopy = phpConflictCopy('UNDO_CONFLICT');
    h = harness(); await respond(h, 0, snapshot({rows: [row(4, {apply: 'Not changed', undo: 'Not restored'})]}));
    h.timer(5000); await respond(h, 1, snapshot({rows: [row(4, {apply: 'Not changed · ' + applyCopy, undo: 'Not restored · ' + undoCopy, undo_attention: true})]}));
    assert.equal(h.body.children[0].children[4].textContent, 'Not changed · ' + applyCopy, 'endpoint Apply conflict copy written verbatim');
    assert.equal(h.body.children[0].children[5].textContent, 'Not restored · ' + undoCopy, 'endpoint Undo conflict copy written verbatim');
    assert.equal(h.body.children[0].children[5].className, 'writeleash-attention');
    const conflictFocus = new Element('SUMMARY'); h.body.children[0].children[4].appendChild(conflictFocus); h.document.activeElement = conflictFocus;
    h.timer(5000); await respond(h, 2, snapshot({rows: [row(4, {apply: 'Not changed · ' + applyCopy, undo: 'Not restored · ' + undoCopy, undo_attention: true})]}));
    assert.equal(h.body.children[0].children[4].textContent, 'Not changed · ' + applyCopy, 'focused outcome keeps the previous saved conflict copy');
    assert.match(h.nodes['[data-progress-connection]'].textContent, /focused outcome still shows its previous saved details/);
    h.document.activeElement = new Element('A');
    // A focused review link on a row that left the current filter is retained with an explicit stale-list message.
    h = harness(); await respond(h, 0, snapshot({rows: [row(4)]}));
    const reviewLink = new Element('A'); h.body.children[0].children[0].appendChild(reviewLink); h.document.activeElement = reviewLink;
    h.timer(5000); await respond(h, 1, snapshot({rows: [row(7)]}));
    assert.deepEqual(ids(h.body), ['4'], 'focused row retained'); assert.equal(h.body.children[0].children[0].children[0], reviewLink);
    assert.match(h.nodes['[data-progress-connection]'].textContent, /focused review link still shows the previous view/);
    // Reordering: the server page order is reflected, not just appended.
    h = harness(); seedRow(h, 5); seedRow(h, 4);
    await respond(h, 0, snapshot({rows: [row(4), row(5)]})); assert.deepEqual(ids(h.body), ['4', '5']);
    h.timer(5000); await respond(h, 1, snapshot({rows: [row(5), row(4)]})); assert.deepEqual(ids(h.body), ['5', '4'], 'server order reflected');
    // Reordering preserves focus without moving the focused row; other rows reconcile around it.
    h = harness(); const kept = seedRow(h, 5); const focused = seedRow(h, 4); h.document.activeElement = focused.children[4];
    await respond(h, 0, snapshot({rows: [row(4), row(5)]}));
    assert.deepEqual(ids(h.body), ['5', '4'], 'focused row keeps its place');
    assert.equal(h.document.activeElement, focused.children[4]);
    h = harness(); const moved = seedRow(h, 4), anchored = seedRow(h, 5); h.document.activeElement = moved.children[4];
    await respond(h, 0, snapshot({rows: [row(5), row(4)]}));
    assert.deepEqual(ids(h.body), ['5', '4'], 'non-focused rows reconcile around the focused row');
    assert.equal(h.document.activeElement, moved.children[4]); assert.equal(moved.parent, h.body);
    // A filter/page change returns a different row set and leaves no stale rows.
    h = harness(); await respond(h, 0, snapshot({rows: [row(4)]}));
    h.timer(5000); await respond(h, 1, snapshot({rows: [row(7), row(8)]}));
    assert.deepEqual(ids(h.body), ['7', '8']); assert(!ids(h.body).includes('4'), 'no stale rows remain');
    h.timer(5000); await respond(h, 2, snapshot({rows: []}));
    assert.equal(h.body.children.length, 1); assert.match(h.body.children[0].children[0].textContent, /No retained products/);
    h.timer(5000); await respond(h, 3, snapshot({rows: [row(9)]}));
    assert.deepEqual(ids(h.body), ['9'], 'empty-state row removed by the next page');
    // Pager next_url update path, including focused and terminal-shape markup.
    h = harness(); await respond(h, 0, snapshot({next_url: '/next'}));
    assert.equal(h.nextLink.tagName, 'A'); assert.equal(h.nextLink.href, '/next'); assert.equal(h.nextLink.hidden, false); assert.equal(h.nextLink.getAttribute('aria-disabled'), 'false');
    h.timer(5000); await respond(h, 1, snapshot({next_url: '/next2'})); assert.equal(h.nextLink.href, '/next2');
    h.document.activeElement = h.nextLink; h.timer(5000); await respond(h, 2, snapshot({next_url: null}));
    assert.equal(h.nextLink.hidden, false); assert.equal(h.nextLink.getAttribute('aria-disabled'), 'true');
    let blocked = false; h.nextLink.dispatch('click', {preventDefault() { blocked = true; }}); assert.equal(blocked, true);
    h.document.activeElement = new Element('A'); h.nextLink.dispatch('blur'); assert.equal(h.nextLink.hidden, true);
    h.timer(5000); await respond(h, 3, snapshot({next_url: '/next3'}));
    assert.equal(h.nextLink.hidden, false); assert.equal(h.nextLink.href, '/next3'); assert.equal(h.nextLink.getAttribute('aria-disabled'), 'false');
    h = harness({nextTag: 'BUTTON'}); await respond(h, 0, snapshot({next_url: '/next'}));
    const controls = h.nodes['[data-progress-pager]'].children[0].children;
    assert.equal(controls.at(-1).tagName, 'A'); assert.equal(controls.at(-1).href, '/next');
    // Focused support details and repeated announcements.
    h = harness(); const support = new Element('DETAILS'), supportFocus = new Element('SUMMARY'); support.appendChild(supportFocus); h.root.querySelectorAll = () => [support]; h.document.activeElement = supportFocus;
    await respond(h, 0, snapshot()); assert.equal(support.hidden, false); assert.equal(h.document.activeElement, supportFocus);
    assert.match(h.nodes['[data-progress-connection]'].textContent, /focused support details still show the previous saved values/);
    h.document.activeElement = new Element('A'); support.dispatch('focusout'); assert.equal(support.hidden, true);
    h = harness(); let announcements = 0, announced = ''; Object.defineProperty(h.nodes['[data-progress-announcement]'], 'textContent', {get: () => announced, set: value => {announcements++; announced = value;}});
    await respond(h, 0, snapshot()); h.timer(5000); await respond(h, 1, snapshot()); assert.equal(announcements, 1, 'unchanged counters not announced repeatedly');
    // Hidden pages pause and abort in-flight work, then resume once visible.
    h = harness(); h.document.hidden = true; h.document.dispatch('visibilitychange'); assert.equal(h.requests[0].opts.signal.aborted, true);
    assert.match(h.nodes['[data-progress-connection]'].textContent, /paused while this page is hidden/);
    await respond(h, 0, snapshot({label: 'Old hidden observation'})); assert.equal(h.nodes['[data-progress-label]'].textContent, '');
    h.document.hidden = false; h.document.dispatch('visibilitychange'); assert.equal(h.requests.length, 2);
    await respond(h, 1, snapshot({label: 'Undo in progress', undo_summary: '1 restored · 2 remaining'})); assert.equal(h.nodes['[data-progress-undo-summary]'].textContent, '1 restored · 2 remaining');
    h.document.hidden = true; h.document.dispatch('visibilitychange'); assert.equal([...h.timers.values()].length, 0);
    // Timeout aborts, backs off and a late response cannot overwrite the retry.
    h = harness(); h.timer(15000); assert.equal(h.requests[0].opts.signal.aborted, true);
    assert.match(h.nodes['[data-progress-connection]'].textContent, /may be stale.*retrying shortly/);
    h.timer(10000); assert.equal(h.requests.length, 2);
    await respond(h, 1, snapshot({label: 'New observation'})); await respond(h, 0, snapshot({label: 'Old delayed observation'})); assert.equal(h.nodes['[data-progress-label]'].textContent, 'New observation');
    // Session refusal stops polling; repeated transport errors back off then stop.
    h = harness(); h.requests[0].resolve({ok: false, status: 403}); await flush();
    assert.match(h.nodes['[data-progress-connection]'].textContent, /expired.*Reload and sign in/); assert.equal(h.timers.size, 0);
    h = harness(); h.requests[0].resolve({ok: false, status: 401}); await flush();
    assert.match(h.nodes['[data-progress-connection]'].textContent, /expired.*Reload and sign in/); assert.equal(h.timers.size, 0);
    h = harness();
    for (let i = 0; i < 4; i++) { h.requests[i].reject(new Error('network failed')); await flush(); if (i === 0) { assert.match(h.nodes['[data-progress-connection]'].textContent, /retrying shortly/); } if (i < 3) { h.timer([10000, 20000, 40000][i]); } }
    assert.match(h.nodes['[data-progress-connection]'].textContent, /stopped after repeated errors/); assert.equal(h.timers.size, 0);
    // Manual/no-JS fallback: without the mount the shipped client never requests.
    assert.equal(harness({withMount: false}).requests.length, 0);
    h = harness(); h.document.activeElement = new Element('A'); h.window.dispatch('pagehide'); await respond(h, 0, snapshot()); assert.equal(h.nodes['[data-progress-label]'].textContent, '');
    // Exhaustive property check of updateRows(): every server page order up to
    // n=4 crossed with every focus position the reconciliation policy defines.
    // Initial rows cover the same id set in every order, every position of an
    // extra row that must be removed, and every position of a missing row that
    // must be added (a row created during reconciliation cannot hold focus, so
    // the reachable equivalent is focus on a retained row while another row is
    // added in the same response). Invariants: no duplicate rows; rows absent
    // from the server page are removed unless focus sits in a removed row, in
    // which case the whole previous view is deferred with an explicit message;
    // focused rows are never moved or removed; non-focused rows keep server
    // relative order; without focus the DOM order equals server order exactly.
    const permutations = list => list.length < 2 ? [list.slice()] : list.flatMap((value, index) => permutations(list.slice(0, index).concat(list.slice(index + 1))).map(rest => [value, ...rest]));
    let enumeratedCases = 0, enumeratedAssertions = 0, deferredCases = 0;
    const checkEnum = (condition, label) => { enumeratedAssertions += 1; assert(condition, label); };
    for (let n = 1; n <= 4; n++) {
        const base = Array.from({length: n}, (_, index) => index + 1);
        const extra = 90 + n;
        const initialVariants = [];
        permutations(base).forEach(rows => initialVariants.push(rows));
        [base, base.slice().reverse()].forEach(order => {
            for (let position = 0; position <= n; position++) { const rows = order.slice(); rows.splice(position, 0, extra); initialVariants.push(rows); }
            base.forEach(missing => initialVariants.push(order.filter(id => id !== missing)));
            base.forEach(missing => { const kept = order.filter(id => id !== missing); for (let position = 0; position < n; position++) { const rows = kept.slice(); rows.splice(position, 0, extra); initialVariants.push(rows); } });
        });
        for (const serverRows of permutations(base)) {
            for (const initialRows of initialVariants) {
                for (let focus = 0; focus <= initialRows.length; focus++) {
                    enumeratedCases += 1;
                    const h = harness();
                    const seeded = initialRows.map(id => ({id, tr: seedRow(h, id)}));
                    let focused = null;
                    if (focus < seeded.length) {
                        const tr = seeded[focus].tr;
                        const control = new Element('A'); tr.children[0].appendChild(control); h.document.activeElement = control;
                        const rawRemove = tr.remove.bind(tr);
                        tr.remove = () => { h.moves.push({op: 'remove', node: tr}); rawRemove(); };
                        focused = tr;
                    }
                    const before = ids(h.body), operations = h.moves.length;
                    await respond(h, 0, snapshot({rows: serverRows.map(id => row(id))}));
                    const after = ids(h.body);
                    const focusedId = focused ? Number(focused.attrs['data-product-id']) : null;
                    const label = `n=${n} server=[${serverRows}] initial=[${before}] focus=${focusedId === null ? 'none' : focusedId}`;
                    checkEnum(new Set(after).size === after.length, `${label}: no duplicate rows`);
                    if (focused && !serverRows.includes(focusedId)) {
                        deferredCases += 1;
                        checkEnum(String(after) === String(before), `${label}: focused removed row defers the previous view`);
                        checkEnum(/previous view/.test(h.nodes['[data-progress-connection]'].textContent), `${label}: deferral states the previous view`);
                        checkEnum(h.moves.length === operations, `${label}: deferral performs no DOM edit`);
                        checkEnum(focused.contains(h.document.activeElement), `${label}: focus retained through deferral`);
                    } else {
                        checkEnum(String([...after].sort((a, b) => a - b)) === String([...serverRows].sort((a, b) => a - b)), `${label}: rows match the server page exactly`);
                        const others = serverRows.filter(id => id !== focusedId).map(String);
                        for (let first = 0; first < others.length; first++) for (let second = first + 1; second < others.length; second++) {
                            checkEnum(after.indexOf(others[first]) < after.indexOf(others[second]), `${label}: non-focused ${others[first]} stays before ${others[second]} as on the server`);
                        }
                        if (focused) {
                            checkEnum(focused.parent === h.body && focused.contains(h.document.activeElement), `${label}: focused row retained`);
                            checkEnum(!h.moves.slice(operations).some(move => move.node === focused), `${label}: focused row never moved or removed`);
                        } else {
                            checkEnum(String(after) === String(serverRows), `${label}: server order is exact without focus`);
                        }
                    }
                }
            }
        }
    }
    assert(enumeratedCases >= 9000, `exhaustive enumeration ran (${enumeratedCases} cases)`);
    console.log('#204 shipped client: PHP selector/action parity, transport, saved counters/rows/order, filter/page change, Apply/Undo, focus/stale wording, pager, text safety, hidden/terminal stop, timeout/out-of-order, session/error backoff, exhaustive order/focus reconciliation PASS (' + enumeratedCases + ' enumerated cases, ' + enumeratedAssertions + ' assertion checks, ' + deferredCases + ' focused-removed deferrals, 0 violations)');
})().catch(error => { console.error(error); process.exitCode = 1; });
