/**
 * Drive AddSynapse's real chat and Approve button.
 * Passwords stay in the secrets file. The recording starts after login.
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require(path.join(process.env.DEMO324_PLAYWRIGHT_ROOT, 'playwright'));

const base = process.env.DEMO324_BASE_URL;
const container = process.env.DEMO324_CONTAINER;
const site = process.env.DEMO324_SITE;
const out = process.env.DEMO324_OUT;
const secrets = JSON.parse(fs.readFileSync(process.env.DEMO324_SECRETS, 'utf8'));
const chatUrl = `${base}/wp-admin/admin.php?page=addsynapse-store-assistant`;
const scenes = [];
let t0 = 0;
let pageRef = null;

function mark(id) {
  scenes.push({ id, t: Number(((Date.now() - t0) / 1000).toFixed(3)) });
}

function wpEval(code) {
  return execFileSync(
    'docker',
    ['exec', container, 'wp', `--path=${site}`, 'eval', code],
    { encoding: 'utf8' }
  ).replace(/\r/g, '').trim();
}

function observe(label) {
  const remote = `/tmp/wl324ai-${label}.json`;
  execFileSync(
    'docker',
    ['exec', '-e', `WL324_OUT=${remote}`, container, 'wp', `--path=${site}`, 'eval-file', '/opt/demo/observe.php'],
    { encoding: 'utf8' }
  );
  const local = path.join(out, `${label}.json`);
  execFileSync('docker', ['cp', `${container}:${remote}`, local]);
  return JSON.parse(fs.readFileSync(local, 'utf8'));
}

function regularPrice(id) {
  const raw = wpEval(`$p = wc_get_product(${Number(id)}); if ( ! $p ) { echo 'missing'; return; } echo $p->get_regular_price();`);
  return raw.split('\n').pop().trim();
}

function auditLines() {
  const log = execFileSync(
    'docker',
    ['exec', container, 'sh', '-c', `cat ${site}/wp-content/debug.log 2>/dev/null || true`],
    { encoding: 'utf8' }
  );
  const rows = [];
  for (const line of log.split('\n')) {
    const start = line.indexOf('{');
    if (start < 0 || !line.includes('writeleash_perimeter_denied')) continue;
    try {
      rows.push(JSON.parse(line.slice(start)));
    } catch (error) {
      rows.push({ unparsed: line.slice(start) });
    }
  }
  return rows;
}

async function localOnly(context) {
  await context.route('**/*', (route) => {
    const url = route.request().url();
    if (url.startsWith(base) || url.startsWith('data:')) return route.continue();
    return route.abort();
  });
}

async function openChat(page) {
  await page.goto(chatUrl, { waitUntil: 'commit', timeout: 180000 });
  await composer(page);
}

async function login(browser, who) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await localOnly(context);
  const page = await context.newPage();
  page.setDefaultTimeout(90000);
  pageRef = page;
  await page.goto(`${base}/wp-login.php`, { waitUntil: 'commit', timeout: 180000 });
  await page.locator('#user_login').fill(who);
  await page.locator('#user_pass').fill(secrets.passwords[who]);
  await page.locator('#wp-submit').click();
  await page.waitForURL(/wp-admin/);
  return { context, page };
}

async function composer(page) {
  const input = page.locator('#addsynapse-assistant-input');
  await input.waitFor({ timeout: 90000 });
  await page.waitForFunction(() => {
    const el = document.querySelector('#addsynapse-assistant-input');
    return el && !el.disabled;
  });
  return input;
}

async function send(page, text, typed) {
  const input = await composer(page);
  await input.click();
  if (typed) {
    await input.fill('');
    await input.pressSequentially(text, { delay: 28 });
  } else {
    await input.fill(text);
  }
  await input.press('Enter');
}

function approveButton(page) {
  return page.getByRole('button', { name: 'Approve', exact: true }).last();
}

function confirmButton(page) {
  return page.getByRole('button', { name: 'Yes, apply the changes', exact: true }).last();
}

