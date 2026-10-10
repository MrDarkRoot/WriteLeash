// #231 Chromium/Firefox native keyboard flow with JavaScript disabled.
const pw = require(process.env.WL167_PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');
const f = JSON.parse(fs.readFileSync(process.env.WL170_FIXTURE));
const engine = process.env.WL170_BROWSER;
const base = process.env.WL167_BASE_URL;
const home = base + '/wp-admin/admin.php?page=writeleash-bulk-prices';
function observe() {
    return JSON.parse(execFileSync('docker', ['exec', '-e', 'WL170_MODE=observe', '-e', 'WL170_FIXTURE=/tmp/wl170-fixture.json', process.env.WL167_CONTAINER,
        'wp', '--path=' + process.env.WL167_SITE, 'eval-file', '/opt/tests/admin/regression-browser-fixture.php'], { encoding: 'utf8' }));
}
async function tab(page, target) {
    for (let n = 0; n < 250; n++) {
        if (await target.evaluate(el => document.activeElement === el)) return;
        const backward = await target.evaluate(el => !!(el.compareDocumentPosition(document.activeElement) & Node.DOCUMENT_POSITION_FOLLOWING));
        await page.keyboard.press(backward ? 'Shift+Tab' : 'Tab');
    }
    throw new Error('Keyboard target unreachable');
}
async function enter(page, target) {
    await tab(page, target);
    await Promise.all([page.waitForEvent('framenavigated', { predicate: frame => frame === page.mainFrame() }), page.keyboard.press('Enter')]);
    await page.waitForLoadState('networkidle');
}
async function check(page, id) {
    const target = page.getByRole('checkbox', { name: 'Select product #' + id, exact: true });
    await tab(page, target); await page.keyboard.press('Space'); assert.equal(await target.isChecked(), true);
}
(async () => {
    const browser = await pw[engine].launch({ headless: true });
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage(); page.setDefaultTimeout(60000);
    try {
        await page.goto(base + '/wp-login.php');
        await page.getByLabel('Username or Email Address').fill(f.users.manager.username);
        await page.getByLabel('Password', { exact: true }).fill(f.users.manager.password);
        await enter(page, page.getByRole('button', { name: 'Log In', exact: true })); await page.goto(home);
        await page.locator('#writeleash-free-selector').selectOption('manual_ids');
        await page.locator('#writeleash-free-ids').fill(f.ids.join(','));
        await page.locator('#writeleash-free-operation').selectOption('SET');
        await page.locator('#writeleash-free-amount').fill('80');
        await enter(page, page.getByRole('button', { name: 'Preview price changes', exact: true }));
        const root = new URL(page.url()).searchParams.get('wl_job');
        await enter(page, page.getByRole('link', { name: 'Exclude individual products', exact: true }));
        assert.equal(await page.getByRole('button', { name: 'Approve and apply', exact: true }).count(), 0, 'refinement page has no approval');
        await check(page, f.ids[0]); await enter(page, page.getByRole('button', { name: 'Exclude selected rows', exact: true }));
        const first = new URL(page.url()).searchParams.get('wl_job'); assert.notEqual(first, root);
        await enter(page, page.getByRole('link', { name: 'Next page', exact: true }));
        await check(page, f.ids[20]); await enter(page, page.getByRole('button', { name: 'Exclude selected rows', exact: true }));
        const final = new URL(page.url()).searchParams.get('wl_job'); assert.notEqual(final, first);
        await page.reload();
        assert.match(await page.locator('.writeleash-refinement-counts').innerText(), /Resolved candidates 22 · Included targets 20 · Excluded targets 2/);
        const state = observe(); const saved = state.jobs.find(j => j.public_id === final); const plan = JSON.parse(saved.plan_json);
        assert.deepEqual(plan.selection_refinement.excluded_ids, [f.ids[0], f.ids[20]]);
        assert.deepEqual(plan.resolved_product_ids, f.ids.filter(id => ![f.ids[0], f.ids[20]].includes(id)));
        assert.equal(Number(saved.approver_id), 0); assert.deepEqual(state.saves, [], 'exclusions and reload write zero prices');
        await enter(page, page.getByRole('link', { name: 'Cancel selection changes', exact: true }));
        assert.equal(new URL(page.url()).searchParams.get('wl_job'), final);
        await enter(page, page.getByRole('link', { name: 'Exclude individual products', exact: true }));
        await enter(page, page.getByRole('link', { name: 'Review updated Preview', exact: true }));
        assert.match(await page.locator('.writeleash-refinement-counts').innerText(), /Included targets 20/);
        await enter(page, page.getByRole('button', { name: 'Approve and apply', exact: true }));
        const approved = observe().jobs.find(j => j.public_id === final);
        assert.equal(Number(approved.approver_id), f.users.manager.id, 'explicit final approval binds current merchant');
        const included = JSON.parse(approved.plan_json).resolved_product_ids;
        assert.equal(included.includes(f.ids[0]) || included.includes(f.ids[20]), false);
        if (process.env.WL167_EVIDENCE) {
            fs.mkdirSync(process.env.WL167_EVIDENCE, { recursive: true });
            fs.writeFileSync(process.env.WL167_EVIDENCE + '/231-' + engine + '-result.json', JSON.stringify({ engine, browserVersion: await browser.version(), javaScript: false, keyboard: 'PASS', candidates: 22, included: 20, excluded: 2, finalPlan: saved.plan_id }, null, 2));
        }
        console.log('#231 ' + engine + ' no-JS keyboard pagination, reload, cancel, exact membership and separate approval: PASS');
    } finally { await context.close(); await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
