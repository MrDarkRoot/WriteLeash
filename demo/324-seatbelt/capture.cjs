#!/usr/bin/env node
/**
 * Drive the disposable WriteLeash admin and record the #324 scenes.
 * Passwords stay in the secrets file. The recording starts after login.
 */
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const pwRoot = process.env.DEMO324_PLAYWRIGHT_ROOT;
const playwright = require(path.join(pwRoot, 'playwright'));
const base = process.env.DEMO324_BASE_URL;
const container = process.env.DEMO324_CONTAINER;
const site = process.env.DEMO324_SITE;
const outDir = process.env.DEMO324_OUT;
const secrets = JSON.parse(fs.readFileSync(process.env.DEMO324_SECRETS, 'utf8'));

if (!base || !container || !site || !outDir) {
  throw new Error('DEMO324_BASE_URL, DEMO324_CONTAINER, DEMO324_SITE and DEMO324_OUT are required');
}

const home = base + '/wp-admin/edit.php?post_type=product&page=writeleash-bulk-prices';
const frames = path.join(outDir, 'frames');
const videoDir = path.join(outDir, 'video');
fs.mkdirSync(frames, { recursive: true });
fs.mkdirSync(videoDir, { recursive: true });

const scenes = [];
let t0 = 0;
let frame = 0;
function mark(id) {
  scenes.push({ id, t: (Date.now() - t0) / 1000 });
  console.log(`scene ${id} at ${scenes.at(-1).t.toFixed(2)}s`);
}
async function hold(page, seconds) {
  const start = Date.now();
  const steps = Math.max(1, Math.round(seconds * 2));
  for (let i = 0; i < steps; i++) {
    const due = start + Math.round((i * seconds * 1000) / steps);
    const wait = due - Date.now();
    if (wait > 0) await page.waitForTimeout(wait);
    const file = path.join(frames, `frame-${String(frame).padStart(3, '0')}.png`);
    await page.screenshot({ path: file, animations: 'disabled' });
    frame += 1;
  }
  const tail = start + seconds * 1000 - Date.now();
  if (tail > 0) await page.waitForTimeout(tail);
}
function wp(args, env = {}) {
  return execFileSync('docker', ['exec', '-i', ...Object.entries(env).flatMap(([k, v]) => ['-e', `${k}=${v}`]), container, 'wp', `--path=${site}`, ...args], {
    encoding: 'utf8',
  });
}
function observe(label) {
  const remote = `/tmp/wl324-${label}.json`;
  wp(['eval-file', '/opt/demo/observe.php'], { WL324_OUT: remote, WL324_PRODUCT_ID: String(secrets.product_id) });
  const local = path.join(outDir, `${label}.json`);
  execFileSync('docker', ['cp', `${container}:${remote}`, local]);
  return JSON.parse(fs.readFileSync(local, 'utf8'));
}
async function loginState(browser, who) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  await page.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill(who);
  await page.locator('#user_pass').fill(secrets.passwords[who]);
  await Promise.all([
    page.waitForURL(/wp-admin/, { timeout: 60000 }),
    page.locator('#wp-submit').click(),
  ]);
  const state = await context.storageState();
  await context.close();
  return state;
}
async function adopt(context, state) {
  await context.clearCookies();
  if (state.cookies.length) await context.addCookies(state.cookies);
}
function priceOf(snapshot) {
  return snapshot.focus && snapshot.focus.prices ? snapshot.focus.prices._regular_price : undefined;
}

