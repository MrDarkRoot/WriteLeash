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

const SCENE_COLORS = {
  meet: '#ff0000',
  ask: '#00ff00',
  notice: '#0000ff',
  command: '#ffff00',
  preview: '#ff00ff',
  confirm: '#00ffff',
  denied: '#ff8800',
  products: '#8800ff',
  board: '#008888',
};

function mark(id) {
  scenes.push({ id, t: Number(((Date.now() - t0) / 1000).toFixed(3)), clock: 'runner' });
}

async function installMarker(context) {
  await context.addInitScript(() => {
    const paint = () => {
      const color = sessionStorage.getItem('wl-scene');
      if (!color || !document.body) return;
      let el = document.getElementById('wl-scene-marker');
      if (!el) {
        el = document.createElement('div');
        el.id = 'wl-scene-marker';
        el.setAttribute('aria-hidden', 'true');
        document.body.appendChild(el);
      }
      el.style.cssText = 'position:fixed;left:12px;bottom:56px;width:48px;height:48px;box-sizing:border-box;border:4px solid #ffffff;pointer-events:none;z-index:2147483647;background:' + color + ';';
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', paint);
    else paint();
  });
}

async function paintScene(page, id) {
  const color = SCENE_COLORS[id];
  if (!color) throw new Error(`unknown scene ${id}`);
  await page.evaluate((value) => {
    sessionStorage.setItem('wl-scene', value);
    let el = document.getElementById('wl-scene-marker');
    if (!el) {
      el = document.createElement('div');
      el.id = 'wl-scene-marker';
      el.setAttribute('aria-hidden', 'true');
      document.body.appendChild(el);
    }
    el.style.cssText = 'position:fixed;left:12px;bottom:56px;width:48px;height:48px;box-sizing:border-box;border:4px solid #ffffff;pointer-events:none;z-index:2147483647;background:' + value + ';';
  }, color);
  mark(id);
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
    hits.push({ status: response.status(), url, body: body.slice(0, 12000) });
  });
  await send(page, command, false);
  await clickApprove(page);
  return hits;
}

async function latestHit(hits) {
  const started = Date.now();
  while (Date.now() - started < 8000) {
    if (hits.length > 0 && hits[hits.length - 1].body) return hits[hits.length - 1];
    await new Promise((resolve) => setTimeout(resolve, 150));
  }
  return hits[hits.length - 1] || null;
}

function requireChangeset(hit, expected) {
  if (!hit || hit.status !== 200) {
    throw new Error(`approve HTTP ${hit && hit.status}, expected 200`);
  }
  let body;
  try {
    body = JSON.parse(hit.body);
  } catch (error) {
    throw new Error('approve body was not JSON');
  }
  const applied = body.counts ? body.counts.applied : null;
  const failed = body.counts ? body.counts.failed : null;
  if (body.status !== expected.status || applied !== expected.applied || failed !== expected.failed) {
    throw new Error(`changeset ${body.status} applied ${applied} failed ${failed}, expected ${expected.status} ${expected.applied} ${expected.failed}`);
  }
  const userIds = (body.log || []).map((row) => Number(row.user_id));
  if (!userIds.length || userIds.some((userId) => userId !== expected.userId)) {
    throw new Error(`approve user ids ${userIds.join(',')}, expected ${expected.userId}`);
  }
  return body;
}

function utcMs(value) {
  const text = String(value).trim();
  const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(text) ? `${text.replace(' ', 'T')}Z` : text;
  const parsed = Date.parse(normalized);
  if (Number.isNaN(parsed)) throw new Error(`could not parse time ${value}`);
  return parsed;
}

