import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { randomBytes } from 'node:crypto';
import { createServer } from 'node:net';
const module = await import(process.env.MEDIVEND_PUPPETEER_MODULE || 'puppeteer');
const puppeteer = module.puppeteer || module.default;
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const php = process.env.MEDIVEND_PHP_PATH || (process.platform === 'win32' ? 'C:/xampp/php/php.exe' : 'php');
const database = input => JSON.parse(execFileSync(php, [join(root,'tests/purchase-flow-fixtures.php')], { input: JSON.stringify(input || {}), encoding:'utf8', windowsHide:true }));
const original = database(), output = mkdtempSync(join(tmpdir(),'medivend-hardware-browser-'));
const fixture = database({ operation:'create' }), owned = [];
const port = await new Promise(resolve => { const server = createServer().listen(0,'127.0.0.1', () => { const value=server.address().port; server.close(() => resolve(value)); }); });
const server = spawn(php,['-S','127.0.0.1:'+port,'-t',root], { windowsHide:true, env:{...process.env,MEDIVEND_MODE:'hardware',MEDIVEND_HARDWARE_ADAPTER:'',MEDIVEND_DELIVERY_TIMEOUT_SECONDS:'0'}, stdio:'ignore' });
const base='http://127.0.0.1:'+port+'/', simulation=(process.env.MEDIVEND_TEST_BASE_URL || 'http://localhost/MediVend').replace(/\/$/,'')+'/';
let browser;
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));
try {
  for (let i=0;i<60;i++) { try { if ((await fetch(base+'api/machine.php')).ok) break; } catch {} await pause(50); }
  browser=await puppeteer.launch({executablePath:process.env.MEDIVEND_CHROME_PATH || (process.platform==='win32'?'C:/Program Files/Google/Chrome/Application/chrome.exe':undefined), headless:true});
  const page=await browser.newPage(), errors=[];
  await page.setViewport({width:1024,height:600,hasTouch:true}); page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base,{waitUntil:'load'}); await page.waitForFunction(()=>machineRuntime.loaded);
  assert.equal(await page.evaluate(()=>machineRuntime.mode),'hardware');
  assert.equal(await page.$eval('[data-action="begin"]',button=>button.disabled),true);
  assert.match(await page.$eval('[data-action="begin"]',button=>button.textContent),/Machine unavailable/);
  assert.doesNotMatch(await page.$eval('body',element=>element.innerText),/demo|simulat/i);
  assert.equal(await page.$('[data-action="demo-coin"]'),null);
  const x=await page.$eval('.medicine-track',element=>element.getBoundingClientRect().x);
  await pause(5500);
  assert.ok(await page.$eval('.medicine-track',element=>element.getBoundingClientRect().x)<x-50,'Health polling must not reset the continuous welcome animation');
  await page.screenshot({path:join(output,'hardware-unavailable.png')});
  const post=await fetch(base+'api/checkout.php?mode=simulation',{method:'POST',headers:{'Content-Type':'application/json','Idempotency-Key':randomBytes(32).toString('hex')},body:JSON.stringify({items:[{product_id:Number(fixture.products[0].id),quantity:1}]})});
  assert.equal(post.status,503);
  console.log('PASS: hardware unavailable, no customer simulation wording/controls, server checkout block, continuous animation');
  // A prior demo credit must remain readable/protected after an administrator
  // changes server mode; changing mode is never permission to erase money.
  const key=randomBytes(32).toString('hex');
  const saved=await (await fetch(simulation+'api/checkout.php',{method:'POST',headers:{'Content-Type':'application/json','Idempotency-Key':key},body:JSON.stringify({items:[{product_id:Number(fixture.products[0].id),quantity:1}]})})).json();
  assert.ok(saved.order); owned.push(saved.order.reference);
  const paid=await fetch(simulation+'api/demo-payment.php',{method:'POST',headers:{'Content-Type':'application/json','X-Demo-Order-Key':key},body:JSON.stringify({event_id:randomBytes(32).toString('hex'),coin_centavos:500})}); assert.equal(paid.status,200);
  await page.evaluate(key=>sessionStorage.setItem('medivend.current-demo-order.v1',JSON.stringify({key})),key);
  await page.reload({waitUntil:'load'}); await page.waitForSelector('.staff-message');
  assert.equal(await page.evaluate(()=>lastOrder.paid),500);
  assert.doesNotMatch(await page.$eval('body',element=>element.innerText),/demo|simulat/i);
  await page.tap('#home'); assert.equal(await page.$eval('#staff-dialog',dialog=>dialog.open),true);
  assert.equal(await page.$eval('#staff-amount',element=>element.textContent),'₱5.00');
  await page.tap('#staff-close'); await page.reload({waitUntil:'load'}); await page.waitForSelector('.staff-message');
  assert.equal(await page.evaluate(()=>lastOrder.paid),500);
  assert.ok(await page.evaluate(()=>sessionStorage.getItem('medivend.current-demo-order.v1')));
  await page.screenshot({path:join(output,'hardware-preserved-credit.png')});
  const layout=await page.evaluate(()=>({width:document.documentElement.scrollWidth,height:document.documentElement.scrollHeight,small:[...document.querySelectorAll('button:not(:disabled)')].filter(button=>{const r=button.getBoundingClientRect();return r.width>0&&r.height>0&&(r.width<44||r.height<44);}).map(button=>button.textContent)}));
  assert.ok(layout.width<=1024&&layout.height<=600); assert.deepEqual(layout.small,[]); assert.deepEqual(errors,[]);
  writeFileSync(join(output,'results.json'),JSON.stringify({passed:true,errors,layout},null,2));
  console.log('PASS: mode-switch credit protection, backend status/reload, staff assistance, 1024×600 touch layout; artifacts: '+output);
} finally {
  if(browser)await browser.close(); server.kill();
  await new Promise(resolve=>server.exitCode!==null?resolve():server.once('exit',resolve));
  database({operation:'cleanup',tag:fixture.tag,references:owned}); assert.deepEqual(database(),original);
  console.log('Original database rows preserved.');
}
