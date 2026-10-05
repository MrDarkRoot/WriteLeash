// #182 listing-ready screenshots from the real disposable Woo Admin site.
// Consumed by the existing wordpress-woo-admin CI artifact; deterministic
// 1280px full-page captures, never fabricated and never committed here.
const { chromium } = require(process.env.WL167_PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const base = process.env.WL167_BASE_URL;
const file = process.env.WL167_LISTING_FIXTURE;
if (!base || !file) throw new Error('Explicit disposable #182 site/URL/fixture required');
const evidence = process.env.WL167_EVIDENCE;
const fixture = JSON.parse(fs.readFileSync(file, 'utf8'));
const home = base + '/wp-admin/admin.php?page=writeleash-bulk-prices';
let checks = 0;
function ok(value, label) { assert.ok(value, label); checks++; }
async function capture(page, name) {
    if (!evidence) return;
    fs.mkdirSync(evidence, { recursive: true });
    await page.evaluate(() => { if (document.activeElement) document.activeElement.blur(); window.scrollTo(0, 0); });
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: evidence + '/' + name + '.png', fullPage: true });
}
async function login(page) {
    await page.goto(base + '/wp-login.php');
    await page.waitForLoadState('networkidle');
    await page.getByLabel('Username or Email Address').fill(fixture.username);
    await page.getByLabel('Password', { exact: true }).fill(fixture.password);
    await Promise.all([page.waitForURL(/wp-admin/), page.getByRole('button', { name: 'Log In', exact: true }).click()]);
}
function searchInput(page) { return page.locator('#writeleash-free-products + .select2-container .select2-search__field'); }
async function selectThree(page) {
    const input = searchInput(page);
    const status = page.locator('#writeleash-free-discovery-status');
    const options = page.locator('.select2-results__option[data-selected="false"]').filter({ hasText: fixture.search });
    for (let i = 0; i < 3; i++) {
        // Selecting a product clears the multiple picker's result list, so
        // re-issue the query before every choice. Retry once when SelectWoo
        // drops a query keyed before its dropdown settles.
        let ready = false;
        for (let attempt = 0; attempt < 2 && !ready; attempt++) {
            try {
                await input.waitFor();
                await input.click();
                await input.fill('');
                await input.pressSequentially(fixture.search, { delay: 20 });
                await status.filter({ hasText: /Choose matches|No matches|Search limit/ }).waitFor({ timeout: 30000 });
                await options.first().waitFor({ timeout: 30000 });
                ready = true;
            } catch (error) {
                if (attempt === 1) {
                    console.error('SelectWoo diagnostics:', JSON.stringify(await page.evaluate(() => ({
                        status: document.getElementById('writeleash-free-discovery-status').textContent,
                        chosen: document.querySelectorAll('#writeleash-free-selected button').length,
                        options: Array.from(document.querySelectorAll('.select2-results__option')).map(option => option.textContent)
                    }))));
                    await capture(page, 'failed-selection');
                    throw error;
                }
                await page.keyboard.press('Escape').catch(() => {});
            }
        }
        await options.first().click();
    }
    ok(await page.locator('#writeleash-free-selected button').count() === 3, 'selection capture shows three chosen products');
}
(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.WL167_CHROME || '/usr/bin/google-chrome' });
    try {
        const page = await (await browser.newContext()).newPage();
        await page.setViewportSize({ width: 1280, height: 900 });
        await login(page);

        await page.goto(home);
        await page.waitForLoadState('networkidle');
        ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'selection screen fits 1280px');
        await selectThree(page);
        await searchInput(page).press('Escape');
        await page.waitForTimeout(200);
        ok(await page.getByRole('button', { name: 'Preview price changes', exact: true }).count() === 1, 'single primary preview action visible');
        await capture(page, 'listing-1-select');

        await page.goto(home + '&wl_view=preview&wl_job=' + fixture.jobs.regular_preview);
        await page.waitForLoadState('networkidle');
        ok((await page.locator('#wpbody-content').innerText()).includes('Regular price before'), 'regular-price preview captured');
        await capture(page, 'listing-2-preview');

        await page.goto(home + '&wl_view=preview&wl_job=' + fixture.jobs.sale_preview);
        await page.waitForLoadState('networkidle');
        ok((await page.locator('#wpbody-content').innerText()).includes('Sale price before'), 'sale-price preview captured');
        await capture(page, 'listing-2b-preview-sale');

        await page.goto(home + '&wl_view=job&wl_job=' + fixture.jobs.progress);
        await page.waitForLoadState('networkidle');
        const progress = await page.locator('#wpbody-content').innerText();
        ok(progress.includes('remaining') && progress.includes('Progress and results'), 'large-job progress captured');
        await capture(page, 'listing-3-progress');

        await page.goto(home + '&wl_view=job&wl_job=' + fixture.jobs.results);
        await page.waitForLoadState('networkidle');
        const results = await page.locator('#wpbody-content').innerText();
        ok(results.includes('changed') && results.includes('already at the target price'), 'results capture separates changed and unchanged');
        await capture(page, 'listing-4-results');

        await page.goto(home + '&wl_view=job&wl_job=' + fixture.jobs.conflicts);
        await page.waitForLoadState('networkidle');
        ok((await page.locator('#wpbody-content').innerText()).includes('left the newer value unchanged'), 'conflict capture explains the preserved newer value');
        await capture(page, 'listing-5-conflicts');

        await page.goto(home + '&wl_view=history');
        await page.waitForLoadState('networkidle');
        ok((await page.locator('#wpbody-content').innerText()).includes('Undo'), 'history capture shows Undo availability');
        await capture(page, 'listing-6-history');

        console.log('#182 listing captures: PASS (' + checks + ' assertions); width=1280 fullPage; evidence=' + (evidence || '(disabled)'));
    } finally { await browser.close(); }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