function grantProof() {
  const publicId = String(secrets.grant.public_id).replace(/[^a-f0-9-]/gi, '');
  const raw = wpEval(`
    global $wpdb;
    $public = '${publicId}';
    $table = $wpdb->prefix . 'writeleash_grants';
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT status, expires_at, actor_id, restriction_removed_at, created_at FROM \${table} WHERE public_id = %s", $public ), ARRAY_A );
    echo wp_json_encode( array(
      'row' => $row,
      'now' => gmdate( 'Y-m-d H:i:s' ),
      'restricted' => ( $row && isset( $row['actor_id'] ) ) ? (bool) \\WriteLeash\\Grant_Repository::is_restricted_actor( (int) $row['actor_id'] ) : false,
    ) );
  `);
  const line = raw.split('\n').map((item) => item.trim()).filter((item) => item.startsWith('{')).pop();
  const parsed = JSON.parse(line);
  const row = parsed.row || {};
  const expires = String(row.expires_at || '');
  if (!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(expires)) {
    throw new Error(`grant expiry ${expires} is not a UTC timestamp`);
  }
  if (expires !== secrets.grant.expires_at) {
    throw new Error(`grant expiry ${expires} does not match the value used at creation`);
  }
  if (row.status !== 'active' || parsed.restricted !== true) {
    throw new Error(`grant is not active for the whole test: ${JSON.stringify(parsed)}`);
  }
  if (row.restriction_removed_at) {
    throw new Error('grant restriction was removed during the test');
  }
  if (Number(row.actor_id) !== Number(secrets.users.riley.id)) {
    throw new Error(`grant actor ${row.actor_id} is not Riley`);
  }
  if (utcMs(expires) <= utcMs(parsed.now)) {
    throw new Error(`grant expiry ${expires} is not after the recording time ${parsed.now}`);
  }
  return {
    status: row.status,
    expires_at: expires,
    actor_id: Number(row.actor_id),
    restriction_removed_at: row.restriction_removed_at,
    created_at: row.created_at,
    now: parsed.now,
    restricted: true,
    expires_source: secrets.grant.expires_source,
  };
}

