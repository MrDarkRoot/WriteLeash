// #170 real-browser gate. Reuse the disposable Admin runner and real Woo/SQL fixture.
const pw = require(process.env.WL167_PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');
const base = process.env.WL167_BASE_URL, site = process.env.WL167_SITE, file = process.env.WL170_FIXTURE;
if (!base || !site || !file) throw new Error('Explicit disposable #170 site required');
const f = JSON.parse(fs.readFileSync(file));
const engine = process.env.WL170_BROWSER || 'chromium';
const home = base + '/wp-admin/admin.php?page=writeleash-bulk-prices';
const out = process.env.WL167_EVIDENCE;
const scans = [], evidence = [], safety = {};
let checks = 0;
function ok(value, label) { assert.ok(value, label); checks++; }
function fixture(mode = 'observe') {
    const args = process.env.WL167_CONTAINER
        ? ['exec', '-e', 'WL170_MODE=' + mode, '-e', 'WL170_FIXTURE=/tmp/wl170-fixture.json', process.env.WL167_CONTAINER, 'wp', '--path=' + site, 'eval-file', '/opt/tests/admin/regression-browser-fixture.php']
        : ['--path=' + site, 'eval-file', __dirname + '/regression-browser-fixture.php'];
    return JSON.parse(execFileSync(process.env.WL167_CONTAINER ? 'docker' : 'wp', args, { env: { ...process.env, WL170_MODE: mode }, encoding: 'utf8' }));
}
function durable(o) { return JSON.stringify({ prices: o.prices, jobs: o.jobs, items: o.items, undo: o.undo, saves: o.saves }); }
async function text(page) { return page.locator('.writeleash-admin').innerText(); }
async function login(page, who = 'manager') {
    await page.goto(base + '/wp-login.php');
    await page.waitForLoadState('networkidle');
    await page.getByLabel('Username or Email Address').fill(f.users[who].username);
    await page.getByLabel('Password', { exact: true }).fill(f.users[who].password);
    await Promise.all([page.waitForEvent('framenavigated', { predicate: frame => frame === page.mainFrame() }), page.getByRole('button', { name: 'Log In', exact: true }).click()]);
}
async function tabTo(page, target) {
    for (let i = 0; i < 200; i++) {
        if (await target.evaluate(el => document.activeElement === el)) {
            ok(await target.evaluate(el => { const s = getComputedStyle(el); const widget = el.closest('.select2-container'); const boundary = widget && widget.querySelector('.select2-selection'); return s.outlineStyle !== 'none' || s.boxShadow !== 'none' || (boundary && widget.classList.contains('select2-container--focus') && (getComputedStyle(boundary).boxShadow !== 'none' || getComputedStyle(boundary).outlineStyle !== 'none')); }), 'visible keyboard focus');
            return;
        }
        const backwards = await target.evaluate(el => !!(el.compareDocumentPosition(document.activeElement) & Node.DOCUMENT_POSITION_FOLLOWING));
        await page.keyboard.press(backwards ? 'Shift+Tab' : 'Tab');
        await page.waitForTimeout(20); // Native focus events settle asynchronously in Firefox.
    }
    throw new Error('Keyboard target unreachable/trapped: ' + await target.toString());
}
async function enter(page, target, navigates = true) {
    await tabTo(page, target);
    if (navigates) await Promise.all([page.waitForEvent('framenavigated', { predicate: frame => frame === page.mainFrame() }), page.keyboard.press('Enter')]);
    else await page.keyboard.press('Enter');
    await page.waitForLoadState('networkidle');
}
function button(page, name) { return page.getByRole('button', { name, exact: true }); }
function link(page, name) { return page.getByRole('link', { name, exact: true }); }
async function search(page, term) {
    const field = page.locator('#writeleash-free-products + .select2-container .select2-search__field');
    // SelectWoo can drop a query keyed before its dropdown settles. Type once,
    // wait on this query's own rows, and retry once before failing.
    for (let attempt = 0; attempt < 2; attempt++) {
        await tabTo(page, field); await page.keyboard.press('ControlOrMeta+A');
        const response = page.waitForResponse(r => { const u = new URL(r.url()); return u.searchParams.get('action') === 'writeleash_free_discovery' && u.searchParams.get('term') === term; });
        await page.keyboard.type(term, { delay: 20 });
        assert.equal(await field.inputValue(), term);
        try {
            const data = await (await response).json();
            await page.locator('.select2-results__option[data-selected]').first().waitFor({ timeout: 15000 });
            ok(data.success && data.data.results.length <= 20, 'bounded real discovery response');
            return;
        } catch (error) {
            if (attempt === 0) { console.error('Discovery query retried after a dropped/stale response: ' + term); continue; }
            throw error;
        }
    }
}
async function form(page, action) {
    return page.locator('form').filter({ has: page.locator('input[name="action"][value="' + action + '"]') }).evaluate(el => Object.fromEntries(new FormData(el)));
}
async function scan(page, name) {
    await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') });
    const result = await page.evaluate(async () => axe.run({ include: [['.writeleash-admin'], ...(document.querySelector('.select2-dropdown') ? [['.select2-dropdown']] : [])] }));
    const violations = result.violations.map(v => ({ id: v.id, impact: v.impact, targets: v.nodes.map(n => n.target) }));
    scans.push({ name, violations });
    ok(!violations.some(v => ['serious', 'critical'].includes(v.impact)), name + ': no serious/critical axe violations: ' + JSON.stringify(violations));
}
async function capture(page, name) {
    if (!out) return;
    fs.mkdirSync(out, { recursive: true });
    await page.evaluate(() => { document.activeElement.blur(); scrollTo(0, 0); });
    const filename = '170-' + engine + '-' + name + '.png';
    await page.screenshot({ path: out + '/' + filename, fullPage: true });
    evidence.push({ name, filename });
}
async function responsive(page) {
    for (const width of [1440, 1024, 782, 375]) {
        await page.setViewportSize({ width, height: 900 });
        ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'no page overflow at ' + width);
        for (const region of await page.locator('.writeleash-table-scroll').all()) {
            await tabTo(page, region);
            if (await region.evaluate(el => el.scrollWidth > el.clientWidth)) {
                await page.keyboard.press('ArrowRight');
                await page.waitForFunction(el => el.scrollLeft > 0, await region.elementHandle());
                ok(await region.evaluate(el => el.scrollLeft > 0), 'keyboard table scroll');
            }
            await page.keyboard.press('Tab'); await page.keyboard.press('Shift+Tab');
            ok(await region.evaluate(el => document.activeElement === el), 'table has no keyboard trap');
        }
        for (const action of await page.locator('.writeleash-admin .button-primary, .writeleash-undo button').all()) {
            const box = await action.boundingBox();
            ok(box && box.x >= 0 && box.x + box.width <= width + 1, 'primary/restore action not clipped');
        }
    }
    await page.setViewportSize({ width: 1440, height: 900 });
}
(async () => {
    const browser = await pw[engine].launch({ headless: true, ...(process.env.WL170_EXECUTABLE ? { executablePath: process.env.WL170_EXECUTABLE } : (engine === 'chromium' ? { executablePath: process.env.WL167_CHROME || '/usr/bin/google-chrome' } : {})) });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, acceptDownloads: true });
    let missing = false, older = false;
    try {
        let page = await context.newPage();
        await login(page); await page.goto(home);
        ok((await text(page)).includes('Bulk Prices') || await page.getByRole('heading', { name: 'WriteLeash Bulk Prices' }).count() === 1, 'WriteLeash opened');
        await scan(page, 'configuration'); await responsive(page);
        ok(await page.getByRole('combobox', { name: 'Choose products', exact: true }).count() === 1, 'product picker named');
        const category = page.locator('#writeleash-free-category + .select2-container .select2-selection');
        await tabTo(page, category); await page.keyboard.press('ArrowDown');
        await page.locator('.select2-dropdown .select2-results__option[data-selected]').first().waitFor();
        await scan(page, 'category search'); await page.keyboard.press('Escape');
        const picker = page.locator('#writeleash-free-products + .select2-container .select2-search__field');
        await search(page, f.skus[0].slice(0,-2)); await page.keyboard.press('Escape');
        const beforeNetwork = durable(fixture());
        await page.route('**/admin-ajax.php*', async route => {
            const u = new URL(route.request().url());
            if (u.searchParams.get('term') !== f.skus[0]) return route.continue();
            const response = await route.fetch(); await new Promise(r => setTimeout(r, 800));
            try { await route.fulfill({ response }); } catch (_) { /* stale request aborted by SelectWoo */ }
        });
        await tabTo(page, picker); await page.keyboard.type(f.skus[0], { delay: 20 });
        await page.waitForTimeout(400); await search(page, f.skus[1]); await page.waitForTimeout(900);
        ok((await page.locator('.select2-results__option[data-selected]').allTextContents()).every(t => t.includes(f.skus[1])), 'out-of-order response never replaces new search');
        await page.unroute('**/admin-ajax.php*'); await page.keyboard.press('Escape');
        await page.route('**/admin-ajax.php*', r => r.abort('timedout'));
        await tabTo(page, picker); await page.keyboard.press('ControlOrMeta+A'); await page.keyboard.type('failed request', { delay: 20 });
        await page.getByText('Search is unavailable. Try again, reload if your session expired, or use the native search below.', { exact: true }).waitFor();
        const networkSelected = await page.locator('#writeleash-free-selected button').count();
        const networkAfter = durable(fixture());
        if (0 !== networkSelected || networkAfter !== beforeNetwork) {
            console.error('Network-failure diagnostics: ' + JSON.stringify({ selected: networkSelected, chosenText: await page.locator('#writeleash-free-selected').innerText(), before: JSON.parse(beforeNetwork), after: JSON.parse(networkAfter) }));
            await capture(page, 'network-failure');
        }
        ok(0 === networkSelected && networkAfter === beforeNetwork, 'network failure has no phantom selection or mutation');
        await scan(page, 'search failure'); await page.unroute('**/admin-ajax.php*');
        // Same real server form works with the enhancement entirely unavailable.
        const native = await browser.newContext({ javaScriptEnabled: false, storageState: await context.storageState() });
        const np = await native.newPage(); await np.goto(home);
        await tabTo(np, np.locator('#writeleash-free-product_search')); await np.keyboard.type(f.skus[0]);
        await enter(np, button(np, 'Search products'));
        await tabTo(np, np.locator('#writeleash-free-products')); await np.keyboard.press('Home');
        await enter(np, button(np, 'Update selected products'));
        ok(await np.locator('#writeleash-free-selected button').count() === 1 && durable(fixture()) === beforeNetwork, 'keyboard native fallback selects actual product without mutation');
        await native.close(); await page.goto(home);
        for (let i = 0; i < f.skus.length; i++) {
            await search(page, f.skus[i]);
            if (!i) { await scan(page, 'open search'); await page.keyboard.press('Escape'); ok(await picker.evaluate(el => document.activeElement === el), 'Escape retains sensible search focus'); await search(page, f.skus[i]); }
            await page.keyboard.press('ArrowDown'); await page.keyboard.press('Enter');
            ok(await page.locator('#writeleash-free-selected button').count() === i + 1, 'keyboard selection exact population');
        }
        const remove = page.locator('#writeleash-free-selected button').last();
        ok((await remove.getAttribute('aria-label')).startsWith('Remove Gate'), 'removal accessible name includes identity');
        await enter(page, remove, false);
        ok(await picker.evaluate(el => document.activeElement === el), 'removal returns focus to search');
        await search(page, f.skus.at(-1)); await page.keyboard.press('ArrowDown'); await page.keyboard.press('Enter');
        await tabTo(page, page.locator('#writeleash-free-operation'));
        await page.keyboard.press('End'); // Last native option = decrease percent.
        await tabTo(page, page.locator('#writeleash-free-amount')); await page.keyboard.type('bad-price');
        await enter(page, button(page, 'Preview price changes'));
        ok((await page.locator('#writeleash-free-amount').inputValue()) === 'bad-price' && await page.locator('#writeleash-free-selected button').count() === 22, 'validation retains selection and input');
        await scan(page, 'validation error');
        await tabTo(page, page.locator('#writeleash-free-amount')); await page.keyboard.press('ControlOrMeta+A'); await page.keyboard.type('20');
        await enter(page, button(page, 'Preview price changes'));
        const previewURL = page.url(); const publicId = new URL(previewURL).searchParams.get('wl_job');
        let observed = fixture(); const job = observed.jobs.find(j => j.public_id === publicId);
        const frozen = { json: job.plan_json, hash: job.plan_hash };
        ok(JSON.parse(job.plan_json).resolved_product_ids.join() === [...f.ids].sort((a,b)=>a-b).join(), 'independent frozen population');
        ok(observed.saves.length === 0 || Object.values(observed.saves).every(n => n === 0), 'preview has no Woo saves');
        await scan(page, 'preview'); await capture(page, 'preview'); await responsive(page);
        await enter(page, link(page, 'Next page')); ok(await page.locator('.writeleash-admin tbody tr').count() === 2, 'review paging');
        await enter(page, link(page, 'Previous page'));
        await page.reload(); await page.goBack(); await page.goto(previewURL);
        await page.close(); page = await context.newPage(); await page.goto(home);
        await enter(page, link(page, 'Continue review').first()); ok(page.url() === previewURL, 'closed preview reopens saved identity');
        fixture('edit-apply'); await page.reload();
        const approval = await form(page, 'writeleash_free_approve');
        let approvalForwarded; const forwarded = new Promise(resolve => { approvalForwarded = resolve; });
        await page.route('**/admin-post.php', async route => {
            await route.fetch({ maxRedirects: 0 }); // Real server approval commits; browser receives no response.
            await route.abort('failed'); approvalForwarded();
        });
        await tabTo(page, button(page, 'Approve and apply')); await page.keyboard.press('Enter');
        await forwarded;
        await page.unroute('**/admin-post.php');
        const jobURL = home + '&wl_view=job&wl_job=' + publicId;
        await page.goto(jobURL); ok((await text(page)).includes('22 remaining'), 'lost approval response recovers queued durable truth');
        await Promise.all([context.request.post(base + '/wp-admin/admin-post.php', { form: approval }), context.request.post(base + '/wp-admin/admin-post.php', { form: approval })]);
        observed = fixture(); let current = observed.jobs.find(j => j.public_id === publicId);
        ok(current.plan_json === frozen.json && current.plan_hash === frozen.hash && current.applied === '0', 'duplicate approval neither replans nor applies');
        await page.reload(); await scan(page, 'queued');
        await enter(page, button(page, 'Resume remaining products'));
        ok((await text(page)).includes('12 remaining'), 'partial chunk is durable');
        await page.reload(); await page.close(); page = await context.newPage(); await page.goto(jobURL);
        ok((await text(page)).includes('12 remaining'), 'partial job reopens without mutation');
        const resume = await form(page, 'writeleash_free_resume');
        await Promise.all([context.request.post(base + '/wp-admin/admin-post.php', { form: resume }), context.request.post(base + '/wp-admin/admin-post.php', { form: resume })]);
        await page.reload(); if (await button(page, 'Resume remaining products').count()) await enter(page, button(page, 'Resume remaining products'));
        observed = fixture();
        ok(observed.prices[f.ids[0]] === '120.00' && f.ids.slice(1).every(id => Number(observed.prices[id]) === 80), 'independent prices: stale preserved, percent applied exactly once');
        ok(observed.items[publicId].filter(i => i.state === 'APPLIED').length === 21 && observed.items[publicId][0].state === 'CONFLICT', 'durable disjoint Apply outcomes');
        ok(f.ids.slice(1).every(id => observed.saves[id] === 1), 'duplicate Resume performs only one Woo save per changed product');
        const row = page.locator('tr[data-product-id="' + f.ids[0] + '"]');
        assert.deepEqual((await row.locator('td').allTextContents()).slice(1,4), ['$100.00 USD', '$120.00 USD', '$80.00 USD']);
        ok((await row.innerText()).includes('left the newer value unchanged'), 'readable Apply preservation');
        await scan(page, 'mixed results'); await capture(page, 'apply-conflict');
        safety.apply = { expected: '100.00', current: observed.prices[f.ids[0]], planned: '80.00', state: observed.items[publicId][0].state, html: await row.evaluate(el => el.outerHTML), product_id: f.ids[0] };
        await responsive(page);
        const beforeCsv = durable(fixture()); const downloadEvent = page.waitForEvent('download');
        await tabTo(page, button(page, 'Download job CSV')); await page.keyboard.press('Enter');
        const csv = fs.readFileSync(await (await downloadEvent).path(), 'utf8');
        ok(csv.includes('ITEM_CONFLICT') && csv.includes('80.00') && durable(fixture()) === beforeCsv, 'keyboard CSV is real saved evidence without mutation');
        await enter(page, link(page, 'Open history')); await scan(page, 'history'); await capture(page, 'history'); await responsive(page);
        await enter(page, link(page, 'Open progress/results').first());
        fixture('edit-undo'); await page.reload();
        await enter(page, button(page, 'Restore eligible prices (Undo)'));
        if (await button(page, 'Continue Undo').count()) await enter(page, button(page, 'Continue Undo'));
        if (await button(page, 'Continue Undo').count()) await enter(page, button(page, 'Continue Undo'));
        observed = fixture();
        ok(observed.prices[f.ids[0]] === '120.00' && observed.prices[f.ids[1]] === '93.00' && f.ids.slice(2).every(id => Number(observed.prices[id]) === 100), 'independent Undo preserves external edits and restores eligible values');
        ok(observed.undo[publicId].filter(i => i.state === 'UNDO_CONFLICT').length === 1, 'durable Undo conflict recorded');
        ok((await text(page)).includes('preserved the newer value instead of restoring over it'), 'readable Undo preservation');
        safety.undo = { preserved: observed.prices[f.ids[1]], restored: f.ids.slice(2).length, conflict: 1 };
        await scan(page, 'Undo results'); await capture(page, 'undo-results'); await responsive(page);
        const unchanged = durable(observed);
        for (const action of ['writeleash_free_approve','writeleash_free_resume','writeleash_free_undo','writeleash_free_export']) {
            await context.request.get(base + '/wp-admin/admin-post.php', { params: { action, job: publicId }, maxRedirects: 0 });
            await context.request.post(base + '/wp-admin/admin-post.php', { form: { action, job: publicId, _wpnonce: 'wrong' }, maxRedirects: 0 });
            ok(durable(fixture()) === unchanged, 'GET/wrong nonce: ' + action + ' has no mutation');
        }
        await page.goto(jobURL); ok((await text(page)).includes('security token'), 'wrong nonce recovery is visible'); await scan(page, 'failed action');
        // Authenticated browser contexts exercise denial, including per-product authority.
        for (const who of ['subscriber','editor','other']) {
            const c = await browser.newContext(); const denied = await c.newPage(); await login(denied, who);
            await denied.goto(jobURL);
            ok(!(await denied.locator('body').innerText()).includes(f.skus[0]), who + ' cannot read another actor job');
            await denied.goto(home);
            if (who === 'subscriber') { ok((await denied.locator('body').innerText()).includes('not allowed'), 'Subscriber page denied'); }
            else {
                const post = await form(denied, 'writeleash_free_preview');
                if (who === 'editor') {
                    const discovery = await denied.locator('#writeleash-free-selection-form').getAttribute('data-discovery-nonce');
                    const r = await c.request.get(base + '/wp-admin/admin-ajax.php', { params: { action: 'writeleash_free_discovery', nonce: discovery, kind: 'products', term: f.skus[0] } });
                    ok((await r.json()).data.results.length === 0, 'unauthorized editor search leaks no product');
                    await c.request.post(base + '/wp-admin/admin-post.php', { form: { ...post, selector: 'manual_ids', ids: String(f.ids[0]), amount: '80' } });
                } else {
                    const r = await c.request.post(base + '/wp-admin/admin-post.php', { form: { action: 'writeleash_free_export', job: publicId, _wpnonce: post._wpnonce } });
                    ok(!(r.headers()['content-type'] || '').includes('text/csv'), 'other Manager cannot export');
                    await denied.goto(home + '&wl_view=history'); ok(!(await text(denied)).includes(f.skus[0]), 'other Manager history scoped');
                }
            }
            ok(durable(fixture()) === unchanged, who + ': independent no mutation'); await c.close();
        }
        fixture('revoke'); await page.goto(home);
        ok((await text(page)).includes('cannot preview or apply'), 'removed permission recovery message');
        const r = await context.request.get(base + '/wp-admin/admin-ajax.php', { params: { action: 'writeleash_free_discovery', nonce: 'wrong', term: f.skus[0] } });
        ok(r.status() === 403 && !(await r.text()).includes(f.skus[0]), 'removed permission search denied');
        fixture('restore-rights'); fixture('short-nonce'); await page.goto(home);
        const expired = await form(page, 'writeleash_free_preview'); await page.waitForTimeout(5000);
        await context.request.post(base + '/wp-admin/admin-post.php', { form: { ...expired, selector: 'manual_ids', ids: String(f.ids[0]), amount: '80' }, maxRedirects: 0 });
        fixture('normal-nonce'); await page.goto(home);
        ok((await text(page)).includes('security token') && durable(fixture()) === unchanged, 'actual expired nonce refuses mutation and gives recovery');
        fixture('expire-session'); await page.goto(jobURL);
        ok(page.url().includes('wp-login.php') && durable(fixture()) === unchanged, 'expired real session denies job');
        await login(page); await page.goto(jobURL);
        fixture('missing-woo'); missing = true; await page.reload();
        ok((await text(page)).includes('WooCommerce') && durable(fixture()) === unchanged, 'missing dependency message preserves durable work'); await scan(page, 'missing dependency');
        fixture('restore-woo'); missing = false; await page.reload();
        ok((await text(page)).includes('Undo finished'), 'reactivated Woo recovers saved job');
        // #180 supports the whole 10.x-11.x range, so the real 11.0.1 package is
        // an in-range install: the saved job must stay reachable and unchanged.
        fixture('older-woo'); older = true; await page.reload();
        ok((await text(page)).includes('Undo finished') && durable(fixture()) === unchanged, 'older in-range Woo 11.0.1 keeps saved work without mutation');
        fixture('restore-version'); older = false; await page.reload();
        ok((await text(page)).includes('Undo finished'), 'supported Woo restored without lost work');
        const reads = fixture().reads; ok(!reads.unbounded && !reads.oversized && reads.search <= 11 && reads.selected <= 100, 'bounded catalog windows throughout browser journey');
        safety.negatives = { no_mutation: durable(fixture()) === unchanged, reads };
        if (out) fs.writeFileSync(out + '/170-' + engine + '-result.json', JSON.stringify({ engine, version: await browser.version(), checks, scans, safety, evidence, viewport: '1440/1024/782/375', keyboard: 'PASS', screen_reader: 'NOT_TESTED', safari: 'NOT_TESTED' }, null, 2));
        console.log('#170 ' + engine + ' integrated keyboard/accessibility/safety: PASS (' + checks + ' assertions); version=' + await browser.version());
    } finally {
        fixture('normal-nonce'); fixture('restore-rights');
        if (missing) fixture('restore-woo'); if (older) fixture('restore-version');
        await context.close(); await browser.close();
    }
})().catch(e => { console.error(e.stack); process.exitCode = 1; });
