/* Executes the shipped client with deterministic DOM/transport/timer boundaries.
 * This is client logic coverage, not a WooCommerce or accessibility browser pass. */
'use strict';
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../writeleash/includes/free/free-progress.js'), 'utf8');
class Element {
    constructor(tag = 'P') { this.tagName = tag.toUpperCase(); this.children = []; this.textContent = ''; this.attrs = {}; this.events = {}; this.hidden = false; }
    appendChild(child) { this.children.push(child); child.parent = this; return child; }
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
function harness(withMount = true) {
    const root = new Element('DIV'), body = new Element('TBODY');
    const nodes = {};
    ['connection', 'announcement', 'label', 'summary', 'notice', 'undo-label', 'undo-summary', 'undo-notice', 'resume', 'undo'].forEach(name => nodes[`[data-progress-${name}]`] = new Element());
    root.attrs = {'data-job': 'job-204', 'data-nonce': 'nonce-204', 'data-offset': '0', 'data-filter': '', 'data-endpoint': '/ajax', 'data-action-url': '/post'};
    root.querySelector = selector => selector === '[data-writeleash-results] tbody' ? body : nodes[selector] || null;
    root.querySelectorAll = () => [];
    const document = new Element(); document.hidden = false; document.activeElement = new Element('A');
    document.querySelector = () => withMount ? root : null; document.createElement = tag => new Element(tag);
    const requests = [], timers = new Map(); let next = 0;
    const pager = new Element('DIV'), paragraph = new Element('P'), previous = new Element('A'), nextLink = new Element('A');
    paragraph.appendChild(previous); paragraph.appendChild(nextLink);
    Object.defineProperty(paragraph, 'lastElementChild', {get: () => paragraph.children.at(-1)}); pager.querySelector = () => paragraph; nodes['[data-progress-pager]'] = pager;
    const window = new Element(); window.URLSearchParams = URLSearchParams; window.AbortController = AbortController;
    window.setTimeout = (fn, delay) => { timers.set(++next, {fn, delay}); return next; }; window.clearTimeout = id => timers.delete(id);
    window.fetch = (url, opts) => new Promise((resolve, reject) => requests.push({url, opts, resolve, reject}));
    vm.runInNewContext(source, {window, document, Date, Error, Array, String, Math});
    return {nodes, root, body, document, window, requests, timers, nextLink,
        timer(delay) { const entry = [...timers.entries()].find(([, x]) => x.delay === delay); assert(entry, `timer ${delay}`); timers.delete(entry[0]); entry[1].fn(); }};
}
const flush = async () => { for (let n = 0; n < 8; n++) { await Promise.resolve(); } };
function snapshot(overrides = {}) {
    return {status: 'OK', label: 'In progress', summary: '1 changed · 2 remaining', notice: '', undo_label: 'Undo unavailable', undo_summary: '0 restored', undo_notice: '', next_url: '/next', resume_available: true, undo_available: false, resume_nonce: 'bound-resume', undo_nonce: null, undo_button: 'Continue Undo', poll: true, rows: [{id: 4, name: '<script>literal</script>', expected: '10 USD', planned: '20 USD', apply: 'Changed', undo: 'Not started', apply_attention: false, undo_attention: false}], ...overrides};
}
async function respond(h, index, data) { h.requests[index].resolve({ok: true, status: 200, json: async () => ({success: true, data})}); await flush(); }
(async () => {
    let h = harness(); assert.equal(h.requests.length, 1); assert.equal(h.requests[0].opts.method, 'POST');
    assert.equal(h.requests[0].opts.body.get('action'), 'writeleash_free_progress');
    assert.equal(h.requests[0].opts.credentials, 'same-origin'); assert.equal(h.requests[0].opts.cache, 'no-store');
    await respond(h, 0, snapshot());
    assert.equal(h.nodes['[data-progress-summary]'].textContent, '1 changed · 2 remaining');
    assert.equal(h.body.children[0].children[0].textContent, '<script>literal</script>');
    assert.equal(h.body.children[0].children[2].textContent, 'Reload to read the current price');
    assert.match(h.nodes['[data-progress-connection]'].textContent, /Last successful refresh/);
    const button = h.nodes['[data-progress-resume]'].querySelector('button');
    assert(button); assert.equal(button.type, 'submit');
    const form = h.nodes['[data-progress-resume]'].children[0]; assert.equal(form.action, '/post');
    assert.equal(form.children[2].value, 'bound-resume');
    h.document.activeElement = button;
    h.timer(5000); await respond(h, 1, snapshot({label: 'Finished with products needing attention', summary: '1 changed · 1 conflict · 1 uncertain', resume_available: false, undo_available: true, undo_nonce: 'bound-undo', poll: false, rows: [{...snapshot().rows[0], apply: 'Outcome uncertain; needs checking', apply_attention: true}]}));
    assert.equal(h.document.activeElement, button); assert.equal(button.disabled, true); assert.equal(h.nodes['[data-progress-resume]'].hidden, false);
    h.document.activeElement = new Element('A'); h.nodes['[data-progress-resume]'].dispatch('focusout'); assert.equal(h.nodes['[data-progress-resume]'].hidden, true);
    assert.equal(h.nodes['[data-progress-undo]'].querySelector('button').textContent, 'Continue Undo');
    assert.equal(h.body.children[0].children[4].className, 'writeleash-attention'); assert.equal([...h.timers.values()].filter(x => x.delay === 5000).length, 0);
    assert.match(h.nodes['[data-progress-announcement]'].textContent, /1 uncertain/);
    h = harness(); await respond(h, 0, snapshot());
    const cell = h.body.children[0].children[4], focusedSummary = new Element('SUMMARY'); cell.appendChild(focusedSummary); h.document.activeElement = focusedSummary;
    h.timer(5000); await respond(h, 1, snapshot({rows: [{...snapshot().rows[0], apply: 'New saved outcome'}]}));
    assert.equal(h.document.activeElement, focusedSummary); assert.equal(cell.textContent, 'Changed'); assert.match(h.nodes['[data-progress-connection]'].textContent, /Leave the focused outcome/);
    h.document.activeElement = h.nextLink; h.timer(5000); await respond(h, 2, snapshot({next_url: null}));
    assert.equal(cell.textContent, 'Changed'); assert.equal(h.document.activeElement, h.nextLink); assert.equal(h.nextLink.hidden, false); assert.equal(h.nextLink.getAttribute('aria-disabled'), 'true');
    let blocked = false; h.nextLink.dispatch('click', {preventDefault() { blocked = true; }}); assert.equal(blocked, true);
    h.document.activeElement = new Element('A'); h.nextLink.dispatch('blur'); assert.equal(h.nextLink.hidden, true);
    h = harness(); let announcements = 0, announced = ''; Object.defineProperty(h.nodes['[data-progress-announcement]'], 'textContent', {get: () => announced, set: value => {announcements++; announced = value;}});
    await respond(h, 0, snapshot()); h.timer(5000); await respond(h, 1, snapshot()); assert.equal(announcements, 1, 'unchanged counters not announced repeatedly');
    h = harness(); const support = new Element('DETAILS'), supportFocus = new Element('SUMMARY'); support.appendChild(supportFocus); h.root.querySelectorAll = () => [support]; h.document.activeElement = supportFocus;
    await respond(h, 0, snapshot()); assert.equal(support.hidden, false); assert.equal(h.document.activeElement, supportFocus);
    h.document.activeElement = new Element('A'); support.dispatch('focusout'); assert.equal(support.hidden, true);
    h = harness(); h.document.hidden = true; h.document.dispatch('visibilitychange'); assert.equal(h.requests[0].opts.signal.aborted, true);
    await respond(h, 0, snapshot({label: 'Old hidden observation'})); assert.equal(h.nodes['[data-progress-label]'].textContent, '');
    h.document.hidden = false; h.document.dispatch('visibilitychange'); assert.equal(h.requests.length, 2);
    await respond(h, 1, snapshot({label: 'Undo in progress', undo_summary: '1 restored · 2 remaining'})); assert.equal(h.nodes['[data-progress-undo-summary]'].textContent, '1 restored · 2 remaining');
    h.document.hidden = true; h.document.dispatch('visibilitychange'); assert.equal([...h.timers.values()].length, 0);
    h = harness(); h.timer(15000); assert.equal(h.requests[0].opts.signal.aborted, true); h.timer(10000);
    await respond(h, 1, snapshot({label: 'New observation'})); await respond(h, 0, snapshot({label: 'Old delayed observation'})); assert.equal(h.nodes['[data-progress-label]'].textContent, 'New observation');
    h = harness(); h.requests[0].resolve({ok: false, status: 403}); await flush(); assert.match(h.nodes['[data-progress-connection]'].textContent, /expired.*Reload and sign in/); assert.equal(h.timers.size, 0);
    h = harness();
    for (let i = 0; i < 4; i++) { h.requests[i].reject(new Error('network failed')); await flush(); if (i < 3) { h.timer([10000, 20000, 40000][i]); } }
    assert.match(h.nodes['[data-progress-connection]'].textContent, /stopped after repeated errors/); assert.equal(h.timers.size, 0);
    h = harness(); h.document.activeElement = new Element('A'); h.window.dispatch('pagehide'); await respond(h, 0, snapshot()); assert.equal(h.nodes['[data-progress-label]'].textContent, '');
    assert.equal(harness(false).requests.length, 0);
    console.log('#204 shipped client: transport, saved counters/rows, Apply/Undo, focus, text safety, hidden/terminal stop, timeout/out-of-order, session/error backoff PASS');
})().catch(error => { console.error(error); process.exitCode = 1; });
