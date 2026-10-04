// #167 real-browser proof on the existing disposable Woo Admin fixture.
// Run after selection-browser-fixture.php seed; never against a real store.
const { chromium } = require(process.env.WL167_PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');
const base = process.env.WL167_BASE_URL;
const site = process.env.WL167_SITE;
const file = process.env.WL167_FIXTURE;
if (!base || !site || !file) throw new Error('Explicit #167 disposable site/URL/fixture required');
const fixture = JSON.parse(fs.readFileSync(file, 'utf8'));
const home = base + '/wp-admin/admin.php?page=writeleash-bulk-prices';
let checks = 0;
function ok(value, label) { assert.ok(value, label); checks++; }
function observe(mode = 'observe') {
    if (process.env.WL167_CONTAINER) {
        return JSON.parse(execFileSync('docker', ['exec', '-e', 'WL167_MODE=' + mode, '-e', 'WL167_FIXTURE=/tmp/wl167-fixture.json', process.env.WL167_CONTAINER,
            'wp', '--path=' + site, 'eval-file', '/opt/tests/admin/selection-browser-fixture.php'], { encoding: 'utf8' }));
    }
    return JSON.parse(execFileSync('wp', ['--path=' + site, 'eval-file', __dirname + '/selection-browser-fixture.php'], { env: { ...process.env, WL167_MODE: mode }, encoding: 'utf8' }));
}
async function capture(page, name) {
    if (!process.env.WL167_EVIDENCE) return;
    fs.mkdirSync(process.env.WL167_EVIDENCE, { recursive: true });
    await page.locator('#wpbody-content').screenshot({ path: process.env.WL167_EVIDENCE + '/' + name + '.png' });
}
async function login(page) {
    await page.goto(base + '/wp-login.php');
    await page.getByLabel('Username or Email Address').fill(fixture.username);
    await page.getByLabel('Password', { exact: true }).fill(fixture.password);
    await Promise.all([page.waitForURL(/wp-admin/), page.getByRole('button', { name: 'Log In', exact: true }).click()]);
    await page.goto(home);
}
async function action(page, name) {
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.getByRole('button', { name, exact: true }).click()]);
    await page.waitForLoadState('networkidle');
}
function input(page) { return page.locator('#writeleash-free-products + .select2-container .select2-search__field'); }
async function typeSearch(page, term) {
    await input(page).fill('');
    await input(page).pressSequentially(term);
}
async function search(page, term) {
    const response = page.waitForResponse(response => {
        const url = new URL(response.url());
        return url.pathname.endsWith('/admin-ajax.php') && url.searchParams.get('action') === 'writeleash_free_discovery' && url.searchParams.get('term') === term;
    });
    await typeSearch(page, term);
    let result;
    try { result = await (await response).json(); }
    catch (error) {
        console.error('Search diagnostics:', JSON.stringify(await page.evaluate(() => ({
            status: document.getElementById('writeleash-free-discovery-status').textContent,
            action: document.getElementById('writeleash-free-selection-form').dataset.discoveryAction,
            value: document.querySelector('#writeleash-free-products + .select2-container .select2-search__field').value
        }))));
        await capture(page, 'failed-search');
        throw error;
    }
    await page.locator('#writeleash-free-discovery-status').filter({ hasText: /Choose matches|No matches|Search limit/ }).waitFor();
    if (result.data.results.length) await page.locator('.select2-results__option[data-selected]').first().waitFor();
}
(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.WL167_CHROME || '/usr/bin/google-chrome' });
    const errors = [];
    try {
        const context = await browser.newContext();
        let page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        page.on('request', request => {
            const url = new URL(request.url());
            if (url.pathname.endsWith('/admin-ajax.php')) console.log('#167 AJAX:', JSON.stringify({ action: url.searchParams.get('action'), kind: url.searchParams.get('kind'), term: url.searchParams.get('term') }));
        });
        await login(page);
        await input(page).waitFor();
        ok(await page.locator('#writeleash-free-selected').innerText() === 'No products selected. Search and choose products to add them.', 'nothing automatically selected');
        await search(page, 'WL167 Browser Café');
        ok((await page.locator('.select2-results__option[data-selected]').allTextContents()).every(text => text.includes('ID:')), 'duplicate names have IDs');
        await page.keyboard.press('ArrowDown'); await page.keyboard.press('Enter');
        ok(await page.locator('#writeleash-free-selected button').count() === 1, 'keyboard adds one product');
        await search(page, 'WL167 Browser Café');
        await page.locator('.select2-results__option[data-selected="false"]').first().click();
        ok(await page.locator('#writeleash-free-selected li').count() === 2, 'keyboard and click add distinct products');
        await capture(page, 'selected-products');
        await search(page, 'WL167 Browser Café');
        ok(await page.locator('.select2-results__option[data-selected="false"]').count() === 0, 'both matching products are already selected');
        await page.keyboard.press('Enter');
        ok(await page.locator('#writeleash-free-selected button').count() === 2, 'repeated keyboard selection does not duplicate products');
        await page.locator('#writeleash-free-selected button').first().click();
        ok(await page.locator('#writeleash-free-selected button').count() === 1, 'individual removal works');
        ok(await input(page).evaluate(element => element === document.activeElement), 'removal returns focus to product search');
        await page.getByRole('button', { name: 'Clear selected products', exact: true }).click();
        ok(await page.locator('#writeleash-free-selected button').count() === 0, 'clear works');
        await search(page, 'WL167-SEARCH-');
        ok((await page.locator('.select2-results__option[data-selected]').allTextContents()).every(text => text.includes('WL167-SEARCH-')), 'partial SKU discovery');
        await page.locator('.select2-results__option[data-selected="false"]').filter({ hasText: 'WL167-SEARCH-TWO' }).click();
        for (const width of [1440, 1024, 782, 375]) {
            await page.setViewportSize({ width, height: 900 });
            const box = await page.locator('#writeleash-free-products + .select2-container').boundingBox();
            ok(box.x >= 0 && box.x + box.width <= width + 1, 'long-name product picker fits ' + width + 'px');
            await capture(page, 'selection-' + width);
        }
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.getByRole('button', { name: 'Clear selected products', exact: true }).click();
        await input(page).press('Escape');
        await page.route('**/admin-ajax.php*', async route => {
            const url = new URL(route.request().url());
            if (url.searchParams.get('action') !== 'writeleash_free_discovery') return route.continue();
            if (url.searchParams.get('term') === 'WL167 Browser Café') {
                const response = await route.fetch(); await new Promise(resolve => setTimeout(resolve, 1000));
                try { await route.fulfill({ response }); } catch (_) { /* expected aborted stale request */ }
            } else return route.continue();
        });
        await typeSearch(page, 'WL167 Browser Café'); await page.waitForTimeout(400);
        await search(page, 'WL167-SEARCH-TWO');
        await page.waitForTimeout(1200);
        ok((await page.locator('.select2-results__option[data-selected]').allTextContents()).every(text => text.includes('WL167-SEARCH-TWO')), 'older response never replaces newer search');
        await page.unroute('**/admin-ajax.php*');
        await input(page).press('Escape');
        await page.route('**/admin-ajax.php*', route => route.abort('timedout'));
        await typeSearch(page, 'WL167 timeout');
        await page.getByText('Search is unavailable. Try again, reload if your session expired, or use the native search below.', { exact: true }).waitFor();
        ok(true, 'failed search has useful native recovery');
        await page.locator('#writeleash-free-product_search').fill('WL167 Browser Café');
        await action(page, 'Search products');
        ok(await page.locator('#writeleash-free-products').isVisible(), 'native search matches remain selectable with failed AJAX and JavaScript enabled');
        await page.locator('#writeleash-free-products').selectOption(fixture.products.slice(0, 2).map(String));
        await action(page, 'Update selected products');
        ok(await page.locator('#writeleash-free-selected button').count() === 2, 'network-failure fallback adds the chosen products');
        await page.unroute('**/admin-ajax.php*');
        await page.goto(home);
        await input(page).press('Escape');
        await search(page, 'WL167 never-matches');
        await page.getByText(/No matches on this page/).first().waitFor();
        ok(true, 'empty async search explained');
        await input(page).press('Escape');
        await search(page, 'WL167 Browser Café');
        await page.locator('.select2-results__option[data-selected="false"]').first().click();
        await search(page, 'WL167 Browser Café');
        await page.locator('.select2-results__option[data-selected="false"]').first().click();
        await page.locator('#writeleash-free-amount').fill('bad-price');
        await action(page, 'Build frozen preview');
        ok(await page.locator('#writeleash-free-amount').inputValue() === 'bad-price', 'validation retains entered amount');
        ok(await page.locator('#writeleash-free-selected button').count() === 2, 'validation retains chosen identities');
        await page.locator('#writeleash-free-amount').fill('80.00');
        await action(page, 'Build frozen preview');
        const previewURL = page.url();
        await capture(page, 'saved-preview');
        const before = observe();
        const saved = before.jobs[0];
        ok(before.saves === 0, 'real browser discovery and preview caused zero Woo saves');
        await page.close(); page = await context.newPage(); await page.goto(home);
        const review = page.getByRole('link', { name: 'Continue review', exact: true }).first();
        await review.click(); await page.waitForLoadState('networkidle');
        ok(page.url() === previewURL, 'Recent jobs continues the same saved preview');
        await page.goto(home + '&wl_view=history');
        await page.getByRole('link', { name: 'Continue review', exact: true }).first().click();
        await page.waitForLoadState('networkidle');
        ok(page.url() === previewURL, 'History continues the same saved preview');
        await capture(page, 'continued-review');
        observe('edit');
        await page.reload();
        const reopened = observe().jobs.find(job => job.public_id === saved.public_id);
        ok(reopened.plan_json === saved.plan_json && reopened.plan_hash === saved.plan_hash, 'reopen preserves exact population/expected/targets/policy/identity');
        ok((await page.locator('table').first().innerText()).includes('100.00'), 'preview retains originally reviewed before value after external edit');
        await page.goto(previewURL.replace('wl_view=preview', 'wl_view=job'));
        ok(await page.getByRole('link', { name: 'Continue review', exact: true }).count() === 1, 'direct status offers saved review');
        await page.getByRole('link', { name: 'Continue review', exact: true }).click();
        await action(page, 'Approve and queue execution');
        await action(page, 'Resume remaining products');
        ok((await page.locator('#wpbody-content').innerText()).includes('Not changed'), 'existing execution checks detect stale product');
        ok(Number(observe().prices[fixture.products[0]].stored) === 120, 'independent observer proves no blind overwrite');
        await page.goto(previewURL);
        ok(await page.getByRole('button', { name: 'Approve and queue execution', exact: true }).count() === 0, 'approved preview routes to actual results, cannot reapprove');
        ok(errors.length === 0, 'escaped names do not run JavaScript or cause script errors');
        await context.close();
        // Native fallback uses the same routes/controls with JavaScript disabled.
        const native = await browser.newContext({ javaScriptEnabled: false });
        page = await native.newPage(); await login(page);
        await page.locator('#writeleash-free-product_search').fill('WL167 Browser Café');
        await page.locator('#writeleash-free-amount').fill('bad-price');
        await action(page, 'Search products');
        const options = await page.locator('#writeleash-free-products option').allTextContents();
        ok(options.length === 2 && options.some(text => text.includes('No SKU')), 'native name search distinguishes duplicate names and absent SKU');
        await page.locator('#writeleash-free-products').selectOption(fixture.products.slice(0, 2).map(String));
        await action(page, 'Update selected products');
        ok(await page.locator('#writeleash-free-selected li').count() === 2 && await page.locator('#writeleash-free-amount').inputValue() === 'bad-price', 'native selection retains entered configuration');
        await page.locator('#writeleash-free-selected button').first().click(); await page.waitForLoadState('networkidle');
        ok(await page.locator('#writeleash-free-selected button').count() === 1, 'native remove');
        await action(page, 'Clear selected products');
        ok(await page.locator('#writeleash-free-selected button').count() === 0, 'native clear');
        await page.locator('#writeleash-free-selector').selectOption('category');
        await page.locator('#writeleash-free-category_search').fill('WL167 Browser Collection');
        await action(page, 'Search categories');
        ok((await page.locator('#writeleash-free-category option').allTextContents()).some(text => text.includes(' › ')), 'native category hierarchy visible');
        ok((await page.locator('#writeleash-free-selector-help').innerText()).includes('subcategories are not included'), 'direct-category limitation explained');
        await page.locator('#writeleash-free-category').selectOption(String(fixture.parent));
        await page.locator('#writeleash-free-amount').fill('80.00');
        await page.locator('#writeleash-free-max_decrease').fill('1');
        await action(page, 'Build frozen preview');
        ok((await page.locator('#wpbody-content').innerText()).includes('This plan cannot be executed.'), 'blocked saved category preview explains block');
        await capture(page, 'blocked-category');
        await page.goto(home + '&wl_view=history');
        await page.getByRole('link', { name: 'Review blocked plan', exact: true }).first().click();
        await page.waitForLoadState('networkidle');
        ok(await page.getByRole('button', { name: 'Approve and queue execution', exact: true }).count() === 0 && await page.getByRole('link', { name: 'Create a new preview', exact: true }).count() === 1, 'blocked review offers new preview, no approval');
        const after = observe().jobs[0];
        ok(JSON.parse(after.plan_json).resolved_product_ids.every(id => id !== fixture.products[1]), 'category preview excludes child-only product');
        await native.close();
        console.log('#167 real Chromium browser merchant selection/native fallback/frozen reopening: PASS (' + checks + ' assertions); browser=' + await browser.version());
    } finally { await browser.close(); }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
