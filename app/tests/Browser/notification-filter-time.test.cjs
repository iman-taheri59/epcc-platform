// Run: node tests/Browser/notification-filter-time.test.cjs
// Requires rendered synthetic fixtures from EPCC_FILTER_UI_EVIDENCE=1 phpunit.
const { chromium } = require('/opt/playwright/node_modules/playwright');
const fs = require('fs');
const path = require('path');
const dir = path.resolve(__dirname, '../../../docs/evidence/2026-10-20-inspection-notification-filter-ui');
let assertions = 0; const cases = [];
function check(value, message) { assertions++; if (!value) throw new Error(message); }
(async () => {
 const browser = await chromium.launch({ executablePath: '/usr/bin/chromium', args: ['--no-sandbox'] });
 try {
 for (const lang of ['en','fa']) for (const timezoneId of ['Asia/Tehran','America/New_York']) {
  const context = await browser.newContext({ timezoneId, viewport: {width: 1280,height: 1000} });
  const page = await context.newPage(); const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.route('**/*', r => r.abort()); // offline fixture, never send an authenticated action
  async function load(kind) {
   await page.setContent(fs.readFileSync(path.join(dir,`${kind}-${lang}.html`),'utf8'));
   await page.evaluate(() => { window.submissions=[]; document.addEventListener('submit',e=>{ e.preventDefault(); window.submissions.push({id:e.target.id, data:Array.from(new FormData(e.target).entries())}); }); });
  }
  const widget = b => page.locator(`[data-boundary="filter-${b}"]`);
  const field = (b,part) => widget(b).locator(`[data-time-${part}]`);
  const valid = () => page.locator('#inspection-notification-filters').evaluate(f=>f.checkValidity());
  const submit = () => page.locator('#inspection-notification-filters button').click();
  const count = () => page.evaluate(()=>window.submissions.length);
  const fill = async(b,date,clock,offset) => {await field(b,'date').fill(date);await field(b,'clock').fill(clock);await field(b,'offset').fill(offset);};
  await load('empty');
  check(await valid(), 'both optional bounds blank must be valid');
  check(await field('from','exact').getAttribute('name')==='activity_from','GET canonical from name');
  check(await field('from','exact').evaluate(e=>e.readOnly&&!e.required),'optional canonical readonly');
  check(await field('from','offset').inputValue()==='', 'never default offset');
  await submit(); check(await count()===1,'unbounded submit fires');
  await field('from','date').fill('2026-10-25');
  check(!await valid(),'partial bound blocks submit'); await submit();check(await count()===1,'partial cannot submit');
  check(await field('from','exact').inputValue()==='','partial cannot reuse stale exact');
  await field('from','clock').fill('23:30:45'); await field('from','offset').fill('+03:30');
  check(await valid(),'one complete lower bound sufficient');
  check(await field('from','exact').inputValue()==='2026-10-25T23:30:45+03:30','serialize without local zone conversion');
  await submit();
  let payload = await page.evaluate(()=>Object.fromEntries(window.submissions.at(-1).data));
  check(payload.activity_from==='2026-10-25T23:30:45+03:30' && payload.activity_to==='', 'one-sided canonical payload');
  check(payload.lang===lang && payload.stage && !('notice_page' in payload),'preserve context and reset notice page');
  check(!Object.keys(payload).some(k=>k.startsWith('notification[')),'GET must never include draft write fields');
  await fill('to','2026-10-26','00:05:15','+03:30'); check(await valid(),'cross-midnight bounds valid');
  await field('from','offset').fill(''); check(!await valid(),'clearing offset blocks stale bound');
  check(await field('from','exact').inputValue()==='','stale canonical cleared');
  await field('from','date').fill('');await field('from','clock').fill('');check(await valid(),'clearing all omits bound');
  await submit();payload=await page.evaluate(()=>Object.fromEntries(window.submissions.at(-1).data));
  check(payload.activity_from==='' && payload.activity_to==='2026-10-26T00:05:15+03:30','upper-only payload');
  await field('to','manual').check();
  await field('to','exact').fill('2026-02-30T09:30:00+03:30');check(!await valid(),'invalid manual Gregorian date');
  await field('to','exact').fill('2026-10-25T09:30:45+00:00');check(await valid(),'manual explicit UTC accepted');
  await field('to','manual').uncheck();
  check(await field('to','clock').inputValue()==='09:30:45' && await field('to','offset').inputValue()==='+00:00','manual/helper roundtrip retains seconds and offset');
  await field('to','offset').fill('-00:00');check(!await valid(),'unknown offset rejected');
  await field('to','offset').fill('+16:00');check(!await valid(),'unsupported offset rejected');
  await load('prefilled');
  check(await valid(),'equal bounds allowed for instant query');
  check(await field('from','exact').inputValue()==='2026-10-25T09:30:45+03:30','prefill preserves exact offset');
  check(await field('from','clock').inputValue()==='09:30:45','prefill seconds');
  const hrefs=await page.locator('a').evaluateAll(nodes=>nodes.map(n=>n.getAttribute('href')));
  check(hrefs.some(h=>h.includes(`lang=${lang==='en'?'fa':'en'}`)&&h.includes('activity_from=')&&h.includes('activity_to=')),'language link keeps bounds');
  const clear=await page.locator('#inspection-notification-clear-filters').getAttribute('href');
  check(!clear.includes('activity_from')&&!clear.includes('notice_q')&&clear.includes('stage=')&&clear.includes(`lang=${lang}`),'clear keeps stage/lang only');
  const draft=page.locator('#inspection-notification-draft');
  check(await draft.locator('[data-time-exact]').count()===2,'two independent required draft inputs');
  check(await draft.locator('[data-time-exact]').evaluateAll(a=>a.every(e=>e.required && e.name.startsWith('notification['))),'draft write bounds still required');
  await page.locator('[data-boundary="start"] [data-time-date]').fill('2026-10-25');
  check(await field('from','exact').inputValue()==='2026-10-25T09:30:45+03:30','draft helper cannot overwrite filter');
  await load('edit');
  check(await page.locator('[data-boundary="start"] [data-time-exact]').inputValue()==='2026-10-25T09:30:00+03:30','edit prefill unchanged');
  check(await page.locator('[data-time-exact]').evaluateAll(a=>a.every(e=>e.required)),'edit fields required');
  check(errors.length===0, `no JS errors: ${errors.join('; ')}`);
  cases.push(`${lang}/${timezoneId}: optional, partial, clear, serialization, UTC, direct, prefill, GET context, draft/edit separation`);
  await context.close();
 }
 for (const lang of ['en','fa']) {
  const context=await browser.newContext({javaScriptEnabled:false});const page=await context.newPage();
  await page.setContent(fs.readFileSync(path.join(dir,`empty-${lang}.html`),'utf8'));
  check(await page.locator('#inspection-notification-filters').evaluate(f=>f.checkValidity()),'no-JS optional blank');
  check(!await page.locator('[data-boundary="filter-from"] [data-time-helper]').isVisible(),'no-JS helper hidden');
  check(await page.locator('[data-boundary="filter-from"] [data-time-exact]').evaluate(e=>!e.readOnly&&!e.required),'no-JS exact input usable');
  await page.locator('[name="activity_to"]').fill('2026-10-25T09:30:00+03:30');
  check(await page.locator('[name="activity_to"]').inputValue()==='2026-10-25T09:30:00+03:30','no-JS direct payload');
  cases.push(`${lang}: no-JS fallback`);await context.close();
 }
 const mobile=await browser.newContext({viewport:{width:390,height:844},timezoneId:'Asia/Tehran'});
 const page=await mobile.newPage();await page.setContent(fs.readFileSync(path.join(dir,'prefilled-fa.html'),'utf8'));
 const filter=page.locator('#inspection-notification-filters');await filter.scrollIntoViewIfNeeded();
 check(await page.locator('html').getAttribute('dir')==='rtl','Persian RTL');
 check(await filter.locator('[data-time-exact]').evaluateAll(a=>a.every(e=>e.dir==='ltr')),'exact offset values LTR');
 check(await filter.locator('[data-time-date], [data-time-clock], [data-time-offset]').evaluateAll(a=>a.every(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=window.innerWidth;})),'mobile time controls fit viewport');
 await filter.screenshot({path:path.join(dir,'filter-time-fa-mobile.png')});cases.push('fa/mobile: RTL/LTR and control fit');
 await mobile.close();
 const result={status:'PASS',cases:cases.length,assertions,browser:'Chromium via Playwright, offline current Laravel-rendered synthetic fixtures',scope:cases,limitations:'Native date-picker popup, Safari/Firefox, live authenticated HTTP navigation and concurrent/load behavior not certified. Server filter/scope validation separately feature-tested.'};
 fs.writeFileSync(path.join(dir,'browser-summary.json'),JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify(result,null,2));
 } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
