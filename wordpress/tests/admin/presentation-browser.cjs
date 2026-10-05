// #168 actual Chromium merchant screens, using the existing disposable Admin runner.
const { chromium } = require(process.env.WL167_PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');
const base = process.env.WL167_BASE_URL;
const site = process.env.WL167_SITE;
const file = process.env.WL168_FIXTURE;
if (!base || !site || !file) throw new Error('Explicit disposable #168 site/URL/fixture required');
const fixture = JSON.parse(fs.readFileSync(file, 'utf8'));
const home = base + '/wp-admin/admin.php?page=writeleash-bulk-prices';
let checks = 0;
function ok(value, label) { assert.ok(value, label); checks++; }
function observe(mode = 'observe') {
    const args = process.env.WL167_CONTAINER
        ? ['exec', '-e', 'WL168_MODE=' + mode, '-e', 'WL168_FIXTURE=/tmp/wl168-fixture.json', process.env.WL167_CONTAINER, 'wp', '--path=' + site, 'eval-file', '/opt/tests/admin/presentation-browser-fixture.php']
        : ['--path=' + site, 'eval-file', __dirname + '/presentation-browser-fixture.php'];
    return JSON.parse(execFileSync(process.env.WL167_CONTAINER ? 'docker' : 'wp', args, { env: { ...process.env, WL168_MODE: mode }, encoding: 'utf8' }));
}
async function capture(page, name) {
    const representative = ['main-configuration', 'preview', 'mixed-conflict', 'undo-conflict', 'history'].includes(name);
    if (!representative) {
        if (process.env.WL167_EVIDENCE) {
            fs.mkdirSync(process.env.WL167_EVIDENCE, { recursive: true });
            await page.locator('#wpbody-content').screenshot({ path: process.env.WL167_EVIDENCE + '/168-' + name + '.png' });
        }
        return;
    }
    for (const width of [1440, 1024, 782, 375]) {
        await page.setViewportSize({ width, height: 900 });
        ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), name + ': no page overflow at ' + width);
        ok(await page.locator('.writeleash-admin .button-primary').count() <= 1, name + ': one primary action');
        for (const region of await page.locator('.writeleash-table-scroll').all()) {
            await region.focus(); await page.keyboard.press('Tab'); await page.keyboard.press('Shift+Tab');
            ok(await region.evaluate(el => document.activeElement === el && getComputedStyle(el).outlineStyle !== 'none'), name + ': keyboard scroll region focus visible');
            await page.keyboard.press('ArrowRight');
            if (width === 375) ok(await region.evaluate(el => el.scrollLeft > 0), name + ': table scrolls by keyboard');
            await region.evaluate(el => { el.scrollLeft = 0; });
        }
        if (process.env.WL167_EVIDENCE) {
            fs.mkdirSync(process.env.WL167_EVIDENCE, { recursive: true });
            await page.evaluate(() => { document.activeElement.blur(); window.scrollTo(0, 0); });
            await page.screenshot({ path: process.env.WL167_EVIDENCE + '/169-' + name + '-' + width + '.png', fullPage: true });
        }
    }
    await page.setViewportSize({ width: 1440, height: 900 });
}
async function login(page, username = fixture.username, password = fixture.password) {
    await page.goto(base + '/wp-login.php');
    await page.waitForLoadState('networkidle');
    await page.getByLabel('Username or Email Address').fill(username);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await Promise.all([page.waitForURL(/wp-admin/), page.getByRole('button', { name: 'Log In', exact: true }).click()]);
}
async function open(page, key, view = 'job') {
    await page.goto(home + '&wl_view=' + view + '&wl_job=' + fixture.jobs[key]);
}
async function action(page, name) {
    await Promise.all([page.waitForURL(/wl_view=job/), page.getByRole('button', { name, exact: true }).click()]);
    await page.waitForLoadState('networkidle');
}
async function link(page, name) {
    const control = page.getByRole('link', { name, exact: true });
    const target = await control.getAttribute('href');
    await Promise.all([page.waitForURL(target), control.click()]);
    await page.waitForLoadState('networkidle');
}
async function text(page) { return page.locator('#wpbody-content').innerText(); }
async function row(page, id) { return page.locator('tr[data-product-id="' + id + '"]'); }
(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.WL167_CHROME || '/usr/bin/google-chrome' });
    const errors = [];
    try {
        const context = await browser.newContext({ acceptDownloads: true });
        const page = await context.newPage();
        page.on('dialog', () => { throw new Error('Markup-shaped name executed'); });
        page.on('pageerror', error => errors.push(error.message));
        await login(page);
        await page.goto(home); await capture(page, 'main-configuration');
        await page.locator('#writeleash-free-amount').focus();
        await page.keyboard.press('Tab'); await page.keyboard.press('Shift+Tab');
        ok(await page.locator('#writeleash-free-amount').evaluate(el => document.activeElement === el && getComputedStyle(el).outlineStyle !== 'none'), 'keyboard input focus visible');
        for (const path of ['/wp-admin/edit.php?post_type=product', '/wp-admin/admin.php?page=wc-orders', '/wp-admin/admin.php?page=wc-settings']) {
            await page.goto(base + path);
            ok(await page.locator('link[href*="free-selection.css"], .writeleash-admin').count() === 0, 'WriteLeash style absent on ' + path);
        }
        await open(page, 'blocked', 'preview');
        ok((await text(page)).includes('This plan cannot be executed.'), 'blocked plan clear');
        ok(await page.getByRole('button', { name: 'Approve and queue execution' }).count() === 0, 'blocked no execution action');
        await open(page, 'blocked');
        ok((await text(page)).includes('will not run') && !(await text(page)).includes('1 remaining'), 'blocked internal pending is not remaining work');
        await capture(page, 'blocked');
        await open(page, 'nochange', 'preview');
        ok((await text(page)).includes('Already at target; unchanged') && (await text(page)).includes('Excluded; unchanged'), 'exclusion/no-change preview');
        await action(page, 'Approve and queue execution');
        await action(page, 'Resume remaining products');
        ok((await text(page)).includes('1 already at target') && (await text(page)).includes('1 excluded'), 'disjoint no-change results');
        ok((await text(page)).includes('no products were changed by this job'), 'Undo unavailable exact reason');
        await capture(page, 'exclusions');
        await open(page, 'mixed', 'preview');
        await capture(page, 'preview');
        ok((await text(page)).includes('Blue T-Shirt') && !(await text(page)).includes('Renamed externally'), 'frozen saved identity after rename');
        await action(page, 'Approve and queue execution');
        ok((await text(page)).includes('12 remaining'), 'queued progress honest');
        await action(page, 'Resume remaining products');
        ok((await text(page)).includes('9 changed') && (await text(page)).includes('1 conflict') && (await text(page)).includes('2 remaining'), 'partial 10 processed separates conflicts and remaining');
        const conflict = await row(page, fixture.mixed_ids[0]);
        const cells = await conflict.locator('td').allTextContents();
        ok(cells[1] === '$18.00 USD' && cells[2] === '$21.00 USD' && cells[3] === '$14.40 USD', 'exact stale expected/current/target in same row');
        ok((await conflict.locator('td strong').allTextContents()).includes('Not changed'), 'merchant conflict label primary');
        ok((await conflict.innerText()).includes('left the newer value unchanged'), 'preserved newer edit explanation');
        ok(await conflict.getByRole('link', { name: 'Review product', exact: true }).count() === 1 && await conflict.getByRole('link', { name: 'Create a new preview', exact: true }).count() === 1, 'safe conflict next actions');
        ok(!/Force apply|Retry conflict/.test(await text(page)), 'no blind overwrite or terminal retry');
        await capture(page, 'partial');
        await page.reload();
        ok((await text(page)).includes('2 remaining'), 'reload retains partial durable truth');
        await action(page, 'Resume remaining products');
        ok((await text(page)).includes('11 changed') && (await text(page)).includes('1 conflict') && (await text(page)).includes('Finished with products needing attention'), 'mixed finished is not complete success');
        ok(observe().prices[fixture.mixed_ids[0]] === '21.00', 'independent storage confirms preserved newer price');
        await capture(page, 'mixed-conflict');
        await link(page, 'Apply conflicts');
        ok(await page.locator('tr[data-product-id]').count() === 1, 'attention view reads only matching conflicted product');
        await link(page, 'Uncertain Apply outcomes');
        ok((await text(page)).includes('No retained products on this page match this view.'), 'empty uncertainty view is honest');
        await link(page, 'All products');
        observe('edit-undo');
        await page.reload();
        ok((await text(page)).includes('Undo available'), 'eligible Undo offered');
        await action(page, 'Restore eligible prices (Undo)');
        ok((await text(page)).includes('Undo in progress') && await page.getByRole('button', { name: 'Continue Undo', exact: true }).count() === 1, 'partial Undo distinguish continued work');
        await action(page, 'Continue Undo');
        ok((await text(page)).includes('Undo finished with conflicts'), 'Undo conflict finished outcome');
        ok((await (await row(page, fixture.mixed_ids[1])).innerText()).includes('preserved the newer value instead of restoring over it'), 'Undo preservation plain language');
        ok(observe().prices[fixture.mixed_ids[1]] === '93.00', 'independent storage confirms conflicting Undo preserved external edit');
        ok((await text(page)).includes('does not reverse: orders'), 'Undo external effects boundary');
        await capture(page, 'undo-conflict');
        await open(page, 'sale', 'preview');
        await action(page, 'Approve and queue execution'); await action(page, 'Resume remaining products');
        ok((await text(page)).includes('sale price or schedule') && (await text(page)).includes('matching regular price alone'), 'sale eligibility conflict despite equal regular price');
        ok(observe().prices[fixture.sale_id] === '18.00', 'independent observer confirms equal price preserved');
        await capture(page, 'sale-conflict');
        await open(page, 'deleted');
        ok((await text(page)).includes('Deleted but recognizable') && (await text(page)).includes('Unavailable'), 'deleted product identity and unavailable current observation');
        ok(await page.getByRole('link', { name: 'Review product', exact: true }).count() === 0, 'deleted product has no edit link');
        await capture(page, 'deleted-product');
        await open(page, 'expired');
        ok((await text(page)).includes('Undo expired') && await page.getByRole('button', { name: 'Restore eligible prices (Undo)' }).count() === 0, 'expired Undo exact explanation and no restore');
        await capture(page, 'expired-undo');
        await open(page, 'success', 'preview');
        ok((await text(page)).includes('<script>alert("168")</script> Café 日本'), 'Unicode and markup-shaped preview identity');
        await action(page, 'Approve and queue execution'); await action(page, 'Resume remaining products');
        ok((await text(page)).includes('2 changed') && (await text(page)).includes('Undo available'), 'successful Apply');
        const before = observe();
        const downloadEvent = page.waitForEvent('download');
        await page.getByRole('button', { name: 'Download job CSV', exact: true }).click();
        const download = await downloadEvent;
        const csv = fs.readFileSync(await download.path(), 'utf8');
        ok(download.suggestedFilename() === 'writeleash-' + fixture.jobs.success + '.csv', 'job-bound CSV attachment');
        ok(csv.includes("'=SUM(1,2) Café") && csv.includes("'+SKU-") && csv.includes('14.40') && csv.includes('18.00'), 'formula protection and original decimal strings in actual download');
        ok(csv.includes('<script>') && csv.includes('日本') && csv.includes('WOO_CRUD_VERIFIED'), 'CSV saved identity and durable outcome');
        ok(!/lease_owner|fingerprint|attempt_id|plan_json/.test(csv), 'CSV excludes private diagnostics');
        const after = observe();
        ok(before.saves === after.saves && before.ddl === after.ddl, 'CSV does not save products or create tables');
        await action(page, 'Restore eligible prices (Undo)');
        ok((await text(page)).includes('Undo finished: eligible prices restored.'), 'clean finished Undo');
        ok(fixture.success_ids.every(id => Number(observe().prices[id]) === 18), 'independent storage confirms clean restoration');
        await capture(page, 'undo-finished');
        await open(page, 'operator');
        await link(page, 'Open history');
        ok((await text(page)).includes('Awaiting review') && (await text(page)).includes('regular prices'), 'history task and approval status descriptive');
        ok(await page.getByRole('link', { name: 'Next page', exact: true }).count() === 1, 'paginated history');
        await capture(page, 'history');
        await link(page, 'Next page');
        ok((await text(page)).includes('Deleted user (User #' + fixture.deleted_actor + ')'), 'deleted operator fallback in paginated history');
        ok((await text(page)).includes('Created by') && (await text(page)).includes('Approved by'), 'operator context present');
        await capture(page, 'history-page-two');
        const otherContext = await browser.newContext(); const other = await otherContext.newPage();
        await login(other, fixture.empty_username, fixture.empty_password);
        await other.goto(home + '&wl_view=history');
        ok((await text(other)).includes('No jobs visible to your account yet.'), 'empty actor-scoped history');
        await capture(other, 'empty-history');
        await open(other, 'success');
        ok((await text(other)).includes('No job is visible') && await other.getByRole('button', { name: 'Download job CSV' }).count() === 0, 'another Manager cannot view or export unreadable job');
        await otherContext.close();
        ok(errors.length === 0, 'product names do not execute or cause browser errors');
        await context.close();
        console.log('#168 real Chromium merchant results/conflicts/partial Resume/Undo/history/CSV: PASS (' + checks + ' assertions); browser=' + await browser.version());
    } finally { await browser.close(); }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