(async () => {
  const browser = await playwright.chromium.launch({
    headless: true,
    executablePath: process.env.DEMO324_CHROME || '/usr/bin/google-chrome',
    args: ['--force-device-scale-factor=1'],
  });
  const morgan = await loginState(browser, 'morgan');
  const casey = await loginState(browser, 'casey');
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    storageState: morgan,
    recordVideo: { dir: videoDir, size: { width: 1440, height: 900 } },
  });
  const page = await context.newPage();
  page.setDefaultTimeout(30000);
  page.setDefaultNavigationTimeout(60000);
  t0 = Date.now();
  try {
    mark('open');
    await page.goto(home, { waitUntil: 'domcontentloaded' });
    await page.getByRole('heading', { name: 'WriteLeash Bulk Prices' }).waitFor();
    await page.locator('#writeleash-free-selector').selectOption('sku');
    await page.locator('#writeleash-free-sku').waitFor({ state: 'visible' });
    await page.locator('#writeleash-free-sku').fill('WL-DEMO-TOTE');
    await page.locator('#writeleash-free-price-field').selectOption('regular_price');
    await page.locator('#writeleash-free-operation').selectOption('DECREASE_PERCENT');
    await page.locator('#writeleash-free-amount').fill('20');
    mark('form');
    await hold(page, 4);

    mark('preview-submit');
    await Promise.all([
      page.waitForURL(/wl_view=preview/, { timeout: 60000 }),
      page.getByRole('button', { name: 'Preview price changes', exact: true }).click(),
    ]);
    await page.getByText('No prices have changed', { exact: false }).waitFor();
    const previewText = await page.locator('#wpbody-content').innerText();
    if (!previewText.includes('80.00') || !previewText.includes('100.00')) {
      throw new Error('Preview did not show the expected $100 to $80 prices');
    }
    const duringPreview = observe('during-preview');
    if (priceOf(duringPreview) !== '100.00') {
      throw new Error('Preview wrote the product price: ' + priceOf(duringPreview));
    }
    const previewURL = page.url();
    mark('preview');
    await hold(page, 8);

    mark('colleague');
    await adopt(context, casey);
    await page.goto(base + '/wp-admin/edit.php?post_type=product', { waitUntil: 'domcontentloaded' });
    const row = page.locator(`#post-${secrets.product_id}`);
    await row.hover();
    await row.locator('.editinline').click();
    const inline = page.locator(`#edit-${secrets.product_id}`);
    await inline.locator('input[name="_regular_price"]').fill('120.00');
    mark('colleague-price');
    await hold(page, 4);
    await inline.locator('button.save').click();
    await page.waitForFunction((id) => {
      const cell = document.querySelector('#post-' + id + ' .column-price, #post-' + id + ' td.price');
      return cell && cell.textContent.includes('120');
    }, secrets.product_id);
    const afterEdit = observe('after-colleague');
    if (priceOf(afterEdit) !== '120.00') {
      throw new Error('Colleague edit did not store 120.00, saw ' + priceOf(afterEdit));
    }
    mark('colleague-saved');
    await hold(page, 4);

    mark('approve');
    await adopt(context, morgan);
    await page.goto(previewURL, { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: 'Approve and apply', exact: true }).first().click();
    await page.waitForLoadState('domcontentloaded');
    for (let attempt = 0; attempt < 5; attempt++) {
      if (await page.getByText('Newer price kept', { exact: false }).count()) break;
      const resume = page.getByRole('button', { name: 'Resume remaining products', exact: true });
      if (await resume.count()) {
        mark(attempt === 0 ? 'resume' : 'resume-again');
        await resume.first().click();
        await page.waitForLoadState('domcontentloaded');
        continue;
      }
      await page.waitForTimeout(1000);
      await page.reload({ waitUntil: 'domcontentloaded' });
    }
    const kept = page.getByText('Newer price kept', { exact: true }).first();
    await kept.waitFor();
    await kept.evaluate((el) => el.scrollIntoView({ block: 'center', inline: 'nearest' }));
    await page.getByText('left the newer value unchanged', { exact: false }).waitFor();
    const resultText = await page.locator('#wpbody-content').innerText();
    for (const needle of ['100.00', '120.00', '80.00', 'Newer price kept', 'left the newer value unchanged']) {
      if (!resultText.includes(needle)) throw new Error('Conflict screen missing: ' + needle);
    }
    const afterConflict = observe('after-conflict');
    if (priceOf(afterConflict) !== '120.00') {
      throw new Error('Apply overwrote the newer price: ' + priceOf(afterConflict));
    }
    mark('conflict');
    const poster = path.join(outDir, 'poster-source.png');
    await page.screenshot({ path: poster });
    await hold(page, 10);

    const beforeDeny = observe('before-deny');
    execFileSync('docker', ['exec', container, 'sh', '-c', `: > ${site}/wp-content/debug.log`]);
    const bodyFile = path.join(outDir, 'rest-body.json');
    const headerFile = path.join(outDir, 'rest-headers.txt');
    const restPath = `/wp-json/wc/v3/products/${secrets.product_id}`;
    try {
      execFileSync('curl', [
        '-sS',
        '-D', headerFile,
        '-o', bodyFile,
        '-u', `riley:${secrets.passwords.riley_app}`,
        '-H', 'Content-Type: application/json',
        '-X', 'PUT',
        '--data', JSON.stringify({ regular_price: '49.99' }),
        base + restPath,
      ], { encoding: 'utf8' });
    } catch (error) {
      const status = error.status;
      if (!fs.existsSync(bodyFile)) {
        throw new Error('REST request failed before a response body (curl exit ' + status + ')');
      }
    }
    const statusLine = fs.readFileSync(headerFile, 'utf8').split(/\r?\n/).find((line) => line.startsWith('HTTP/'));
    const httpStatus = statusLine ? Number(statusLine.trim().split(/\s+/)[1]) : 0;
    const responseBody = fs.readFileSync(bodyFile, 'utf8');
    let parsed = {};
    try { parsed = JSON.parse(responseBody); } catch (_) { parsed = {}; }
    const afterDeny = observe('after-deny');
    const auditRaw = execFileSync('docker', ['exec', container, 'sh', '-c', `grep writeleash_perimeter_denied ${site}/wp-content/debug.log || true`], { encoding: 'utf8' }).trim();
    const auditLine = auditRaw.split(/\n/).filter(Boolean).at(-1) || '';
    let audit = {};
    try { audit = JSON.parse(auditLine); } catch (_) { audit = {}; }
    if (httpStatus !== 403) throw new Error('Expected HTTP 403, saw ' + httpStatus + ' body ' + responseBody.slice(0, 400));
    if (parsed.code !== 'writeleash_perimeter_denied') throw new Error('Unexpected denial code: ' + responseBody.slice(0, 400));
    if (priceOf(afterDeny) !== '120.00') throw new Error('Denied write changed the price to ' + priceOf(afterDeny));
    if (beforeDeny.sha256 !== afterDeny.sha256) throw new Error('Denied write changed product rows');
    if (audit.code !== 'writeleash_perimeter_denied' || audit.where !== 'rest' || audit.route !== `/wc/v3/products/${secrets.product_id}`) {
      throw new Error('Audit line did not record this REST denial: ' + auditLine);
    }

    const evidence = {
      request: {
        method: 'PUT',
        path: restPath,
        caller: 'Riley Agent, shop manager, restricted by a WriteLeash grant. Application password redacted.',
        body: '{"regular_price":"49.99"}',
      },
      response: {
        status: httpStatus,
        status_line: statusLine || '',
        code: parsed.code,
        message: parsed.message || '',
        body: JSON.stringify(parsed, null, 2),
      },
      product: {
        regular_price_before: priceOf(beforeDeny),
        regular_price_after: priceOf(afterDeny),
        rows_unchanged: beforeDeny.sha256 === afterDeny.sha256,
        sha256: afterDeny.sha256,
      },
      conflict: {
        regular_price_after_apply: priceOf(afterConflict),
        expected: '100.00',
        planned: '80.00',
        kept: '120.00',
      },
      audit: { line: auditLine, parsed: audit },
      counts: { before: beforeDeny.counts, after: afterDeny.counts },
    };
    fs.writeFileSync(path.join(outDir, 'evidence.json'), JSON.stringify(evidence, null, 2));
    execFileSync('docker', ['cp', path.join(outDir, 'evidence.json'), `${container}:${site}/demo-evidence.json`]);
    mark('denial');
    await page.goto(base + '/demo-board.html', { waitUntil: 'domcontentloaded' });
    await page.getByText('HTTP 403').waitFor();
    await page.getByText('Unchanged').waitFor();
    await hold(page, 8);
    mark('end');

    const vtt = buildVtt(scenes, evidence);
    fs.writeFileSync(path.join(outDir, 'captions.en.vtt'), vtt);
    fs.writeFileSync(path.join(outDir, 'scenes.json'), JSON.stringify(scenes, null, 2));
    console.log('capture assertions passed');
  } catch (error) {
    const failed = path.join(outDir, 'failure.png');
    await page.screenshot({ path: failed, fullPage: true }).catch(() => {});
    const text = await page.locator('body').innerText().catch(() => '');
    fs.writeFileSync(path.join(outDir, 'failure.txt'), String(error && error.stack || error) + '\n\n' + text.slice(0, 4000));
    throw error;
  } finally {
    await context.close();
    await browser.close();
  }
})().catch((error) => {
  let text = String(error && error.stack || error);
  for (const value of Object.values(secrets.passwords || {})) {
    if (value) text = text.split(value).join('[redacted]');
  }
  console.error(text);
  process.exit(1);
});

