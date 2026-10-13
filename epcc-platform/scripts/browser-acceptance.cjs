/* Live read-only acceptance on a seeded demo. Requires Playwright and Chromium.
 * No migrations, seeding or application writes other than login/logout sessions.
 * EPCC_BROWSER_MODULE may point at an external test-tool installation.
 */
const { chromium } = require(process.env.EPCC_BROWSER_MODULE || 'playwright');
const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');
const base = process.env.EPCC_BASE_URL || 'http://127.0.0.1:8000';
const output = process.env.EPCC_ACCEPTANCE_OUTPUT || '/tmp/epcc-acceptance';
fs.mkdirSync(path.join(output, 'screenshots'), {recursive:true});
const results = {base, pages:[], pdfs:[], interactions:[], consoleErrors:[], failedRequests:[], issues:[]};
const routes = [
 ['portfolio','/'], ['project','/projects/PH-14'], ['second-project','/projects/KISH-1'],
 ['documents','/projects/PH-14/documents'], ['transmittals','/projects/PH-14/transmittals'],
 ['punch','/projects/PH-14/punch'], ['piping','/projects/PH-14/piping'],
 ['piping-config','/projects/PH-14/piping/config'], ['model-import','/projects/PH-14/piping/model-import'],
 ['itp','/projects/PH-14/itp'], ['standards','/projects/PH-14/standards'],
 ['procurement','/projects/PH-14/procurement'], ['materials','/projects/PH-14/materials'],
 ['team','/projects/PH-14/team'], ['admin','/admin']
];
if (process.env.EPCC_EXTRA_ROUTES_JSON) routes.push(...JSON.parse(process.env.EPCC_EXTRA_ROUTES_JSON));
let browser;
async function login(page,email) {
 await page.goto(base+'/login?lang=fa');
 await page.locator('#email').fill(email);
 await page.locator('#password').fill(process.env.EPCC_DEMO_PASSWORD || 'password');
 await Promise.all([page.waitForURL(u=>u.pathname==='/',{timeout:20000}),page.locator('button[type=submit]').click()]);
}
(async()=>{
 browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium',args:['--no-sandbox','--disable-dev-shm-usage']});
 for (const width of [1440,430]) {
  const context=await browser.newContext({viewport:{width,height:1000},acceptDownloads:true});
  const page=await context.newPage();
  page.on('pageerror',e=>results.consoleErrors.push({width,path:new URL(page.url()).pathname,error:e.message}));
  page.on('requestfailed',r=>results.failedRequests.push({path:new URL(r.url()).pathname,error:r.failure()?.errorText}));
  await login(page,'karimi@nioc.example');
  for (const lang of ['fa','en']) {
   for (const [name,url] of routes) {
    const response=await page.goto(base+url+'?lang='+lang,{waitUntil:'networkidle'});
    const info=await page.evaluate(()=>{
      const heading=document.querySelector('h1');const wrap=document.querySelector('.wrap');
      const rect=heading?.getBoundingClientRect();
      return {title:document.title,lang:document.documentElement.lang,dir:document.documentElement.dir,
       viewport:innerWidth,scrollWidth:document.documentElement.scrollWidth,
       textLength:document.body.innerText.length,heading:heading?.innerText,
       headingLeft:rect?.left,headingRight:rect?.right,
       wrapLeft:wrap?.getBoundingClientRect().left,wrapWidth:wrap?.getBoundingClientRect().width,
       tables:document.querySelectorAll('table').length};
    });
    const item={name,lang,width,status:response.status(),...info};results.pages.push(item);
    if(response.status()!==200) results.issues.push({name,lang,width,reason:'HTTP '+response.status()});
    if(info.textLength<60) results.issues.push({name,lang,width,reason:'Nearly blank page'});
    if(info.dir !== (lang==='fa'?'rtl':'ltr')) results.issues.push({name,lang,width,reason:'Wrong direction'});
    if(info.scrollWidth>width+3) results.issues.push({name,lang,width,reason:'Document horizontal overflow',scrollWidth:info.scrollWidth});
    if(info.headingRight<0 || info.headingLeft>width) results.issues.push({name,lang,width,reason:'Heading offscreen'});
    if(lang==='fa' && ['portfolio','documents','materials','itp','piping','admin','itp-detail','piping-model'].includes(name))
      await page.screenshot({path:path.join(output,'screenshots',`${name}-${lang}-${width}.png`),fullPage:true});
    if(width===1440 && ['punch','transmittals'].includes(name)) {
      const link=page.locator('a[href*=".pdf"]').first();
      if(await link.count()) {
       const pdf=await context.request.get(new URL(await link.getAttribute('href'),base).href);
       const bytes=await pdf.body();
       const ok=pdf.status()===200 && bytes.subarray(0,5).toString()==='%PDF-';
       results.pdfs.push({name,lang,status:pdf.status(),bytes:bytes.length,ok});
       if(ok)fs.writeFileSync(path.join(output,`${name}-${lang}.pdf`),bytes);
       else results.issues.push({name,lang,reason:'PDF request failed'});
      } else results.issues.push({name,lang,reason:'No reachable PDF link'});
    }
   }
  }
  await page.goto(base+'/projects/PH-14/documents?lang=fa');
  const burger=page.locator('#sb-burger');
  if(width===430) {
    await burger.click();await page.waitForTimeout(400);
    assert(await page.locator('body').evaluate(e=>e.classList.contains('drawer')));
    await page.screenshot({path:path.join(output,'screenshots','mobile-menu-open.png'),fullPage:true});
    await page.keyboard.press('Escape');
    assert(!await page.locator('body').evaluate(e=>e.classList.contains('drawer')));
    results.interactions.push('Mobile drawer opens and closes with Escape');
  } else {
    await burger.click();await page.waitForTimeout(400);
    assert(await page.locator('body').evaluate(e=>e.classList.contains('rail')));
    await page.reload();
    assert(await page.locator('body').evaluate(e=>e.classList.contains('rail')));
    results.interactions.push('Desktop sidebar collapse survives navigation');
  }
  await context.close();
 }
 const context=await browser.newContext();const page=await context.newPage();
 await login(page,'rezaei@pars.example');
 let r=await page.goto(base+'/projects/PH-14/vendor-transmittals?lang=fa');
 assert.equal(r.status(),200);results.interactions.push('Contractor can open its vendor-transmittal stream');
 r=await page.goto(base+'/projects/KISH-1?lang=fa');
 assert([403,404].includes(r.status()));results.interactions.push('Nonparticipant contractor denied KISH-1');
 await context.close();
})().catch(e=>{results.issues.push({reason:e.message,stack:e.stack});process.exitCode=1}).finally(async()=>{
 if(browser)await browser.close();
 fs.writeFileSync(path.join(output,'browser-results.json'),JSON.stringify(results,null,2));
 console.log(JSON.stringify({pages:results.pages.length,pdfs:results.pdfs,interactions:results.interactions,issues:results.issues,consoleErrors:results.consoleErrors,failedRequests:results.failedRequests},null,2));
 if(results.issues.length || results.consoleErrors.length)process.exitCode=1;
});
