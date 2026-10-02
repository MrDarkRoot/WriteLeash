// Release-only browser journey; never run from PR_FAST or against a real store.
// Requirements: local Playwright and Chromium; a NEW disposable WP/Woo site,
// public-manifest WriteLeash, fixture.php already seeded, cron disabled and
// scheduler-interruption.php installed only in that fixture's MU plugins.
// Required environment: WL122_BASE_URL (loopback), WL122_WP_SITE,
// WL122_WP_CLI_PHAR, WL122_PLAYWRIGHT_MODULE, WL122_CHROMIUM,
// WL122_ADMIN_PASSWORD. Optional WL122_ADMIN_USER (default demo-manager).
// Writes raw product-region PNGs and capture-log.json into WL122_OUTPUT,
// which must be an existing review directory outside the canonical asset set.
// Optional WL122_SCREENSHOTS selects which PNGs to write (e.g. 2,3,4,5);
// the full real workflow still runs. Review outputs and update proof explicitly.
const fs = require('fs'), path = require('path'), cp = require('child_process');
const assert = require('assert/strict');
function required(name) { assert(process.env[name], `Missing ${name}`); return process.env[name]; }
const base = required('WL122_BASE_URL').replace(/\/$/, '');
assert(['127.0.0.1', 'localhost', '[::1]'].includes(new URL(base).hostname), 'Disposable loopback capture only');
const site = required('WL122_WP_SITE'), cli = required('WL122_WP_CLI_PHAR');
const { chromium } = require(required('WL122_PLAYWRIGHT_MODULE'));
const executablePath = required('WL122_CHROMIUM');
const password = required('WL122_ADMIN_PASSWORD'), username = process.env.WL122_ADMIN_USER || 'demo-manager';
const output = required('WL122_OUTPUT');
const selected = new Set((process.env.WL122_SCREENSHOTS || '1,2,3,4,5,6').split(',').map(Number));
assert([...selected].every(n => Number.isInteger(n) && n >= 1 && n <= 6), 'Invalid screenshot selection');
assert(fs.statSync(output).isDirectory());
const fixture = path.join(__dirname, 'fixture.php');
const home = base + '/wp-admin/admin.php?page=writeleash-bulk-prices';
const ids = JSON.parse(cp.execFileSync('php', [cli, '--path=' + site, 'option', 'get', 'wl122_fixture_ids', '--format=json'], { encoding: 'utf8' }));
assert.equal(ids.length, 12, 'Twelve synthetic fixture products required');
assert.equal(ids[0], 10, 'Deterministic first product must be 10');
const evidence = { screenshots: [], observations: [] };
function save() { fs.writeFileSync(path.join(output, 'capture-log.json'), JSON.stringify(evidence, null, 2) + '\n'); }
function observe(label) {
    const prices = JSON.parse(cp.execFileSync('php', [cli, '--path=' + site, 'eval-file', fixture], { env: { ...process.env, WL122_MODE: 'observe' }, encoding: 'utf8' }));
    evidence.observations.push({ label, prices }); save(); return prices;
}
function edit() {
    cp.execFileSync('php', [cli, '--path=' + site, 'eval-file', fixture], { env: { ...process.env, WL122_MODE: 'edit' }, encoding: 'utf8' });
}
async function login(page) {
    await page.goto(base + '/wp-login.php');
    await page.locator('#user_login').fill(username);
    await page.locator('#user_pass').fill(password);
    await Promise.all([page.waitForURL('**/wp-admin/**'), page.locator('#wp-submit').click()]);
}
function region(page) { return page.locator('#wpbody-content > .wrap'); }
async function preview(page, selected, operation = 'DECREASE_PERCENT', amount = '20', maximum = '50') {
    await page.goto(home);
    await page.locator('#writeleash-free-ids').fill(selected.join(','));
    await page.locator('#writeleash-free-operation').selectOption(operation);
    await page.locator('#writeleash-free-amount').fill(amount);
    await page.locator('#writeleash-free-max_increase').fill(maximum);
    await Promise.all([page.waitForURL('**&wl_view=preview&wl_job=*'), page.getByRole('button', { name: 'Build frozen preview', exact: true }).click()]);
    return page.url();
}
async function action(page, name) {
    await Promise.all([page.waitForURL('**&wl_view=job&wl_job=*'), page.getByRole('button', { name, exact: true }).click()]);
}
async function capture(page, n, state, requiredText) {
    const content = region(page), body = await content.innerText();
    for (const text of requiredText) { assert(body.includes(text), 'Missing required real UI state: ' + text); }
    assert(!/@|127\.0\.0\.1|localhost|\/tmp\/|nonce|password|token=/i.test(body), 'Unexpected sensitive/identifying screenshot text');
    if (!selected.has(n)) { return; }
    const filename = `screenshot-${n}.png`;
    // Native rendering only; no CSS, DOM, text, controls or state modification.
    await content.screenshot({ path: path.join(output, filename) });
    evidence.screenshots.push({ filename, state, body, captured_at_utc: new Date().toISOString() }); save();
    process.stdout.write(`${filename}: ${state}\n`);
}
(async () => {
    let browser;
    try {
        browser = await chromium.launch({ headless: true, executablePath });
        let context = await browser.newContext({ viewport: { width: 1020, height: 1600 }, deviceScaleFactor: 1 });
        let page = await context.newPage();
        await login(page);
        const before = observe('before preview/block');
        await preview(page, ids.slice(0, 3));
        await capture(page, 1, 'Frozen Change Plan', ['Frozen preview', 'Before', 'After', 'DECREASE_PERCENT 20', 'Approve exact plan', 'Approve and queue execution']);
        const blockedPreview = await preview(page, ids.slice(0, 3), 'INCREASE_PERCENT', '30', '10');
        assert((await region(page).innerText()).includes('BLOCKED'));
        await page.goto(blockedPreview.replace('wl_view=preview', 'wl_view=job'));
        await capture(page, 2, 'Safety-policy blocked', ['State: BLOCKED (BLOCKED_BY_POLICY)', 'applied 0']);
        assert.deepEqual(observe('after blocked plan'), before, 'Blocked plan changed prices');
        await preview(page, ids);
        await action(page, 'Approve and queue execution');
        const jobURL = page.url();
        edit();
        await action(page, 'Run bounded resume chunk');
        const headers = await region(page).locator('table thead th').allTextContents();
        assert.deepEqual(headers, ['Product', 'Expected', 'Current', 'Planned', 'Apply', 'Undo']);
        const conflictRow = region(page).locator('table tbody tr').filter({ has: page.locator('td').filter({ hasText: /^CONFLICT \(ITEM_CONFLICT\)$/ }) });
        assert.deepEqual(await conflictRow.locator('td').allTextContents(), [String(ids[0]), '18', '21', '14.40', 'CONFLICT (ITEM_CONFLICT)', '—']);
        await capture(page, 3, 'Execution-precondition conflict', ['Expected', 'Current', 'Planned', 'CONFLICT', 'conflict 1', 'applied 9', 'pending 2']);
        const first = observe('after first Apply chunk');
        assert.equal(first[0].stored_regular_price, '21.00', 'Blind overwrite');
        assert.equal(first[1].stored_regular_price, '19.20');
        // Close the entire browser, allow REAL timeout to elapse, then reopen.
        await browser.close(); browser = undefined;
        const expires = Date.now() + 65000;
        while (Date.now() < expires) { await new Promise(resolve => setTimeout(resolve, 1000)); }
        browser = await chromium.launch({ headless: true, executablePath });
        context = await browser.newContext({ viewport: { width: 1020, height: 1600 }, deviceScaleFactor: 1 });
        page = await context.newPage(); await login(page); await page.goto(jobURL); await page.reload();
        await capture(page, 4, 'Paused/interrupted durable job', ['State: PAUSED (LEASE_RECOVERY)', 'applied 9', 'conflict 1', 'pending 2', 'Run bounded resume chunk', 'worker lease expired']);
        assert.deepEqual(observe('after fresh browser login and reload'), first);
        for (let i = 0; i < 3; ++i) {
            if ((await region(page).innerText()).includes('State: COMPLETED_WITH_ISSUES')) { break; }
            await action(page, 'Run bounded resume chunk');
        }
        await capture(page, 5, 'Partial/completed-with-issues', ['State: COMPLETED_WITH_ISSUES', 'applied 11', 'conflict 1', 'pending 0', 'partial results are never reported as generic success', 'Restore eligible prices (Undo)']);
        const final = observe('after remaining work resumed');
        assert.equal(final[0].stored_regular_price, '21.00');
        assert.equal(final[10].stored_regular_price, '144.00');
        assert.equal(final[11].stored_regular_price, '168.00');
        await page.getByRole('link', { name: 'Open history', exact: true }).click();
        await capture(page, 6, 'History / conflict-aware Undo eligibility', ['History', 'COMPLETED_WITH_ISSUES', 'applied 11 / planned 12', 'conflict 1', 'eligible']);
    } finally { if (browser) { await browser.close(); } }
})().catch(error => { process.stderr.write(error.message + '\n'); process.exitCode = 1; });
