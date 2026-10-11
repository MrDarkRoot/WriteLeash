// #230 integrated range/preset/refinement journey on the real #170 Woo fixture.
const pw = require(process.env.WL167_PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');
const site = process.env.WL167_SITE, base = process.env.WL167_BASE_URL;
const f = JSON.parse(fs.readFileSync(process.env.WL170_FIXTURE));
const engine = process.env.WL170_BROWSER || 'firefox';
const home = base + '/wp-admin/admin.php?page=writeleash-bulk-prices';
const out = process.env.WL167_EVIDENCE;
let checks = 0;
const scans = [];
function ok(value, label) { assert.ok(value, label); checks++; }
function observe() {
    const args = process.env.WL167_CONTAINER
        ? ['exec', '-e', 'WL170_MODE=observe', '-e', 'WL170_FIXTURE=/tmp/wl170-fixture.json', process.env.WL167_CONTAINER, 'wp', '--path=' + site, 'eval-file', '/opt/tests/admin/regression-browser-fixture.php']
        : ['--path=' + site, 'eval-file', __dirname + '/regression-browser-fixture.php'];
    return JSON.parse(execFileSync(process.env.WL167_CONTAINER ? 'docker' : 'wp', args, { env: { ...process.env, WL170_MODE: 'observe' }, encoding: 'utf8' }));
}
async function tab(page, target) {
    for (let n = 0; n < 250; n++) {
        if (await target.evaluate(el => document.activeElement === el)) {
            ok(await target.evaluate(el => { const s = getComputedStyle(el); return s.outlineStyle !== 'none' || s.boxShadow !== 'none'; }), 'visible keyboard focus');
            return;
        }
        const backward = await target.evaluate(el => !!(el.compareDocumentPosition(document.activeElement) & Node.DOCUMENT_POSITION_FOLLOWING));
        await page.keyboard.press(backward ? 'Shift+Tab' : 'Tab');
    }
    throw new Error('Preset keyboard target unreachable');
}
async function enter(page, target) {
    await tab(page, target);
    await Promise.all([page.waitForEvent('framenavigated', { predicate: frame => frame === page.mainFrame() }), page.keyboard.press('Enter')]);
    await page.waitForLoadState('networkidle');
}
const button = (page, name) => page.getByRole('button', { name, exact: true });
async function capture(page, name) {
    if (!out) return;
    fs.mkdirSync(out, { recursive: true });
    await page.screenshot({ path: out + '/230-' + engine + '-' + name + '.png', fullPage: true });
}
async function scan(page, name) {
    await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') });
    const result = await page.evaluate(() => axe.run({ include: [['.writeleash-admin']] }));
    const violations = result.violations.map(v => ({ id: v.id, impact: v.impact, targets: v.nodes.map(n => n.target) }));
    scans.push({ name, violations });
    ok(!violations.some(v => ['serious', 'critical'].includes(v.impact)), 'preset axe ' + name + ': ' + JSON.stringify(violations));
}
async function combined241(page, javaScriptEnabled) {
    await page.goto(home);
    const ids = f.ids.slice(javaScriptEnabled ? 2 : 4, javaScriptEnabled ? 4 : 6);
    const before = observe();
    await page.locator('#writeleash-free-selector').selectOption('manual_ids');
    const summary = page.locator('#writeleash-free-advanced-selection > summary');
    if (!await summary.evaluate(el => el.parentElement.open)) { await tab(page, summary); await page.keyboard.press('Enter'); }
    await page.locator('#writeleash-free-ids').fill(ids.join(','));
    await page.locator('#writeleash-free-operation').selectOption('INCREASE_PERCENT');
    await page.locator('#writeleash-free-amount').fill('5');
    await page.locator('#writeleash-free-range-enabled').check();
    await page.locator('#writeleash-free-range_min').fill('100');
    await page.locator('#writeleash-free-range_max').fill('150');
    await page.locator('#writeleash-preset-name').fill('Combined History preset');
    await enter(page, button(page, 'Save as preset'));
    await enter(page, button(page, 'Load preset'));
    assert.deepEqual(observe().jobs, before.jobs, 'combined Save/Load does not create Preview');
    await enter(page, button(page, 'Preview price changes'));
    const sourceId = new URL(page.url()).searchParams.get('wl_job');
    await enter(page, button(page, 'Approve and apply'));
    for (let n = 0; n < 4 && await button(page, 'Resume remaining products').count(); n++) await enter(page, button(page, 'Resume remaining products'));
    const source = observe().jobs.find(j => j.public_id === sourceId);
    ok(ids.every(id => Number(observe().prices[id]) === 105), 'combined preset operation explicitly applied');
    await enter(page, page.getByRole('link', { name: 'Open history', exact: true }));
    await enter(page, page.locator('a[href*="wl_view=repeat"][href*="wl_job=' + sourceId + '"]').first());
    const form = page.locator('#writeleash-free-selection-form');
    ok(await form.locator('form').count() === 0, 'no nested Repeat form');
    ok(await form.locator('input[name="source_job"]').count() === 1 && await form.locator('input[name="repeat"]').count() === 1, 'exactly one historical workflow marker pair');
    ok(await button(page, 'Save as preset').count() === 0 && await form.locator('input[name="preset_nonce"]').count() === 0, 'Repeat carries no preset action/nonce');
    ok(await page.locator('#writeleash-free-amount').inputValue() === '5', 'Repeat operation prefill');
    ok(await page.locator('#writeleash-free-range-enabled').isChecked(), 'Repeat range prefill');
    // Exercise no-JS discovery and validation retention, including an unchecked
    // historical range. Both actions must stay in Repeat, not return to home.
    await page.locator('#writeleash-free-range-enabled').uncheck();
    await page.locator('#writeleash-free-amount').fill('bad-amount');
    await enter(page, button(page, 'Check selection count'));
    ok(await page.getByRole('heading', { name: 'Repeat as new Preview', exact: true }).count() === 1, 'native count stays in active Repeat view');
    ok(await page.locator('input[name="repeat"]').inputValue() === '1', 'native count retains Repeat marker');
    await enter(page, button(page, 'Preview price changes'));
    ok(new URL(page.url()).searchParams.get('wl_view') === 'repeat', 'invalid Repeat returns to Repeat');
    ok(await page.locator('#writeleash-free-amount').inputValue() === 'bad-amount', 'invalid Repeat keeps edited amount');
    ok(!await page.locator('#writeleash-free-range-enabled').isChecked(), 'unchecked historical range stays disabled after error');
    await page.evaluate(() => { document.activeElement.blur(); scrollTo(0, 0); });
    await capture(page, javaScriptEnabled ? 'combined-repeat-form' : 'combined-nojs-repeat-form');
    if (javaScriptEnabled) await scan(page, 'combined Repeat form');
    await page.locator('#writeleash-free-amount').fill('5');
    await enter(page, button(page, 'Preview price changes'));
    const nextId = new URL(page.url()).searchParams.get('wl_job');
    const next = observe().jobs.find(j => j.public_id === nextId);
    const plan = JSON.parse(next.plan_json);
    ok(plan.source_kind === 'repeat' && plan.source_job === sourceId, 'new Preview freezes Repeat provenance');
    ok(next.plan_id !== source.plan_id && next.plan_hash !== source.plan_hash, 'Repeat has distinct immutable identity/hash');
    ok(plan.items.every(item => item.snapshot.regular_price === '105.00' && item.planned_regular_price === '110.25'), 'Repeat computes current-price 105 -> 110.25');
    ok(Number(next.approver_id) === 0 && await button(page, 'Approve and apply').count() === 1, 'Repeat separately unapproved');
    ok((await page.locator('.writeleash-admin').innerText()).includes('repeated from a previous job'), 'Repeat visually distinct from Recovery');
    await page.evaluate(() => { document.activeElement.blur(); scrollTo(0, 0); });
    await capture(page, javaScriptEnabled ? 'combined-repeat-preview' : 'combined-nojs-repeat-preview');
    assert.deepEqual(observe().jobs.find(j => j.public_id === sourceId), source, 'Repeat leaves original job bytes unchanged');
    await page.goto(home); await enter(page, button(page, 'Delete preset'));
    assert.deepEqual(observe().jobs.find(j => j.public_id === sourceId), source, 'preset Delete preserves historical job');
    ok(await button(page, 'Load preset').count() === 0, 'Repeat did not create another preset');
    console.log('#241+#242 ' + engine + ' JS=' + javaScriptEnabled + ' preset -> Apply -> History Repeat, no-JS count/error retention, frozen kind/new approval PASS');
}
(async () => {
    const browser = await pw[engine].launch({ headless: true, ...(engine === 'chromium' ? { executablePath: process.env.WL167_CHROME || '/usr/bin/google-chrome' } : {}) });
    try {
        let session;
        for (const javaScriptEnabled of [true, false]) {
            const context = await browser.newContext({ javaScriptEnabled, viewport: { width: 1440, height: 900 } });
            const page = await context.newPage(); page.setDefaultTimeout(60000);
            try {
                await page.goto(base + '/wp-login.php'); await page.waitForLoadState('networkidle');
                await page.getByLabel('Username or Email Address').fill(f.users.manager.username);
                await page.getByLabel('Password', { exact: true }).fill(f.users.manager.password);
                await enter(page, button(page, 'Log In')); await page.goto(home);
                const before = observe();
                await page.locator('#writeleash-free-selector').selectOption('manual_ids');
                const summary = page.locator('#writeleash-free-advanced-selection > summary');
                if (!await summary.evaluate(el => el.parentElement.open)) { await tab(page, summary); await page.keyboard.press('Enter'); }
                await page.locator('#writeleash-free-ids').fill(f.ids.join(','));
                await page.locator('#writeleash-free-operation').selectOption('DECREASE_PERCENT');
                await page.locator('#writeleash-free-amount').fill('10');
                await tab(page, page.locator('#writeleash-free-range-enabled')); await page.keyboard.press('Space');
                await page.locator('#writeleash-free-range_min').fill('100');
                await page.locator('#writeleash-free-range_max').fill('100');
                await page.locator('#writeleash-free-range-basis').selectOption('regular_price');
                await page.locator('#writeleash-preset-name').fill('Integrated & preset');
                await capture(page, javaScriptEnabled ? 'configuration' : 'nojs-configuration');
                if (javaScriptEnabled) await scan(page, 'configuration');
                await page.locator('#writeleash-free-range_min').fill('101');
                await enter(page, button(page, 'Save as preset'));
                ok((await page.locator('.writeleash-admin').innerText()).includes('Check the optional price range'), 'invalid range refused visibly');
                ok(await page.locator('#writeleash-free-range_min').inputValue() === '101', 'invalid range retained');
                assert.deepEqual(observe().jobs, before.jobs, 'invalid Save creates no Preview');
                await page.locator('#writeleash-free-range_min').fill('100');
                await page.locator('#writeleash-preset-name').fill('Integrated & preset');
                await enter(page, button(page, 'Save as preset'));
                ok((await page.locator('.writeleash-admin').innerText()).includes('Preset saved.'), 'native Save succeeded');
                await page.goto(home); await enter(page, button(page, 'Load preset'));
                ok(await page.locator('#writeleash-free-range-enabled').isChecked(), 'enabled range preserved');
                ok(await page.locator('#writeleash-free-range_min').inputValue() === '100' && await page.locator('#writeleash-free-range_max').inputValue() === '100', 'independent inclusive bounds preserved');
                ok(await page.locator('#writeleash-free-range-basis').inputValue() === 'regular_price', 'range basis preserved');
                assert.deepEqual(observe().jobs, before.jobs, 'Save and Load create no jobs');
                assert.deepEqual(observe().prices, before.prices, 'CRUD changes no prices');
                await page.getByLabel('New preset name', { exact: true }).fill('Renamed & integrated');
                await enter(page, button(page, 'Rename preset'));
                ok(await page.getByRole('heading', { name: 'Renamed & integrated', exact: true }).count() === 1, 'rename is escaped and durable');
                await enter(page, button(page, 'Load preset'));
                await page.locator('#writeleash-free-amount').fill('9');
                await enter(page, button(page, 'Preview price changes'));
                const root = new URL(page.url()).searchParams.get('wl_job');
                const rootJob = observe().jobs.find(j => j.public_id === root);
                assert.deepEqual(JSON.parse(rootJob.plan_json).resolved_product_ids, f.ids, 'exact range membership after persistence');
                ok(Number(rootJob.approver_id) === 0 && await button(page, 'Approve and apply').count() === 1, 'new independent approval required');
                await enter(page, page.getByRole('link', { name: 'Exclude individual products', exact: true }));
                const row = page.getByRole('checkbox', { name: 'Select product #' + f.ids[0], exact: true });
                await tab(page, row); await page.keyboard.press('Space');
                await enter(page, button(page, 'Exclude selected rows'));
                const refined = new URL(page.url()).searchParams.get('wl_job');
                ok(refined !== root, 'refinement creates new immutable Preview');
                ok(await button(page, 'Save as preset').count() === 0 && await button(page, 'Approve and apply').count() === 0, 'refinement offers neither misleading Save nor Apply');
                ok((await page.locator('.writeleash-admin').innerText()).includes('This refined Preview cannot be saved as a preset.'), 'exclusion limitation explicit');
                await enter(page, page.getByRole('link', { name: 'Review updated Preview', exact: true }));
                const frozen = observe().jobs.find(j => j.public_id === refined);
                const plan = JSON.parse(frozen.plan_json);
                assert.deepEqual(plan.selection_refinement.excluded_ids, [f.ids[0]]);
                assert.deepEqual(plan.resolved_product_ids, f.ids.slice(1));
                ok(Number(frozen.approver_id) === 0 && await button(page, 'Approve and apply').count() === 1, 'refined Preview separately unapproved');
                await capture(page, javaScriptEnabled ? 'refined-preview' : 'nojs-refined-preview');
                if (javaScriptEnabled) await scan(page, 'refined Preview');
                await page.goto(home); await enter(page, button(page, 'Load preset'));
                assert.deepEqual(observe().jobs.find(j => j.public_id === refined), frozen, 'ordinary Load never changes refined plan');
                await enter(page, button(page, 'Delete preset'));
                ok(await button(page, 'Load preset').count() === 0, 'preset deleted');
                assert.deepEqual(observe().jobs.find(j => j.public_id === refined), frozen, 'Delete preserves refined plan');
                assert.deepEqual(observe().saves, before.saves, 'no unexpected Woo saves before approval');
                console.log('#230 ' + engine + ' JS=' + javaScriptEnabled + ' keyboard CRUD/range/refinement/no-write/new-approval PASS');
                session = await context.storageState();
            } finally { await context.close(); }
        }
        // Keep the original read-only #230 assertions intact before the new
        // combined journey deliberately applies prices on separate fixture IDs.
        for (const javaScriptEnabled of [true, false]) {
            const context = await browser.newContext({ javaScriptEnabled, storageState: session, viewport: { width: 1440, height: 900 } });
            const page = await context.newPage(); page.setDefaultTimeout(60000);
            try { await combined241(page, javaScriptEnabled); }
            finally { await context.close(); }
        }
        if (out) fs.writeFileSync(out + '/230-' + engine + '-result.json', JSON.stringify({ engine, version: await browser.version(), checks, scans, enhanced: 'PASS', noJavaScript: 'PASS', keyboard: 'PASS', screenReader: 'NOT_TESTED' }, null, 2));
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