async function openApplyConfirm(page) {
  const approve = approveButton(page);
  await approve.waitFor({ timeout: 45000 });
  // The slash-command reply remounts this card when the thread reloads.
  // Wait, then retry, so the confirm step belongs to the settled card.
  await page.waitForTimeout(1500);
  for (let attempt = 0; attempt < 3; attempt += 1) {
    if (await confirmButton(page).isVisible().catch(() => false)) break;
    await approve.scrollIntoViewIfNeeded();
    await approve.click();
    try {
      await confirmButton(page).waitFor({ timeout: 5000 });
      break;
    } catch (error) {
      if (attempt === 2) throw error;
    }
  }
  await confirmButton(page).scrollIntoViewIfNeeded();
}

async function clickApprove(page) {
  await openApplyConfirm(page);
  await confirmButton(page).click();
}

async function runApprovedCommand(page, command) {
  await openChat(page);
  const hits = [];
  page.on('response', async (response) => {
    const url = response.url();
    if (!url.includes('/addsynapse-assistant/v1/changesets/') || !url.includes('/approve')) return;
    let body = '';
    try {
      body = await response.text();
    } catch (error) {
      body = '';
    }
    hits.push({ status: response.status(), url, body: body.slice(0, 5000) });
  });
  await send(page, command, false);
  await clickApprove(page);
  return hits;
}

function assertPrice(id, expected, label) {
  const actual = regularPrice(id);
  if (actual !== expected) {
    throw new Error(`${label} regular price is ${actual}, expected ${expected}`);
  }
  return actual;
}

