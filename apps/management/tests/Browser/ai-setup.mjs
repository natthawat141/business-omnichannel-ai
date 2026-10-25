/** Synthetic browser acceptance; serves the real production build, never production data or APIs. */
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const publicDir = fileURLToPath(new URL('../../public/', import.meta.url));
const manifest = JSON.parse(await readFile(path.join(publicDir, 'build/manifest.json'), 'utf8'));
const entry = manifest['resources/js/app.tsx'];
const styles = [...new Set([manifest['resources/css/app.css'].file, ...(entry.css || [])])];
const future = new Date(Date.now() + 3600000).toISOString();
const secret = 'SYNTHETIC_KEY_MUST_NOT_ENTER_PAGE_EXPORT';
let issued = false;
let revoked = false;
let createCalls = 0;
let revokeCalls = 0;
let failRevoke = false;
let remoteEnabled = true;
let origin;
const headers = { 'Cache-Control': 'no-store' };
function data(url) {
  return {component: 'AiSetup', url, version: 'synthetic-review', clearHistory: false, encryptHistory: false,
    props: {baseUrl: origin, remoteEnabled, connections: [{id: 7, name: 'Synthetic connection', scopes: ['Read document metadata'], expires_at: future, revoked_at: revoked ? new Date().toISOString() : null, last_used_at: null}],
      tokens: issued ? [{id: 9, name: 'AI Setup: codex', abilities: ['documents:read'], expires_at: future, revoked_at: null, last_used_at: null}] : [],
      antigravityConfig: JSON.stringify({mcpServers: {'document-intake': {command: '/absolute/path/to/node', args: ['/absolute/path/to/document-intake-mcp.js'], env: {MANAGEMENT_API_BASE_URL: origin, MANAGEMENT_API_TOKEN: 'PASTE_KEY_LOCALLY'}}}}, null, 2),
      auth: {user: {id: 1, name: 'Synthetic admin', email: 'review@example.test', role: 'admin', is_admin: true, can_edit: true}}, flash: {}, errors: {},
    }};
}
const server = createServer(async (req, res) => {
  try {
    const url = new URL(req.url, origin || 'http://127.0.0.1');
    if (url.pathname === '/admin/ai-setup/keys' && req.method === 'POST') {
      createCalls++; issued = true;
      res.writeHead(201, {...headers, 'Content-Type': 'application/json'}).end(JSON.stringify({id: 9, key: secret, expires_at: future})); return;
    }
    if (url.pathname === '/admin/ai-setup/connections/7' && req.method === 'DELETE') {
      revokeCalls++;
      if (failRevoke) { res.writeHead(500, headers).end('{}'); return; }
      revoked = true; res.writeHead(200, {...headers, 'Content-Type': 'application/json'}).end('{"revoked":true}'); return;
    }
    if (url.pathname === '/admin/ai-setup') {
      const page = data(url.pathname + url.search);
      if (req.headers['x-inertia']) { res.writeHead(200, {...headers, 'Content-Type': 'application/json', 'X-Inertia': 'true'}).end(JSON.stringify(page)); return; }
      const encoded = JSON.stringify(page).replaceAll('&', '&amp;').replaceAll("'", '&#39;').replaceAll('<', '&lt;');
      res.writeHead(200, {...headers, 'Content-Type': 'text/html'}).end(`<!doctype html><html lang="th" data-theme="light"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup review</title>${styles.map(s => `<link rel="stylesheet" href="/build/${s}">`).join('')}<body class="font-sans"><div id="app" data-page='${encoded}'></div><script type="module" src="/build/${entry.file}"></script></body></html>`); return;
    }
    const filename = path.resolve(publicDir, '.' + url.pathname);
    if (!filename.startsWith(publicDir)) { res.writeHead(403).end(); return; }
    const bytes = await readFile(filename);
    const type = filename.endsWith('.js') ? 'text/javascript' : filename.endsWith('.css') ? 'text/css' : filename.endsWith('.png') ? 'image/png' : 'application/octet-stream';
    res.writeHead(200, {'Content-Type': type}).end(bytes);
  } catch { res.writeHead(404).end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
origin = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({headless: true, channel: process.env.PLAYWRIGHT_BROWSER_CHANNEL || undefined});
const context = await browser.newContext({viewport: {width: 1440, height: 1000}, permissions: ['clipboard-read', 'clipboard-write']});
const page = await context.newPage();
const errors = [];
page.on('pageerror', error => errors.push(error.name));
const outputDir = process.env.AI_SETUP_REVIEW_DIR || '/tmp/aione-ai-setup-review';
await mkdir(outputDir, {recursive: true});
let checks = 0;
async function check(name, fn) { await fn(); checks++; console.log(JSON.stringify({check: name, passed: true})); }
try {
  await page.goto(origin + '/admin/ai-setup');
  await page.getByRole('heading', {name: 'เชื่อมต่อ AI กับข้อมูลธุรกิจ'}).waitFor();
  await check('remote_and_local_on_same_page', async () => {
    assert.equal(await page.locator('#remote-setup').count(), 1);
    assert.equal(await page.locator('#local-setup').count(), 1);
    assert.equal(await page.getByText('ช่องทางนี้ยังไม่เปิดใช้งาน', {exact: true}).count(), 0);
  });
  await page.screenshot({path: path.join(outputDir, 'desktop.png')});
  await check('copy_complete_guide', async () => {
    await page.getByRole('button', {name: 'คัดลอกคู่มือทั้งหน้า', exact: true}).click();
    const value = await page.evaluate(() => navigator.clipboard.readText());
    for (const part of ['Remote MCP', 'Manual setup', 'Local keys', 'แก้ปัญหา', 'codex mcp add', '/docs/remote-mcp']) assert.ok(value.includes(part), part);
  });
  await check('client_and_permission_choices_update_export', async () => {
    await page.getByRole('button', {name: 'Antigravity', exact: true}).click();
    await page.locator('#setup-scope').selectOption('catalog_proposals');
    await page.getByLabel('คำถามพบบ่อย', {exact: true}).check();
    await page.getByRole('button', {name: 'คัดลอกคู่มือทั้งหน้า', exact: true}).click();
    const value = await page.evaluate(() => navigator.clipboard.readText());
    assert.ok(value.includes('agent:faq')); assert.ok(value.includes('Antigravity')); assert.ok(value.includes('PASTE_KEY_LOCALLY'));
  });
  await page.getByRole('button', {name: 'Codex', exact: true}).click();
  await page.locator('#setup-scope').selectOption('document_metadata');
  await check('key_is_explicit_masked_and_excluded_from_page_copy', async () => {
    assert.equal(createCalls, 0);
    await page.getByRole('button', {name: 'สร้าง key สำหรับ Codex', exact: true}).click();
    await page.locator('#issued-local-key').waitFor();
    assert.equal(await page.locator('#issued-local-key').getAttribute('type'), 'password');
    assert.equal(createCalls, 1);
    await page.getByRole('button', {name: 'คัดลอกคู่มือทั้งหน้า', exact: true}).click();
    const value = await page.evaluate(() => navigator.clipboard.readText());
    assert.ok(!value.includes(secret)); assert.ok(value.includes('AI Setup: codex'));
    await page.getByRole('button', {name: 'แสดง local key', exact: true}).click();
    assert.equal(await page.locator('#issued-local-key').getAttribute('type'), 'text');
    await page.getByRole('button', {name: 'ซ่อน local key', exact: true}).click();
  });
  await check('all_local_clients_export_their_registration', async () => {
    for (const [name, expected] of [['Terminal / CLI', 'node ./bin/document-intake.js list'], ['Claude Code', 'claude mcp add'], ['Antigravity', 'mcpServers'], ['Codex', 'codex mcp add']]) {
      await page.getByRole('button', {name, exact: true}).click();
      await page.getByRole('button', {name: 'คัดลอกคู่มือทั้งหน้า', exact: true}).click();
      assert.ok((await page.evaluate(() => navigator.clipboard.readText())).includes(expected));
      assert.equal(createCalls, 1);
    }
  });
  await check('clipboard_denial_has_safe_manual_fallback', async () => {
    await page.evaluate(() => { window.__originalCopy = navigator.clipboard.writeText.bind(navigator.clipboard); navigator.clipboard.writeText = async () => { throw new DOMException('denied', 'NotAllowedError'); }; });
    await page.getByRole('button', {name: 'คัดลอกคู่มือทั้งหน้า', exact: true}).click();
    const field = page.getByRole('textbox', {name: 'คู่มือทั้งหน้าสำหรับคัดลอก'});
    await field.waitFor(); assert.ok(!(await field.inputValue()).includes(secret));
    assert.equal(await field.evaluate(element => element === document.activeElement), true);
    await page.getByRole('button', {name: 'ปิดสำเนาคู่มือ', exact: true}).click();
    await page.evaluate(() => { navigator.clipboard.writeText = window.__originalCopy; });
  });
  await check('disconnect_requires_confirmation_and_recovers_from_failure', async () => {
    await page.getByRole('button', {name: 'ยกเลิกการเชื่อมต่อ', exact: true}).click();
    assert.equal(revokeCalls, 0);
    await page.getByRole('button', {name: 'ใช้ต่อ', exact: true}).click();
    assert.equal(revokeCalls, 0);
    await page.getByRole('button', {name: 'ยกเลิกการเชื่อมต่อ', exact: true}).click();
    failRevoke = true;
    await page.getByRole('button', {name: 'ยืนยันยกเลิก', exact: true}).click();
    await page.getByText('ทำรายการไม่สำเร็จ กรุณาโหลดหน้าใหม่ก่อนลองอีกครั้ง', {exact: true}).waitFor();
    assert.equal(revoked, false);
    failRevoke = false;
    await page.getByRole('button', {name: 'ยืนยันยกเลิก', exact: true}).click();
    await page.locator('#connections').getByText('ยกเลิกแล้ว', {exact: false}).waitFor();
    assert.equal(revoked, true);
  });
  await check('narrow_screen_does_not_overflow', async () => {
    await page.setViewportSize({width: 390, height: 844}); await page.evaluate(() => window.scrollTo(0, 0));
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
    await page.screenshot({path: path.join(outputDir, 'mobile.png')});
    await page.locator('#local-setup').evaluate(element => element.scrollIntoView({block: 'start'}));
    await page.screenshot({path: path.join(outputDir, 'mobile-local.png')});
  });
  await check('legacy_alias_and_disabled_remote_are_truthful', async () => {
    remoteEnabled = false;
    await page.goto(origin + '/admin/ai-setup?advanced=1');
    await page.getByRole('heading', {name: 'เชื่อมต่อ AI กับข้อมูลธุรกิจ'}).waitFor();
    assert.equal(await page.getByRole('button', {name: 'Copy URL', exact: true}).isDisabled(), true);
    assert.equal(await page.getByRole('button', {name: 'สร้าง key สำหรับ Codex', exact: true}).isEnabled(), true);
  });
  await check('dark_theme_and_keyboard_focus', async () => {
    await page.setViewportSize({width: 1440, height: 1000});
    await page.getByRole('button', {name: 'โหมดมืด', exact: true}).click();
    assert.equal(await page.locator('html').getAttribute('data-theme'), 'dark');
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.getByRole('button', {name: 'คัดลอกคู่มือทั้งหน้า', exact: true}).focus();
    assert.equal(await page.evaluate(() => document.activeElement.textContent.includes('คัดลอกคู่มือทั้งหน้า')), true);
    await page.screenshot({path: path.join(outputDir, 'dark.png')});
  });
  assert.deepEqual(errors, []);
  console.log(JSON.stringify({checks, passed: checks, failed: 0, real_credentials_issued: 0, production_requests: 0, screenshots: outputDir}));
} finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