function ts(seconds) {
  const ms = Math.max(0, Math.round(seconds * 1000));
  const h = Math.floor(ms / 3600000);
  const m = Math.floor((ms % 3600000) / 60000);
  const s = Math.floor((ms % 60000) / 1000);
  const milli = ms % 1000;
  return [h, m, s].map((n) => String(n).padStart(2, '0')).join(':') + '.' + String(milli).padStart(3, '0');
}
function buildVtt(scenes, evidence) {
  const at = Object.fromEntries(scenes.map((scene) => [scene.id, scene.t]));
  const end = at.end;
  const cues = [
    [at.open, at.preview, 'Morgan Merchant opens Bulk Prices for the fictional product Northwind Canvas Tote. Its regular price is $100.00. The 20 percent decrease is only a preview.'],
    [at.preview, at.colleague, 'The preview plans $80.00 from $100.00. The notice says no prices have changed yet. The stored regular price is still $100.00.'],
    [at.colleague, at.approve, 'Casey Colleague, a second unrestricted user, sets the same regular price to $120.00 in WooCommerce and updates the product.'],
    [at.approve, at.conflict, at.resume !== undefined
      ? 'Morgan approves the original preview. Apply compares the stored price with the reviewed price. Resume runs the waiting Apply step.'
      : 'Morgan approves the original preview. Apply compares the stored price with the reviewed price.'],
    [at.conflict, at.denial, 'The result is a conflict. Expected $100.00, now $120.00, planned $80.00. WriteLeash left the newer value unchanged.'],
    [at.denial, end, `Riley Agent is a shop manager restricted by a WriteLeash grant. PUT ${evidence.request.path} asking for regular price 49.99 returns HTTP ${evidence.response.status} ${evidence.response.code}. The product rows are unchanged and the debug log records the denial for that route. This denial is from the repository build, not the WordPress.org 0.2.2 plugin.`],
  ];
  const lines = ['WEBVTT', ''];
  for (const [start, stop, text] of cues) {
    if (!(stop > start)) continue;
    lines.push(`${ts(start)} --> ${ts(stop)}`);
    lines.push(text);
    lines.push('');
  }
  return lines.join('\n');
}
