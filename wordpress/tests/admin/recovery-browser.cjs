// Real HTTP merchant recovery journey; canonical #170 evidence remains separate.
const { chromium } = require(process.env.WL167_PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');
const base = process.env.WL167_BASE_URL, site = process.env.WL167_SITE;
const f = JSON.parse(fs.readFileSync(process.env.WL205_FIXTURE));
const home = base + '/wp-admin/admin.php?page=writeleash-bulk-prices';
let checks = 0;
function eq(a, b, label) { assert.deepEqual(a, b, label); checks++; }
function observe(mode = 'observe') {
 return JSON.parse(execFileSync('docker', ['exec', '-e', 'WL205_MODE=' + mode, '-e', 'WL205_FIXTURE=/tmp/wl205-fixture.json', process.env.WL167_CONTAINER, 'wp', '--path=' + site, 'eval-file', '/opt/tests/admin/recovery-browser-fixture.php'], { encoding: 'utf8' }));
}
(async () => {
 const browser = await chromium.launch({ executablePath: process.env.WL167_CHROME || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
 try {
  for (const js of [true, false]) {
   // Each mode uses a new source fixture and actor; never share approved browser state.
   if (!js) {
    execFileSync('docker', ['exec', '-e', 'WL205_MODE=seed', '-e', 'WL205_FIXTURE=/tmp/wl205-fixture.json', process.env.WL167_CONTAINER, 'wp', '--path=' + site, 'eval-file', '/opt/tests/admin/recovery-browser-fixture.php']);
    execFileSync('docker', ['cp', process.env.WL167_CONTAINER + ':/tmp/wl205-fixture.json', process.env.WL205_FIXTURE]);
    Object.assign(f, JSON.parse(fs.readFileSync(process.env.WL205_FIXTURE)));
   }
   const context = await browser.newContext({ javaScriptEnabled: js, viewport: { width: 1440, height: 900 } });
   const page = await context.newPage();
   await page.goto(base + '/wp-login.php');
   await page.getByLabel('Username or Email Address').fill(f.username);
   await page.getByLabel('Password', { exact: true }).fill(f.password);
   await Promise.all([page.waitForURL('**/wp-admin/**'), page.getByRole('button', { name: 'Log In', exact: true }).click()]);
   const original = observe();
   await page.goto(home + '&wl_view=job&wl_job=' + f.job);
   const link = page.getByRole('link', { name: 'Re-preview conflicted products', exact: true });
   eq(await link.count(), 1, 'eligible entry');
   await link.click(); await page.waitForURL('**wl_view=recovery**');
   const row = page.getByRole('checkbox', { name: /Recovery browser product/ });
   eq(await row.isChecked(), false, 'empty default');
   eq(await page.locator('[name=operation]').inputValue(), 'INCREASE_PERCENT', 'original operation suggestion');
   eq(await page.locator('[name=amount]').inputValue(), '10', 'original amount suggestion');
   eq(observe(), original, 'open has no side effects');
   await page.getByRole('link', { name: 'Cancel and return to results' }).click();
   eq(observe(), original, 'cancel no side effects');
   await page.getByRole('link', { name: 'Re-preview conflicted products', exact: true }).click();
   await row.focus(); await page.keyboard.press('Space'); eq(await row.isChecked(), true, 'keyboard choice');
   // Invalid inputs must retain the merchant's edited suggestions without creating work.
   await page.getByRole('checkbox', { name: 'Block a preview that sets any changing price to zero', exact: true }).uncheck();
   await page.locator('[name=amount]').fill('invalid');
   await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.getByRole('button', { name: 'Preview price changes', exact: true }).click()]);
   eq(await row.isChecked(), true, 'chosen population retained after validation error');
   eq(await page.locator('[name=amount]').inputValue(), 'invalid', 'edited amount retained');
   const limits = page.locator('#writeleash-free-safety-limits');
   if (process.env.WL167_EVIDENCE) {
    fs.mkdirSync(process.env.WL167_EVIDENCE, { recursive: true });
    await page.screenshot({ path: process.env.WL167_EVIDENCE + '/205-invalid-' + js + '.png', fullPage: true });
   }
   if (!(await limits.evaluate(el => el.open))) {
    await limits.locator('summary').focus(); await page.keyboard.press('Enter');
   }
   eq(await limits.evaluate(el => el.open), true, 'keyboard opens optional safety controls');
   eq(await page.getByRole('checkbox', { name: 'Block a preview that sets any changing price to zero', exact: true }).isChecked(), false, 'unchecked policy stays unchecked');
   eq(observe(), original, 'invalid submission has no durable side effects');
   await page.locator('[name=amount]').fill('10');
   if (process.env.WL167_EVIDENCE) {
    fs.mkdirSync(process.env.WL167_EVIDENCE, { recursive: true });
    await page.screenshot({ path: process.env.WL167_EVIDENCE + '/205-recovery-' + js + '.png', fullPage: true });
   }
   await Promise.all([page.waitForURL('**wl_view=preview**'), page.getByRole('button', { name: 'Preview price changes', exact: true }).click()]);
   const newJob = new URL(page.url()).searchParams.get('wl_job');
   eq(newJob !== f.job, true, 'distinct job');
   const observed = observe(); eq(observed.price, '120', 'preview never writes'); eq(observed.source, original.source, 'source immutable');
   eq(observed.jobs.length, 2, 'only one new job');
   const plan = JSON.parse(observed.jobs[1].plan_json);
   eq(plan.items[0].planned_regular_price, '132.00', 'current price arithmetic'); eq(plan.source_job, f.job, 'provenance');
   eq(plan.resolved_product_ids, [f.id], 'exact chosen population'); eq(plan.policy_snapshot.block_zero, false, 'edited safety setting frozen');
   eq(await page.getByRole('button', { name: 'Approve and apply', exact: true }).count(), 1, 'normal explicit approval');
   if (process.env.WL167_EVIDENCE) await page.screenshot({ path: process.env.WL167_EVIDENCE + '/205-preview-' + js + '.png', fullPage: true });
   await Promise.all([page.waitForURL('**wl_view=job**'), page.getByRole('button', { name: 'Approve and apply', exact: true }).click()]);
   const applied = observe('run'); eq(applied.price, '132', 'normal Apply'); eq(applied.source, original.source, 'source unchanged after Apply');
   await page.reload(); eq(await page.locator('tr[data-product-id="' + f.id + '"] td').nth(4).locator('strong').innerText(), 'Changed', 'result visible');
   await context.close();
  }
  const result = { issue: 205, checks, outcome: 'PASS', modes: ['javascript', 'no-javascript'] };
  if (process.env.WL167_EVIDENCE) fs.writeFileSync(process.env.WL167_EVIDENCE + '/205-recovery-result.json', JSON.stringify(result, null, 2));
  console.log('#205 browser recovery: PASS (' + checks + ' checks; JS/no-JS)');
 } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exit(1); });
