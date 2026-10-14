// #256 focused real Admin regression, using the existing Playwright/WP-CLI fixture tools.
const pw = require(process.env.WL167_PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const site = process.env.WL167_SITE, base = process.env.WL167_BASE_URL;
if (!site || !base) throw new Error('Explicit disposable store required');
const wp = code => JSON.parse(execFileSync('wp', ['--path=' + site, 'eval', code], { encoding: 'utf8' }));
const home = base + '/wp-admin/admin.php?page=writeleash-bulk-prices';
const button = (page, name) => page.getByRole('button', { name, exact: true });
async function submit(page, target) {
    assert.deepEqual(await target.evaluate(el => [...el.form.querySelectorAll(':invalid')].map(e => e.id)), [], 'native form inputs valid');
    assert.ok(await target.isVisible(), 'button is visible');
    await target.focus();
    assert.ok(await target.evaluate(el => document.activeElement === el), 'button has keyboard focus');
    await Promise.all([page.waitForEvent('framenavigated', { predicate: frame => frame === page.mainFrame() }), target.dispatchEvent('click')]);
    await page.waitForLoadState('networkidle');
}
const fixture = wp(`
$tag=substr(wp_generate_uuid4(),0,8); $ids=array();
foreach(array('Napkins & linen','Stoneware bowl') as $name) { $p=new WC_Product_Simple(); $p->set_name($name); $p->set_sku('UX256-'.$tag.'-'.count($ids)); $p->set_status('publish'); $p->set_regular_price('100'); $ids[]=$p->save(); }
$p=new WC_Product_Variable(); $p->set_name('Duvet & cover'); $p->set_status('publish');
$a=new WC_Product_Attribute(); $a->set_name('size'); $a->set_options(array('Single','Double')); $a->set_variation(true); $p->set_attributes(array($a)); $parent=$p->save();
foreach(array('Single','Double') as $size) { $p=new WC_Product_Variation(); $p->set_parent_id($parent); $p->set_attributes(array('size'=>$size)); $p->set_sku('UX256-'.$tag.'-'.$size); $p->set_status('publish'); $p->set_regular_price('100'); $ids[]=$p->save(); }
echo wp_json_encode(array('ids'=>$ids,'tag'=>$tag));`);
const ids = fixture.ids.map(String);
const observe = () => wp(`global $wpdb; $prices=array(); foreach(array(${ids.join(',')}) as $id) { $p=wc_get_product($id); $prices[$id]=$p?$p->get_regular_price('edit'):null; } echo wp_json_encode(array('prices'=>$prices,'jobs'=>$wpdb->get_results('SELECT public_id,plan_json,approver_id FROM '.WriteLeash\\Job_Schema::jobs_table($wpdb).' ORDER BY id',ARRAY_A)));`);
(async () => {
    const browser = await pw.chromium.launch({ headless: true, executablePath: process.env.WL167_CHROME || '/usr/bin/google-chrome' });
    try {
        for (const javaScriptEnabled of [true, false]) {
            const context = await browser.newContext({ javaScriptEnabled });
            const page = await context.newPage();
            try {
                await page.goto(base + '/wp-login.php');
                await page.getByLabel('Username or Email Address').fill(process.env.WL256_USER || 'merchant');
                await page.getByLabel('Password', { exact: true }).fill(process.env.WL256_PASSWORD || 'merchant256-local');
                await submit(page, button(page, 'Log In'));
                for (const targets of [ids.slice(2), ids.slice(0,2)]) {
                    await page.goto(home); await page.waitForLoadState('networkidle');
                    await page.locator('#writeleash-free-selector').selectOption('manual_ids');
                    await page.locator('#writeleash-free-advanced-selection').evaluate(el => { el.open = true; });
                    await page.locator('#writeleash-free-ids').fill(targets.join(','));
                    await page.locator('#writeleash-free-operation').selectOption('INCREASE_FIXED');
                    await page.locator('#writeleash-free-amount').fill('5');
                    await page.locator('#writeleash-free-range-enabled').check();
                    await page.locator('#writeleash-free-range_min').fill('90');
                    await page.locator('#writeleash-free-range_max').fill('150');
                    await page.locator('#writeleash-free-range-basis').selectOption('regular_price');
                    const name = 'Labels ' + fixture.tag + ' ' + javaScriptEnabled + ' ' + targets[0];
                    await page.locator('#writeleash-preset-name').fill(name);
                    const before = observe();
                    await submit(page, button(page, 'Save as preset'));
                    await page.goto(home); await page.waitForLoadState('networkidle');
                    const preset = () => page.locator('.writeleash-preset').filter({ has: page.getByRole('heading', { name, exact: true }) });
                    await submit(page, preset().getByRole('button', { name: 'Load preset', exact: true }));
                    assert.equal(await page.locator('#writeleash-free-selector').inputValue(), 'ids');
                    assert.deepEqual((await page.locator('#writeleash-free-products option:checked').evaluateAll(els => els.map(e => e.value))).sort(), [...targets].sort());
                    const text = await page.locator('#writeleash-free-selected').innerText();
                    for (const id of targets) assert.ok(text.includes('ID: ' + id));
                    assert.ok(text.includes('SKU: UX256-' + fixture.tag));
                    assert.ok(targets[0] === ids[2] ? text.includes('Single') && text.includes('Double') : text.includes('Napkins & linen') && text.includes('Stoneware bowl'));
                    assert.equal(await page.locator('#writeleash-free-range_min').inputValue(), '90');
                    assert.equal(await page.locator('#writeleash-free-range_max').inputValue(), '150');
                    assert.equal(await page.locator('#writeleash-free-range-basis').inputValue(), 'regular_price');
                    assert.ok(await page.locator('#writeleash-free-range-enabled').isChecked());
                    assert.deepEqual(observe(), before, 'Save/Load perform no price writes or jobs/approval changes');
                    if (process.env.WL167_EVIDENCE) {
                        fs.mkdirSync(process.env.WL167_EVIDENCE, { recursive: true });
                        await page.screenshot({ path: process.env.WL167_EVIDENCE + '/256-' + javaScriptEnabled + '-' + targets[0] + '.png', fullPage: true });
                    }
                    wp(`$p=wc_get_product(${targets[1]});$p->set_regular_price('110');$p->save();echo 'true';`);
                    // Existing Remove controls edit the exact loaded population in both modes.
                    await submitIfNative(page, page.locator('#writeleash-free-selected li').filter({ hasText: 'ID: ' + targets[0] }).getByRole('button'), javaScriptEnabled);
                    assert.deepEqual(await page.locator('#writeleash-free-products option:checked').evaluateAll(els => els.map(e => e.value)), [targets[1]], 'Remove edits exact loaded IDs');
                    await page.locator('#writeleash-free-amount').fill('6');
                    await submit(page, button(page, 'Preview price changes'));
                    const job = observe().jobs.find(j => j.public_id === new URL(page.url()).searchParams.get('wl_job'));
                    assert.deepEqual(JSON.parse(job.plan_json).resolved_product_ids, [Number(targets[1])]);
                    assert.ok((await page.locator('.writeleash-admin').innerText()).includes('116.00'));
                    assert.equal(Number(job.approver_id), 0);
                    assert.equal(await button(page, 'Approve and apply').count(), 2);
                    await page.goto(home); await submit(page, preset().getByRole('button', { name: 'Delete preset', exact: true }));
                }
                // A deleted target must remain visible and selected, alongside readable products.
                const deleted = String(wp(`$p=new WC_Product_Simple();$p->set_name('Deleted target');$p->set_status('publish');$p->set_regular_price('100');echo wp_json_encode($p->save());`));
                await page.goto(home); await page.waitForLoadState('networkidle');
                await page.locator('#writeleash-free-selector').selectOption('manual_ids');
                const advanced = page.locator('#writeleash-free-advanced-selection > summary');
                if (!await advanced.evaluate(el => el.parentElement.open)) await advanced.press('Enter');
                await page.locator('#writeleash-free-ids').fill(ids[0] + ',' + deleted);
                await page.locator('#writeleash-free-amount').fill('100');
                const missingName = 'Missing ' + fixture.tag + ' ' + javaScriptEnabled;
                await page.locator('#writeleash-preset-name').fill(missingName);
                await submit(page, button(page, 'Save as preset'));
                wp(`wp_delete_post(${deleted},true);echo 'true';`);
                const missing = () => page.locator('.writeleash-preset').filter({ has: page.getByRole('heading', { name: missingName, exact: true }) });
                await submit(page, missing().getByRole('button', { name: 'Load preset', exact: true }));
                assert.deepEqual((await page.locator('#writeleash-free-products option:checked').evaluateAll(els => els.map(e => e.value))).sort(), [ids[0], deleted].sort());
                assert.ok((await page.locator('#writeleash-free-selected').innerText()).includes('Product #' + deleted + ' unavailable or inaccessible. ID retained;'));
                assert.ok((await page.locator('#writeleash-free-selected').innerText()).includes('Napkins & linen'));
                await submitIfNative(page, page.locator('#writeleash-free-selected li').filter({ hasText: 'Product #' + deleted }).getByRole('button'), javaScriptEnabled);
                assert.deepEqual(await page.locator('#writeleash-free-products option:checked').evaluateAll(els => els.map(e => e.value)), [ids[0]]);
                await submit(page, missing().getByRole('button', { name: 'Delete preset', exact: true }));
                console.log('#256 JS=' + javaScriptEnabled + ' variation/simple labels, exact IDs, editable fresh prices, range, no writes, new approval PASS');
            } finally { await context.close(); }
        }
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
async function submitIfNative(page, target, enhanced) {
    if (enhanced) { assert.ok(await target.isVisible()); await target.dispatchEvent('click'); await page.waitForLoadState('networkidle'); } else await submit(page, target);
}
