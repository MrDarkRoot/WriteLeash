// Effective MO/Jed translation journey on the existing disposable #170 Admin lab.
const { chromium } = require(process.env.WL167_PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');
const base = process.env.WL167_BASE_URL, site = process.env.WL167_SITE;
const fixtureFile = process.env.WL210_FIXTURE;
const home = base + '/wp-admin/admin.php?page=writeleash-bulk-prices';
const out = process.env.WL167_EVIDENCE;
const longPreview = '[Ü] Preisänderungen mit ausführlicher Sicherheitsprüfung vor der ausdrücklichen Freigabe ansehen';
let checks = 0;
const results = [];
function eq(actual, expected, label) { assert.deepEqual(actual, expected, label); checks++; }
function fixture(mode) {
 const value = execFileSync('docker', ['exec', '-e', 'WL210_MODE=' + mode, '-e', 'WL210_FIXTURE=/tmp/wl210-fixture.json', process.env.WL167_CONTAINER, 'wp', '--path=' + site, 'eval-file', '/opt/tests/admin/i18n-browser-fixture.php'], {encoding: 'utf8'});
 if (mode === 'seed') {
  execFileSync('docker', ['cp', process.env.WL167_CONTAINER + ':/tmp/wl210-fixture.json', fixtureFile]);
  return JSON.parse(fs.readFileSync(fixtureFile));
 }
 return JSON.parse(value);
}
async function clickSubmit(page, locator) {
 await Promise.all([page.waitForNavigation({waitUntil: 'load'}), locator.click()]);
}
async function capture(page, name) {
 if (!out) return;
 fs.mkdirSync(out, {recursive: true});
 await page.screenshot({path: out + '/210-' + name + '.png', fullPage: true});
}
(async () => {
 const browser = await chromium.launch({executablePath: process.env.WL167_CHROME || '/usr/bin/google-chrome', args: ['--no-sandbox']});
 try {
  for (const js of [true, false]) {
   const f = fixture('seed');
   execFileSync('docker', ['exec', process.env.WL167_CONTAINER, 'wp', '--skip-plugins=writeleash', '--path=' + site, 'eval-file', '/opt/tests/admin/i18n-activation.php'], {stdio: 'inherit'});
   const original = fixture('observe');
   eq(original.locale_loaded, true, 'real PHP MO effective');
   eq(original.csv_machine_invariant, true, 'localized CSV machine columns and values stable');
   const context = await browser.newContext({javaScriptEnabled: js, acceptDownloads: true, viewport: {width: 1440, height: 900}});
   const page = await context.newPage();
   const errors = []; page.on('pageerror', e => errors.push(e.message));
   await page.goto(base + '/wp-login.php');
   await page.getByLabel('Username or Email Address').fill(f.username);
   await page.getByLabel('Password', {exact: true}).fill(f.password);
   await clickSubmit(page, page.getByRole('button', {name: 'Log In', exact: true}));
   await page.goto(home);
   eq(await page.locator('.writeleash-heading').innerText(), '[Ü] WriteLeash Bulk Prices', 'effective server heading translation');
   eq(await page.getByRole('button', {name: longPreview, exact: true}).count(), 1, 'long translated Preview accessible name');
   eq(await page.locator('#writeleash-free-selected').innerText(), '[Ü] No products selected. Search and choose products to add them.', 'empty selection translated');
   if (js) {
    eq(await page.evaluate(() => wp.i18n.__('Searching…', 'writeleash')), '[Ü] Searching…', 'actual Jed JSON loaded by wp_set_script_translations');
    eq(await page.locator('label[for=writeleash-free-amount]').innerText(), '[Ü] New price', 'JS amount label translated');
   }
   for (const width of [375, 782, 1440]) {
    await page.setViewportSize({width, height: 900});
    eq(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'long labels no page overflow ' + width);
    eq(await page.getByRole('button', {name: longPreview, exact: true}).isVisible(), true, 'long translated action usable ' + width);
   }
   if (js) {
    const search = page.locator('#writeleash-free-products + .select2-container .select2-search__field');
    await search.fill('Locale simple ' + f.actor);
    await page.locator('.select2-results__option[data-selected]').filter({hasText: 'Locale simple ' + f.actor}).first().click();
    const remove = page.getByRole('button', {name: /\[Ü\] Remove .*Locale simple/});
    eq(await remove.count(), 1, 'JS selected-product remove accessible name translated');
    eq(await page.locator('#writeleash-free-products').inputValue(), String(f.id), 'JS selected product ID unchanged');
    await remove.click();
    eq(await search.evaluate(el => el === document.activeElement), true, 'localized Remove returns keyboard focus to search');
    eq(await page.locator('#writeleash-free-selected').innerText(), '[Ü] No products selected. Search and choose products to add them.', 'JS empty selection remains translated');
    for (const count of [0, 1, 3]) {
     eq(await page.evaluate(n => wp.i18n.sprintf(wp.i18n._n('Choose at most %d product.', 'Choose at most %d products.', n, 'writeleash'), n), count), '[Ü] Choose at most ' + count + (count === 1 ? ' product.' : ' products.'), 'effective JS JSON plural ' + count);
    }
   }
   await page.locator('#writeleash-free-selector').selectOption('category');
   async function category(id, name) {
    if (js) {
     await page.locator('#writeleash-free-category + .select2-container .select2-selection').click();
     const search = page.locator('.select2-container--open .select2-search__field').last();
     await search.fill(name);
     await page.locator('.select2-results__option[data-selected]').filter({hasText: name}).last().click();
    } else {
     await page.locator('#writeleash-free-category_search').fill(name);
     await clickSubmit(page, page.getByRole('button', {name: '[Ü] Search categories', exact: true}));
     await page.locator('#writeleash-free-category').selectOption(String(id));
    }
   }
   await category(f.empty, 'Locale empty ' + f.actor);
   await clickSubmit(page, page.getByRole('button', {name: '[Ü] Check selection count', exact: true}));
   eq((await page.locator('#writeleash-free-selection-count').innerText()).startsWith('[Ü] No products match'), true, 'zero targets effective translated message');
   await category(f.category, 'Locale parent ' + f.actor);
   await clickSubmit(page, page.getByRole('button', {name: '[Ü] Check selection count', exact: true}));
   eq((await page.locator('#writeleash-free-selection-count').innerText()).includes('1 deduplicated price target after'), true, 'one target singular');
   await page.locator('#writeleash-free-include-subcategories').check();
   await clickSubmit(page, page.getByRole('button', {name: '[Ü] Check selection count', exact: true}));
   eq((await page.locator('#writeleash-free-selection-count').innerText()).includes('2 deduplicated price targets after'), true, 'many targets plural with variation and descendants');
   if (js) { eq((await page.locator('#writeleash-free-discovery-status').innerText()).startsWith('[Ü] Direct members'), true, 'JS descendant discovery feedback translated'); }
   await page.locator('#writeleash-free-amount').fill('invalid');
   await clickSubmit(page, page.getByRole('button', {name: longPreview, exact: true}));
   eq((await page.locator('.notice').first().innerText()).includes('[Ü] Use an unsigned decimal amount string'), true, 'real validation failure translated');
   await page.locator('#writeleash-free-amount').fill('80');
   await clickSubmit(page, page.getByRole('button', {name: longPreview, exact: true}));
   eq((await page.locator('.writeleash-summary').innerText()).includes('[Ü] Selected 2 products:'), true, 'Preview translated plural');
   const approvedJob = new URL(page.url()).searchParams.get('wl_job');
   const previewState = fixture('observe');
   const plan = previewState.jobs.find(j => j.public_id === approvedJob);
   eq(plan.status, 'PLANNED', 'explicit approval still required');
   eq(plan.data.resolved_product_ids.slice().sort((a,b) => a-b), [f.id, f.variation].sort((a,b) => a-b), 'submitted category identifiers resolve unchanged simple/variation targets');
   eq(plan.data.operation, {type: 'SET', input: '80', field: 'regular_price'}, 'operation authority untranslated');
   eq(plan.data.items.map(i => i.planned_regular_price), ['80.00','80.00'], 'canonical planned prices');
   eq(previewState.source, original.source, 'selection and Preview preserve historical source');
   await capture(page, 'preview-' + js);
   await clickSubmit(page, page.getByRole('button', {name: '[Ü] Approve and apply', exact: true}));
   eq((await page.locator('[data-progress-label]').innerText()).startsWith('[Ü]'), true, 'translated saved Apply progress');
   if (js) {
    const focused = page.getByRole('link', {name: '[Ü] Refresh saved progress', exact: true});
    await focused.focus();
    const response = await page.waitForResponse(r => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('writeleash_free_progress'));
    const data = await response.json();
    eq(data.success, true, 'real server-authoritative polling succeeds');
    eq(data.data.status, 'OK', 'poll machine status unchanged');
    eq(data.data.summary.startsWith('[Ü]'), true, 'effective translated poll summary');
    await page.waitForFunction(() => document.querySelector('[data-progress-announcement]').textContent.includes('[Ü]'));
    eq(await focused.evaluate(el => el === document.activeElement), true, 'translated polling preserves keyboard focus');
    eq(await page.getByRole('button', {name: '[Ü] Resume remaining products', exact: true}).count(), 1, 'poll retains meaningful accessible server control');
    await page.route('**/admin-ajax.php', route => route.fulfill({status: 403, body: ''}));
    await page.waitForFunction(() => document.querySelector('[data-progress-connection]').textContent.includes('Session or permission expired'), {timeout: 20000});
    eq((await page.locator('[data-progress-connection]').innerText()).startsWith('[Ü]'), true, 'controlled polling permission error translated');
    eq(await focused.evaluate(el => el === document.activeElement), true, 'error announcement preserves focus');
    await page.unroute('**/admin-ajax.php');
   }
   fixture('run'); await page.reload();
   const finished = fixture('observe');
   eq(finished.prices[String(f.id)], '80.00', 'simple canonical applied price');
   eq(finished.prices[String(f.variation)], '80.00', 'variation canonical applied price');
   eq(finished.jobs.find(j => j.public_id === approvedJob).hash, plan.hash, 'approval/apply keeps approved plan hash');
   eq(finished.source, original.source, 'Apply preserves older conflict source');
   await capture(page, 'apply-' + js);
   await clickSubmit(page, page.getByRole('button', {name: '[Ü] Restore eligible prices (Undo)', exact: true}));
   const undone = fixture('observe');
   eq(undone.prices, original.prices, 'translated no-JS-capable Undo restores canonical original prices');
   eq(undone.source, original.source, 'Undo preserves historic source journal');
   await capture(page, 'undo-' + js);
   await page.goto(home + '&wl_view=history');
   eq(await page.getByRole('heading', {name: '[Ü] History', exact: true}).count(), 1, 'History translated');
   eq(await page.getByRole('region', {name: '[Ü] Job history', exact: true}).count(), 1, 'History accessible region translated');
   await capture(page, 'history-' + js);
   await page.goto(home + '&wl_view=preview&wl_job=' + f.old_job);
   eq((await page.locator('.writeleash-summary').innerText()).includes('[Ü]'), true, 'pre-locale existing plan renders translated');
   await page.goto(home + '&wl_view=job&wl_job=' + f.source_job);
   eq((await page.locator('[data-product-id="' + f.id + '"] td').nth(4).innerText()).includes('[Ü] This product’s price'), true, 'real conflict guidance translated');
   eq(await page.locator('[data-product-id="' + f.id + '"] td').nth(4).getByRole('link', {name: 'Create a new preview', exact: true}).count(), 1, 'translated complete linked message preserves safe recovery link');
   // Template text receives one prefix for the whole sentence, not separate fragments.
   await page.getByRole('link', {name: '[Ü] Re-preview conflicted products', exact: true}).click();
   const chosen = page.locator('#writeleash-conflict-recovery-form [name="product_ids[]"]');
   eq(await chosen.isChecked(), false, 'recovery no default selection');
   await chosen.focus(); await page.keyboard.press('Space');
   eq(await chosen.isChecked(), true, 'translated recovery keyboard selection');
   await clickSubmit(page, page.getByRole('button', {name: longPreview, exact: true}));
   const recovered = fixture('observe');
   const recovery = recovered.jobs.find(j => j.public_id === new URL(page.url()).searchParams.get('wl_job'));
   eq(recovery.status, 'PLANNED', 'fresh translated recovery still requires approval');
   eq(recovery.data.source_job, f.source_job, 'untranslated recovery authorization reference');
   eq(recovery.data.resolved_product_ids, [f.id], 'source conflict membership unchanged');
   eq(recovery.data.items[0].planned_regular_price, '132.00', 'fresh Preview uses current canonical 120 plus 10 percent');
   eq(recovered.source, original.source, 'recovery preserves source job, items and journal bytes');
   eq(recovered.prices, original.prices, 'fresh Preview changes no prices');
   await capture(page, 'recovery-' + js);
   eq(errors, [], 'no JS runtime errors');
   results.push({js, locale: 'de_DE pseudo', checks, source_job: f.source_job, plan_hash: plan.hash, recovered_hash: recovery.hash, source_immutable: true, prices_canonical: true, csv_machine_invariant: true});
   await context.close();
  }
 } finally { await browser.close(); }
 if (out) fs.writeFileSync(out + '/210-i18n-result.json', JSON.stringify({checks, results}, null, 2));
 console.log('#210 real translated Admin: effective MO/Jed, zero/one/many, simple/variation, descendants, Preview/approval/Apply/poll/error/focus, History/Undo, existing plans/CSV and fresh conflict recovery JS/no-JS PASS (' + checks + ' checks)');
})().catch(error => { console.error(error); process.exitCode = 1; });