function assertPrice(id, expected, label) {
  const actual = regularPrice(id);
  if (actual !== expected) {
    throw new Error(`${label} regular price is ${actual}, expected ${expected}`);
  }
  return actual;
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
    if (secrets.plugin.version !== '1.3.5') {
      throw new Error(`AddSynapse ${secrets.plugin.version} is not the pinned 1.3.5 artifact`);
    }
    if (secrets.grant.expires_source !== 'utc_now_plus_30_days') {
      throw new Error('grant expiry was not calculated at execution time');
    }
    const morgan = await login(browser, 'morgan');
    const morganHits = await runApprovedCommand(morgan.page, `/price id=${lantern.id} set 55.00`);
    const morganApplied = morgan.page.getByText(/\d+ changes applied\.|^Applied$/).first();
    await morganApplied.waitFor({ timeout: 30000 });
    const morganUi = (await morganApplied.innerText()).trim();
    const morganHit = await latestHit(morganHits);
    const morganBody = requireChangeset(morganHit, {
      status: 'applied',
      applied: 1,
      failed: 0,
      userId: Number(secrets.users.morgan.id),
    });
    assertPrice(lantern.id, '55.00', 'lantern after unrestricted Approve');
    assertPrice(tote.id, '100.00', 'tote after control');
    assertPrice(cap.id, '40.00', 'cap after control');
    const afterControl = observe('after-control');
    await morgan.context.close();

    execFileSync('docker', ['exec', container, 'sh', '-c', `truncate -s 0 ${site}/wp-content/debug.log`]);
    const rileySetup = await login(browser, 'riley');
    const toteHits = await runApprovedCommand(rileySetup.page, `/price id=${tote.id} set 80.00`);
    const toteRefused = rileySetup.page.getByText('WooCommerce refused the change').first();
    await toteRefused.waitFor({ timeout: 30000 });
    const toteUi = (await toteRefused.innerText()).trim();
    const toteHit = await latestHit(toteHits);
    const toteBody = requireChangeset(toteHit, {
      status: 'failed',
      applied: 0,
      failed: 1,
      userId: Number(secrets.users.riley.id),
    });
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
    await installMarker(context);
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
      approveHits.push({ status: response.status(), url, body: body.slice(0, 12000) });
    });
    t0 = Date.now();
    await openChat(page);
    await paintScene(page, 'meet');
    await page.waitForTimeout(5000);

    const question = 'Please change only the Northwind Canvas Tote regular price from 100 to 80. Do not change any other product.';
    await paintScene(page, 'ask');
    await send(page, question, true);
    const notice = page.getByText('The AI is not set up on this site yet').first();
    await notice.waitFor({ timeout: 30000 });
    await notice.scrollIntoViewIfNeeded();
    await paintScene(page, 'notice');
    await page.waitForTimeout(7000);

    const command = `/price id=${cap.id} set 49.99`;
    await paintScene(page, 'command');
    await send(page, command, true);
    await page.getByText('Harborline Wool Cap').first().waitFor({ timeout: 45000 });
    await page.getByText('49.99').first().waitFor();
    await approveButton(page).waitFor({ timeout: 45000 });
    await approveButton(page).scrollIntoViewIfNeeded();
    await paintScene(page, 'preview');
    await page.waitForTimeout(8000);

    await openApplyConfirm(page);
    await paintScene(page, 'confirm');
    await page.waitForTimeout(4000);
    await confirmButton(page).click();
    const refused = page.getByText('WooCommerce refused the change').first();
    await refused.waitFor({ timeout: 30000 });
    await page.getByText('WriteLeash denied this write outside a matching Execution Frame').first().waitFor();
    await refused.scrollIntoViewIfNeeded();
    const capUi = (await refused.innerText()).trim();
    await paintScene(page, 'denied');
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
    if (!capAudit || Number(capAudit.actor) !== secrets.users.riley.id || capAudit.where !== 'product_save' || capAudit.code !== 'writeleash_perimeter_denied' || capAudit.kind !== 'price' || Number(capAudit.object_id) !== cap.id) {
      throw new Error('missing product_save denial for the cap by Riley: ' + JSON.stringify(audits));
    }
    if (!toteAudit || Number(toteAudit.actor) !== secrets.users.riley.id || toteAudit.where !== 'product_save' || toteAudit.code !== 'writeleash_perimeter_denied' || toteAudit.kind !== 'price' || Number(toteAudit.object_id) !== tote.id) {
      throw new Error('missing product_save denial for the tote by Riley: ' + JSON.stringify(audits));
    }
    const capHit = await latestHit(approveHits);
    const capBody = requireChangeset(capHit, {
      status: 'failed',
      applied: 0,
      failed: 1,
      userId: Number(secrets.users.riley.id),
    });
    const proof = grantProof();
    for (const row of audits) {
      if (!row.timestamp) continue;
      if (utcMs(row.timestamp) >= utcMs(proof.expires_at)) {
        throw new Error(`audit ${row.timestamp} is not before grant expiry ${proof.expires_at}`);
      }
      if (utcMs(row.timestamp) < utcMs(proof.created_at)) {
        throw new Error(`audit ${row.timestamp} is before the grant was created`);
      }
    }
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
      grant_proof: proof,
      plugin: secrets.plugin,
      save_boundary: 'The audit actor is get_current_user_id() inside woocommerce_before_product_object_save. Riley stayed that user on both denied saves.',
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
        ui_result: morganUi,
        approve_http: morganHit.status,
        approve_body: morganHit.body,
        changeset_status: morganBody.status,
        regular_price_after: '55.00',
      },
      named_product: {
        command: `/price id=${tote.id} set 80.00`,
        ui_result: toteUi,
        approve_http: toteHit.status,
        approve_body: toteHit.body,
        changeset_status: toteBody.status,
        audit: toteAudit,
      },
      on_camera: {
        plain_language: question,
        plain_language_result: 'The AI is not set up on this site yet.',
        command,
        confirmations: ['Approve', 'Yes, apply the changes'],
        ui_result: capUi,
        approve_http: capHit.status,
        approve_body: capHit.body,
        changeset_status: capBody.status,
        audit: capAudit,
        audit_line: JSON.stringify(capAudit),
      },
      audit_lines: audits,
    };
    fs.writeFileSync(path.join(out, 'evidence.json'), JSON.stringify(evidence, null, 2) + '\n');
    execFileSync('docker', ['cp', path.join(out, 'evidence.json'), `${container}:${site}/demo-evidence.json`]);

    await page.goto(`${base}/wp-admin/edit.php?post_type=product&s=Harborline`, { waitUntil: 'commit', timeout: 180000 });
    await page.getByRole('link', { name: 'Harborline Wool Cap' }).first().waitFor({ timeout: 30000 });
    await page.getByRole('cell', { name: /\$40\.00/ }).first().waitFor({ timeout: 20000 });
    await paintScene(page, 'products');
    await page.waitForTimeout(6000);

    await page.goto(`${base}/demo-board.html`, { waitUntil: 'commit', timeout: 180000 });
    await page.getByText('product_save').first().waitFor();
    await page.getByText('unchanged').first().waitFor();
    await page.getByText('not the WordPress.org 0.2.2 package').first().waitFor();
    await paintScene(page, 'board');
    await page.waitForTimeout(8000);
    const still = grantProof();
    if (still.expires_at !== proof.expires_at || still.status !== 'active' || utcMs(still.expires_at) <= utcMs(still.now)) {
      throw new Error('grant was not active through the end of the recording');
    }
    fs.writeFileSync(path.join(out, 'scenes.json'), JSON.stringify({
      clock: 'runner',
      note: 'Diagnostic wall clock from the Playwright process. Caption times come from the encoded video.',
      marks: scenes,
    }, null, 2) + '\n');
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
