import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash, randomBytes } from 'node:crypto';

// Use an already available Puppeteer module. No browser/library is downloaded.
const imported = await import(process.env.MEDIVEND_PUPPETEER_MODULE || 'puppeteer');
const puppeteer = imported.puppeteer || imported.default;
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const php = process.env.MEDIVEND_PHP_PATH || (process.platform === 'win32' ? 'C:/xampp/php/php.exe' : 'php');
const base = (process.env.MEDIVEND_TEST_BASE_URL || 'http://localhost/MediVend').replace(/\/$/, '') + '/';
const database = (input = {}) => JSON.parse(execFileSync(php, [join(root, 'tests/purchase-flow-fixtures.php')], {
  input: JSON.stringify(input), encoding: 'utf8', windowsHide: true
}));
const original = database();
const output = mkdtempSync(join(tmpdir(), 'medivend-purchase-audit-'));
const owned = new Set(), runtimeErrors = [], mutations = [], checks = [];
let fixture, browser;
console.log('Audit artifacts:', output);
const wait = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
const pass = message => { checks.push(message); console.log('PASS:', message); };
async function http(path, key, body = null) {
  const response = await fetch(base + 'api/' + path + '.php', {
    method: body === null ? 'GET' : 'POST', headers: { 'Content-Type': 'application/json', 'X-Demo-Order-Key': key },
    ...(body === null ? {} : { body: JSON.stringify(body) })
  });
  return { status: response.status, body: await response.json() };
}
try {
  const live = await (await fetch(base + 'api/products.php')).json();
  const medicines = live.products.filter(product => ['DEMO-CETI', 'DEMO-LOPE', 'DEMO-PARA'].includes(product.sku));
  assert.equal(medicines.length, 3); assert.ok(medicines.every(product => product.price_centavos === 1000));
  pass('Live catalog: all three existing medicines are 1000 centavos per unit');
  fixture = database({ operation: 'create' });
  const [a, b, c] = fixture.products.map(product => Number(product.id));
  const ids = new Set([a, b, c]);
  browser = await puppeteer.launch({
    executablePath: process.env.MEDIVEND_CHROME_PATH || (process.platform === 'win32' ? 'C:/Program Files/Google/Chrome/Application/chrome.exe' : undefined),
    headless: true, userDataDir: join(output, 'chrome'), args: ['--no-first-run', '--no-default-browser-check']
  });
  async function customer() {
    const context = await browser.createBrowserContext();
    const page = await context.newPage();
    await page.setViewport({ width: 1024, height: 600, hasTouch: true });
    page.on('pageerror', error => runtimeErrors.push(error.message));
    const control = { catalog: 'normal', mutation: 'normal', held: null };
    async function forward(request) {
      const headers = { 'Content-Type': 'application/json' };
      for (const name of ['idempotency-key', 'x-demo-order-key', 'x-demo-event-id']) {
        if (request.headers()[name]) headers[name] = request.headers()[name];
      }
      if (new URL(request.url()).pathname.endsWith('/api/checkout.php')) {
        owned.add('MV-' + createHash('sha256').update('medivend-checkout-v1:' + headers['idempotency-key']).digest('hex').slice(0, 32).toUpperCase());
      }
      const response = await fetch(request.url(), { method: request.method(), headers,
        ...(request.method() === 'POST' ? { body: request.postData() } : {}) });
      return { status: response.status, contentType: 'application/json', body: await response.text() };
    }
    await page.setRequestInterception(true);
    page.on('request', request => {
      (async () => {
        const path = new URL(request.url()).pathname;
        if (path.endsWith('/api/products.php')) {
          if (control.catalog === 'hold') { control.held = { request, forward: async () => {
            const result = await (await fetch(base + 'api/products.php')).json();
            return { status: 200, contentType: 'application/json', body: JSON.stringify({ products: result.products.filter(product => ids.has(product.id)) }) };
          } }; return; }
          if (control.catalog === 'empty' || control.catalog === 'error') {
            await request.respond({ status: control.catalog === 'error' ? 503 : 200, contentType: 'application/json',
              body: JSON.stringify(control.catalog === 'empty' ? { products: [] } : { error: { code: 'catalog_unavailable' } }) }); return;
          }
          const result = await (await fetch(base + 'api/products.php')).json();
          await request.respond({ status: 200, contentType: 'application/json', body: JSON.stringify({ products: result.products.filter(product => ids.has(product.id)) }) }); return;
        }
        if (path.endsWith('/api/checkout.php') || (path.includes('/api/demo-') && request.method() === 'POST')) {
          mutations.push({ path: path.split('/').pop(), body: JSON.parse(request.postData()), headers: request.headers() });
          if (control.mutation === 'hold') { control.held = { request, forward: () => forward(request) }; return; }
          if (control.mutation === 'reject') {
            control.mutation = 'normal';
            await request.respond({ status: 503, contentType: 'application/json', body: JSON.stringify({ error: {
              code: 'simulation_unavailable', message: 'Simulation stop could not be confirmed. Refresh status or contact clinic staff.'
            } }) }); return;
          }
          const response = await forward(request);
          if (control.mutation === 'drop') { control.mutation = 'normal'; await request.abort('failed'); return; }
          await request.respond(response); return;
        }
        await request.continue();
      })().catch(error => { runtimeErrors.push(error.message); request.abort().catch(() => {}); });
    });
    await page.goto(base, { waitUntil: 'load' });
    return { page, context, control };
  }
  const tap = (page, selector) => page.tap(selector);
  const idle = page => page.waitForFunction(() => !DemoFlow.locked);
  const keyOf = page => page.evaluate(() => JSON.parse(sessionStorage.getItem('medivend.current-demo-order.v1')).key);
  const stateOf = page => page.evaluate(() => ({ reference: lastOrder.reference, total: lastOrder.total, paid: lastOrder.paid,
    token: lastOrder.token, status: lastOrder.orderStatus, items: lastOrder.items }));
  const stock = id => Number(database().products.find(product => Number(product.id) === id).stock_quantity);
  async function screen(page, name) {
    await page.evaluate(() => document.fonts.ready);
    await page.waitForFunction(() => [...document.images].every(image => image.complete && image.naturalWidth));
    await wait(200);
    const layout = await page.evaluate(() => ({ width: innerWidth, height: innerHeight, scrollWidth: document.documentElement.scrollWidth, scrollHeight: document.documentElement.scrollHeight,
      smallButtons: [...document.querySelectorAll('button:not(:disabled)')].filter(button => {
        const rect = button.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0 && rect.top < innerHeight && rect.bottom > 0
          && getComputedStyle(button).visibility !== 'hidden' && (rect.height < 43.9 || rect.width < 43.9);
      }).map(button => ({ label: button.textContent.trim(), width: button.getBoundingClientRect().width, height: button.getBoundingClientRect().height })) }));
    await page.screenshot({ path: join(output, name + '.png'), fullPage: true });
    assert.ok(layout.scrollWidth <= layout.width, name + ' horizontal overflow');
    assert.ok(layout.scrollHeight <= layout.height, name + ' vertical overflow');
    assert.deepEqual(layout.smallButtons, [], name + ' touch targets below 44px');
    checks.push('1024 × 600 touch layout: ' + name);
  }
  async function select(page, quantities = [[a, 1]]) {
    await tap(page, '[data-action="begin"]'); await page.waitForSelector('.product');
    for (const [id, count] of quantities) for (let i = 0; i < count; i++) await tap(page, '.products [data-id="' + id + '"]');
  }
  async function review(page) { await tap(page, '[data-action="checkout"]'); await tap(page, '#ack'); }
  async function save(page) { await tap(page, '#complete'); await page.waitForSelector('.demo-flow'); await idle(page); }
  async function pay(page, coins) {
    let total = (await stateOf(page)).paid;
    for (const coin of coins) {
      await tap(page, '[data-action="demo-coin"][data-coin="' + coin + '"]'); await idle(page); total += coin;
      assert.equal((await stateOf(page)).paid, total);
    }
  }
  async function start(page) { await tap(page, '[data-action="demo-start"]'); await idle(page); }
  async function deliver(page, id) { await tap(page, '[data-action="demo-delivered"][data-id="' + id + '"]'); await idle(page); }

  const main = await customer(); const page = main.page;
  await screen(page, 'welcome');
  const firstX = await page.$eval('.medicine-track', element => element.getBoundingClientRect().x);
  await wait(450); assert.ok(await page.$eval('.medicine-track', element => element.getBoundingClientRect().x) < firstX - 10);
  pass('Welcome animation is actually moving under the system motion setting');
  main.control.catalog = 'hold'; await page.reload({ waitUntil: 'load' }); await tap(page, '[data-action="begin"]');
  await page.waitForSelector('.catalog-spinner'); await screen(page, 'catalog-loading');
  await main.control.held.request.respond(await main.control.held.forward()); main.control.held = null; main.control.catalog = 'normal';
  await page.waitForSelector('.product'); await screen(page, 'catalog');
  for (let count = 0; count < 12; count++) await tap(page, '.products [data-id="' + a + '"]');
  assert.equal(await page.$eval('.products [data-id="' + a + '"]', button => button.disabled), true);
  assert.equal(await page.$eval('.cart .total strong', element => element.textContent), '₱120.00');
  await tap(page, '.cart [data-action="remove"][data-id="' + a + '"]');
  for (const id of [a, a, b, c]) await tap(page, '.products [data-id="' + id + '"]');
  assert.equal(await page.$eval('.cart .total strong', element => element.textContent), '₱40.00');
  await tap(page, '.cart [data-action="minus"][data-id="' + a + '"]');
  assert.equal(await page.$eval('.cart .total strong', element => element.textContent), '₱30.00');
  await tap(page, '.cart [data-action="remove"][data-id="' + c + '"]');
  assert.equal(await page.$eval('.cart .total strong', element => element.textContent), '₱20.00');
  for (const id of [a, c]) await tap(page, '.products [data-id="' + id + '"]');
  await screen(page, 'filled-cart'); await tap(page, '.brand'); await screen(page, 'reset-dialog'); await tap(page, '#keep');
  await review(page); await screen(page, 'checkout-review');
  main.control.mutation = 'hold';
  const mutationCount = mutations.length;
  await page.evaluate(() => { for (let i = 0; i < 6; i++) document.querySelector('#complete').click(); });
  await page.waitForFunction(() => checkoutBusy); await screen(page, 'saving-order');
  assert.equal(mutations.length, mutationCount + 1);
  const checkoutRequest = mutations.at(-1);
  await main.control.held.request.respond(await main.control.held.forward()); main.control.held = null; main.control.mutation = 'normal';
  await page.waitForSelector('.demo-flow'); await idle(page);
  let state = await stateOf(page); assert.equal(state.total, 4000);
  const orderKey = await keyOf(page);
  const duplicateCheckout = await fetch(base + 'api/checkout.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': checkoutRequest.headers['idempotency-key'] }, body: JSON.stringify(checkoutRequest.body) });
  assert.equal(duplicateCheckout.status, 200); assert.equal((await duplicateCheckout.json()).replayed, true);
  const unpaidStart = await http('demo-dispense', orderKey, { state_token: state.token, action: 'start', product_id: null });
  assert.equal(unpaidStart.status, 409); await screen(page, 'payment-pending');
  pass('Cart quantities, totals, stock limit and duplicate checkout; unpaid dispensing rejected');
  await pay(page, [1000]); await screen(page, 'partial-payment');
  await tap(page, '#home'); assert.equal(await page.$eval('#staff-dialog', dialog => dialog.open), true); await screen(page, 'staff-assistance');
  await tap(page, '#staff-close');
  await page.reload({ waitUntil: 'load' }); await page.waitForSelector('.demo-flow'); await idle(page);
  assert.equal((await stateOf(page)).paid, 1000);
  await pay(page, [500, 100, 100, 100, 100, 100, 1000, 1000]);
  state = await stateOf(page); assert.equal(state.paid, 4000); await screen(page, 'payment-complete');
  const firstCoin = mutations.find(request => request.path === 'demo-payment.php');
  const duplicateCoin = await http('demo-payment', orderKey, firstCoin.body);
  assert.equal(duplicateCoin.status, 200); assert.equal(duplicateCoin.body.coin_event.replayed, true); assert.equal(duplicateCoin.body.order.amount_paid_centavos, 4000);
  const extraCoin = await http('demo-payment', orderKey, { event_id: randomBytes(32).toString('hex'), coin_centavos: 100 }); assert.equal(extraCoin.status, 409);
  pass('Mixed ₱1/₱5/₱10 coins, persistent duplicates, paid-state rejection, refresh recovery and credit protection');
  await start(page); await screen(page, 'dispensing');
  const beforeStock = [stock(a), stock(b), stock(c)];
  main.control.mutation = 'hold'; await tap(page, '[data-action="demo-delivered"][data-id="' + a + '"]');
  await page.waitForSelector('.demo-processing'); await screen(page, 'dispensing-busy');
  assert.equal(await page.$eval('#home', button => button.disabled), true);
  await tap(page, '#home'); assert.equal(await page.evaluate(() => screen), 'pending');
  const deliveredRequest = mutations.at(-1);
  await main.control.held.request.respond(await main.control.held.forward()); main.control.held = null; main.control.mutation = 'normal'; await idle(page);
  const duplicateDelivery = await http('demo-dispense', orderKey, deliveredRequest.body); assert.equal(duplicateDelivery.status, 409); assert.equal(stock(a), beforeStock[0] - 1);
  await deliver(page, a); await deliver(page, b); await deliver(page, c);
  assert.equal((await stateOf(page)).status, 'completed'); assert.deepEqual([stock(a), stock(b), stock(c)], [beforeStock[0] - 2, beforeStock[1] - 1, beforeStock[2] - 1]);
  await screen(page, 'delivery-complete');
  assert.equal(await page.$eval('[data-action="new"]', button => button.textContent.trim()), 'Finish session');
  await tap(page, '[data-action="new"]'); await page.waitForSelector('.welcome');
  assert.equal(await page.evaluate(() => lastOrder === null && cart.size === 0 && !checkoutAttempt), true);
  assert.equal(await page.evaluate(() => sessionStorage.getItem('medivend.current-demo-order.v1')), null);
  assert.equal(await page.evaluate(() => localStorage.getItem('medivend.protected-demo-credit.v1')), null);
  await page.reload({ waitUntil: 'load' }); await page.waitForSelector('.welcome'); await screen(page, 'next-customer-welcome');
  await select(page); await review(page); await save(page);
  assert.notEqual((await stateOf(page)).reference, state.reference); assert.equal((await stateOf(page)).paid, 0); assert.equal((await stateOf(page)).total, 1000);
  await tap(page, '#home'); await page.waitForSelector('.welcome');
  pass('Per-unit stock decrements once, unresolved dispensing blocks reset, and Finish session clears all customer state');

  const jam = await customer(); await select(jam.page, [[a, 2]]); await review(jam.page); await save(jam.page); await pay(jam.page, [1000, 1000]); await start(jam.page); await deliver(jam.page, a);
  await tap(jam.page, '[data-action="demo-jam"]'); await idle(jam.page); assert.equal((await stateOf(jam.page)).status, 'needs_review'); assert.equal((await stateOf(jam.page)).items[0].delivered, 1);
  await screen(jam.page, 'jam-staff-review'); await tap(jam.page, '#home'); assert.equal(await jam.page.$eval('#staff-dialog', dialog => dialog.open), true); await tap(jam.page, '#staff-close');
  const uncertain = await customer(); await select(uncertain.page); await review(uncertain.page); await save(uncertain.page); await pay(uncertain.page, [1000]); await start(uncertain.page);
  const noSensorStock = stock(a); await tap(uncertain.page, '[data-action="demo-no-confirmation"]'); await idle(uncertain.page);
  assert.equal((await stateOf(uncertain.page)).status, 'needs_review'); assert.equal(stock(a), noSensorStock); await screen(uncertain.page, 'no-sensor-staff-review');
  pass('Jam preserves partial delivery; missing confirmation consumes no unit and stops for staff');

  const lost = await customer(); await select(lost.page, [[a, 2]]); await review(lost.page); await save(lost.page); await pay(lost.page, [1000, 1000]); await start(lost.page);
  const lostStock = stock(a), beforeDeliveries = mutations.filter(request => request.body.action === 'delivered').length;
  lost.control.mutation = 'drop'; await tap(lost.page, '[data-action="demo-delivered"][data-id="' + a + '"]'); await lost.page.waitForSelector('.checkout-error');
  await screen(lost.page, 'uncertain-delivery');
  lost.control.mutation = 'reject';
  await tap(lost.page, '[data-action="demo-refresh"]');
  await lost.page.waitForFunction(() => document.querySelector('.demo-controls')?.getAttribute('aria-busy') === 'false');
  assert.equal((await stateOf(lost.page)).status, 'processing');
  assert.match(await lost.page.$eval('.checkout-error', element => element.textContent), /stop could not be confirmed/);
  await tap(lost.page, '[data-action="demo-refresh"]'); await idle(lost.page);
  assert.equal((await stateOf(lost.page)).status, 'needs_review'); assert.equal((await stateOf(lost.page)).items[0].delivered, 1); assert.equal(stock(a), lostStock - 1);
  assert.equal(mutations.filter(request => request.body.action === 'delivered').length, beforeDeliveries + 1);
  await screen(lost.page, 'uncertain-delivery-stopped');
  await lost.page.reload({ waitUntil: 'load' }); await lost.page.waitForSelector('.staff-message'); await idle(lost.page); assert.equal((await stateOf(lost.page)).status, 'needs_review');
  pass('Lost delivery response recovers its one confirmed unit, flags staff review, and never repeats delivery');

  const coinLost = await customer(); await select(coinLost.page); await review(coinLost.page); await save(coinLost.page);
  coinLost.control.mutation = 'drop'; await tap(coinLost.page, '[data-coin="500"]'); await coinLost.page.waitForSelector('.checkout-error');
  const originalEvent = mutations.at(-1).body;
  await screen(coinLost.page, 'coin-error-recovery'); await tap(coinLost.page, '[data-action="demo-retry-coin"]'); await idle(coinLost.page);
  assert.deepEqual(mutations.at(-1).body, originalEvent); assert.equal((await stateOf(coinLost.page)).paid, 500);
  await coinLost.page.reload({ waitUntil: 'load' }); await coinLost.page.waitForSelector('.demo-flow'); await idle(coinLost.page); assert.equal((await stateOf(coinLost.page)).paid, 500);
  pass('Lost coin response retries the same persistent event without a second credit');

  for (const mode of ['empty', 'error']) {
    const statePage = await customer(); statePage.control.catalog = mode; await statePage.page.reload({ waitUntil: 'load' }); await tap(statePage.page, '[data-action="begin"]');
    await statePage.page.waitForSelector('.catalog-state'); await screen(statePage.page, 'catalog-' + mode);
    statePage.control.catalog = 'normal'; await tap(statePage.page, '[data-action="retry"]'); await statePage.page.waitForSelector('.product');
  }
  assert.deepEqual(runtimeErrors, [], 'No uncaught JavaScript or request-interception errors');
  pass('Loading, empty, error and retry screens remain usable with touch; no uncaught browser errors');
  writeFileSync(join(output, 'results.json'), JSON.stringify({ passed: checks, runtimeErrors }, null, 2));
  console.log('PASS: all purchase-flow browser checks.');
} finally {
  if (browser) await browser.close();
  if (fixture) database({ operation: 'cleanup', tag: fixture.tag, references: [...owned] });
  assert.deepEqual(database(), original, 'Existing products, orders, delivery counts and coin events must be preserved');
  console.log('Audit fixtures removed; existing database rows are unchanged.');
}