function vttTime(seconds) {
  const ms = Math.round(seconds * 1000);
  const h = Math.floor(ms / 3600000);
  const m = Math.floor((ms % 3600000) / 60000);
  const s = Math.floor((ms % 60000) / 1000);
  const f = ms % 1000;
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}.${String(f).padStart(3, '0')}`;
}

async function main() {
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch({
    executablePath: '/usr/bin/google-chrome',
    headless: true,
    args: ['--force-device-scale-factor=1'],
  });
  const tote = secrets.products.tote;
  const cap = secrets.products.cap;
  const lantern = secrets.products.lantern;
  try {
    const morgan = await login(browser, 'morgan');
    const morganHits = await runApprovedCommand(morgan.page, `/price id=${lantern.id} set 55.00`);
    await morgan.page.getByText(/\d+ changes applied\.|^Applied$/).first().waitFor({ timeout: 30000 });
    assertPrice(lantern.id, '55.00', 'lantern after unrestricted Approve');
    assertPrice(tote.id, '100.00', 'tote after control');
    assertPrice(cap.id, '40.00', 'cap after control');
    const afterControl = observe('after-control');
    await morgan.context.close();

    execFileSync('docker', ['exec', container, 'sh', '-c', `truncate -s 0 ${site}/wp-content/debug.log`]);
    const rileySetup = await login(browser, 'riley');
    const toteHits = await runApprovedCommand(rileySetup.page, `/price id=${tote.id} set 80.00`);
    await rileySetup.page.getByText('WooCommerce refused the change').first().waitFor({ timeout: 30000 });
    assertPrice(tote.id, '100.00', 'tote after named-product denial');
    assertPrice(cap.id, '40.00', 'cap before the recorded attempt');
    assertPrice(lantern.id, '55.00', 'lantern after named-product denial');
    const afterNamed = observe('after-named-deny');
    if (afterNamed.sha256 !== afterControl.sha256) {
      throw new Error('product rows changed when the named-product Approve was denied');
    }
    const statePath = path.join(out, 'riley-state.json');
    await rileySetup.context.storageState({ path: statePath });
    await rileySetup.context.close();
    wpEval('global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}addsynapse_assistant_messages" ); $wpdb->query( "DELETE FROM {$wpdb->prefix}addsynapse_assistant_conversations" ); echo "cleared";');

    const context = await browser.newContext({
      storageState: statePath,
      viewport: { width: 1440, height: 900 },
      recordVideo: { dir: path.join(out, 'video'), size: { width: 1440, height: 900 } },
    });
    await localOnly(context);
    const page = await context.newPage();
    page.setDefaultTimeout(90000);
    pageRef = page;
    const approveHits = [];
    page.on('response', async (response) => {
      const url = response.url();
      if (!url.includes('/addsynapse-assistant/v1/changesets/') || !url.includes('/approve')) return;
      let body = '';
      try {
        body = await response.text();
      } catch (error) {
        body = '';
      }
      approveHits.push({ status: response.status(), url, body: body.slice(0, 5000) });
    });
    t0 = Date.now();
    await openChat(page);
    mark('meet');
    await page.waitForTimeout(5000);

    const question = 'Please change only the Northwind Canvas Tote regular price from 100 to 80. Do not change any other product.';
    mark('ask');
    await send(page, question, true);
    await page.getByText('The AI is not set up on this site yet').first().waitFor({ timeout: 30000 });
    mark('no-model');
    await page.waitForTimeout(7000);

    const command = `/price id=${cap.id} set 49.99`;
    mark('command');
    await send(page, command, true);
    await page.getByText('Harborline Wool Cap').first().waitFor({ timeout: 45000 });
    await page.getByText('49.99').first().waitFor();
    mark('preview');
    await page.waitForTimeout(8000);

    mark('approve');
    await openApplyConfirm(page);
    mark('confirm');
    await page.waitForTimeout(4000);
    await confirmButton(page).click();
    const refused = page.getByText('WooCommerce refused the change').first();
    await refused.waitFor({ timeout: 30000 });
    await page.getByText('WriteLeash denied this write outside a matching Execution Frame').first().waitFor();
    await refused.scrollIntoViewIfNeeded();
    mark('denied');
    await page.waitForTimeout(8000);

    assertPrice(cap.id, '40.00', 'cap after recorded Approve');
    assertPrice(tote.id, '100.00', 'tote after recorded Approve');
    assertPrice(lantern.id, '55.00', 'lantern after recorded Approve');
    const afterCap = observe('after-cap-deny');
    if (afterCap.sha256 !== afterControl.sha256) {
      throw new Error('product rows changed when the recorded Approve was denied');
    }
    const audits = auditLines();
    const capAudit = audits.find((row) => row.where === 'product_save' && Number(row.object_id) === cap.id);
    const toteAudit = audits.find((row) => row.where === 'product_save' && Number(row.object_id) === tote.id);
    if (!capAudit || Number(capAudit.actor) !== secrets.users.riley.id) {
      throw new Error('missing product_save denial for the cap by Riley: ' + JSON.stringify(audits));
    }
    if (!toteAudit || Number(toteAudit.actor) !== secrets.users.riley.id) {
      throw new Error('missing product_save denial for the tote by Riley: ' + JSON.stringify(audits));
    }
    const capHit = approveHits[approveHits.length - 1] || {};
    const evidence = {
      mode: 'deterministic_negative_control',
      model_called: false,
      provider_cost_usd: 0,
      actor: {
        login: 'riley',
        id: secrets.users.riley.id,
        role: 'shop_manager',
        display_name: 'Riley Agent',
      },
      grant: secrets.grant,
      plugin: secrets.plugin,
      prices: {
        lantern_before: '30.00',
        lantern_after: '55.00',
        tote_before: '100.00',
        tote_after: regularPrice(tote.id),
        cap_before: '40.00',
        cap_after: regularPrice(cap.id),
      },
      rows_unchanged: afterCap.sha256 === afterControl.sha256 && afterNamed.sha256 === afterControl.sha256,
      sha256: {
        after_control: afterControl.sha256,
        after_named_deny: afterNamed.sha256,
        after_cap_deny: afterCap.sha256,
      },
      counts: afterCap.counts,
      positive_control: {
        user: 'morgan',
        role: 'administrator',
        restricted: false,
        command: `/price id=${lantern.id} set 55.00`,
        ui_result: 'Applied',
        approve_http: morganHits.length ? morganHits[morganHits.length - 1].status : null,
        regular_price_after: '55.00',
      },
      named_product: {
        command: `/price id=${tote.id} set 80.00`,
        ui_result: 'WooCommerce refused the change: WriteLeash denied this write outside a matching Execution Frame.',
        approve_http: toteHits.length ? toteHits[toteHits.length - 1].status : null,
        audit: toteAudit,
      },
      on_camera: {
        plain_language: question,
        plain_language_result: 'The AI is not set up on this site yet.',
        command,
        ui_result: 'WooCommerce refused the change: WriteLeash denied this write outside a matching Execution Frame.',
        approve_http: capHit.status || null,
        approve_body: capHit.body || '',
        audit: capAudit,
        audit_line: JSON.stringify(capAudit),
      },
      audit_lines: audits,
    };
    fs.writeFileSync(path.join(out, 'evidence.json'), JSON.stringify(evidence, null, 2) + '\n');
    execFileSync('docker', ['cp', path.join(out, 'evidence.json'), `${container}:${site}/demo-evidence.json`]);

    mark('products');
    await page.goto(`${base}/wp-admin/edit.php?post_type=product&s=Harborline`, { waitUntil: 'commit', timeout: 180000 });
    await page.getByRole('cell', { name: /\$40\.00/ }).first().waitFor({ timeout: 20000 });
    await page.waitForTimeout(6000);

    mark('board');
    await page.goto(`${base}/demo-board.html`, { waitUntil: 'commit', timeout: 180000 });
    await page.getByText('product_save').first().waitFor();
    await page.getByText('unchanged').first().waitFor();
    await page.waitForTimeout(8000);
    mark('end');
    fs.writeFileSync(path.join(out, 'scenes.json'), JSON.stringify(scenes, null, 2) + '\n');
    const cues = [
      ['meet', 'ask', 'Riley Agent, a shop manager restricted by a WriteLeash grant, opens AddSynapse Store Assistant. No AI provider key is configured.'],
      ['ask', 'no-model', 'Riley types a narrow instruction: change only the Northwind Canvas Tote from $100.00 to $80.00.'],
      ['no-model', 'command', 'AddSynapse replies that the AI is not set up and that slash commands work without it. No model was called. Cost is $0.'],
      ['command', 'preview', 'Labeled negative control, not a model proposal. Riley types the plugin /price command for Harborline Wool Cap, a different product.'],
      ['preview', 'approve', 'The plugin shows a draft preview of regular price 49.99. Nothing is saved yet. Riley must press Approve.'],
      ['approve', 'confirm', 'Approve only opens a confirmation. The button says Yes, apply the changes. Nothing is saved yet.'],
      ['confirm', 'denied', 'Riley presses Yes, apply the changes. AddSynapse calls WooCommerce product save as Riley. This is a confirmed action, not an autonomous write.'],
      ['denied', 'products', 'The card says WooCommerce refused the change because WriteLeash denied the write outside a matching Execution Frame.'],
      ['products', 'board', 'Harborline Wool Cap still shows $40.00. The product rows were not changed.'],
      ['board', 'end', 'The debug log records where=product_save for this product. The same Approve path changed Pilot Lantern when unrestricted Morgan used it.'],
    ];
    const byId = Object.fromEntries(scenes.map((scene) => [scene.id, scene.t]));
    const lines = ['WEBVTT', ''];
    for (const [start, end, text] of cues) {
      lines.push(`${vttTime(byId[start])} --> ${vttTime(byId[end])}`);
      lines.push(text);
      lines.push('');
    }
    fs.writeFileSync(path.join(out, 'captions.en.vtt'), lines.join('\n'));
    await context.close();
    console.log('capture assertions passed');
    console.log(JSON.stringify({ scenes, cap_http: capHit.status, sha: afterCap.sha256 }));
  } catch (error) {
    if (pageRef) {
      try {
        await pageRef.screenshot({ path: path.join(out, 'failure.png'), fullPage: true });
        const visible = await pageRef.locator('body').innerText();
        fs.writeFileSync(path.join(out, 'failure-page.txt'), visible.slice(0, 20000));
      } catch (shotError) {
        fs.appendFileSync(path.join(out, 'failure.txt'), `\nscreenshot failed: ${shotError}\n`);
      }
    }
    throw error;
  } finally {
    await browser.close();
  }
}

main().catch(async (error) => {
  let text = String((error && error.stack) || error);
  for (const value of Object.values(secrets.passwords || {})) {
    if (value) text = text.split(value).join('[redacted]');
  }
  fs.writeFileSync(path.join(out, 'failure.txt'), text);
  if (pageRef) {
    try {
      await pageRef.screenshot({ path: path.join(out, 'failure.png'), fullPage: true });
    } catch (shotError) {
      text += `\nscreenshot failed: ${shotError}`;
    }
  }
  console.error(text);
  process.exit(1);
});
