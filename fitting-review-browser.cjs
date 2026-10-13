const {chromium}=require('/home/user/.cache/epcc-browser/node_modules/playwright');
const fs=require('fs'),assert=require('node:assert/strict');
if(process.env.EPCC_ALLOW_DEMO_WRITES!=='yes')throw new Error('Explicit demo-write permission required');
const dir='/home/user/organization-report/fitting-review-browser';fs.mkdirSync(dir,{recursive:true});
const base='http://127.0.0.1:8000',route='/projects/PH-14/piping/project-fittings?lang=fa';
const result={steps:[],pages:[],errors:[]};let browser,active;
async function login(email){const context=await browser.newContext({viewport:{width:1440,height:1000}}),p=await context.newPage();p.on('pageerror',e=>result.errors.push(e.message));await p.goto(base+'/login?lang=fa');await p.locator('#email').fill(email);await p.locator('#password').fill('password');await Promise.all([p.waitForURL(u=>u.pathname==='/'),p.locator('button[type=submit]').click()]);return p;}
async function clickNav(p,loc){active=p;await Promise.all([p.waitForNavigation(),loc.click()]);assert.equal(new URL(p.url()).searchParams.get('lang'),'fa');}
async function page(p){active=p;const r=await p.goto(base+route);assert.equal(r.status(),200);}
function card(p,id){return p.locator(`[data-definition="${id}"]`);}
(async()=>{try{
 browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox','--disable-dev-shm-usage']});
 const owner=await login('karimi@nioc.example'),author=await login('rezaei@pars.example'),pmc=await login('tehrani@pmc.example');
 await page(owner);await owner.locator('#review-policy summary').click();
 const options=await owner.locator('[name="stages[0]"] option').evaluateAll(xs=>xs.map(x=>({value:x.value,text:x.textContent})));
 const own=options.find(x=>x.value.startsWith('user:')&&x.text.includes('کریمی')).value;
 const consultant=options.find(x=>x.value.startsWith('role:')&&x.value.endsWith(':consultant')&&x.text.includes('PMC-TEHRAN')).value;
 await owner.locator('[name="stages[0]"]').selectOption(consultant);await owner.locator('[name="stages[1]"]').selectOption(own);
 await clickNav(owner,owner.locator('#review-policy-form button'));result.steps.push('Owner configures two ordered steps: consultant role, named owner');
 await page(author);assert.equal(await author.locator('#review-policy-form').count(),0);
 const spec='SYNTHETIC-REVIEW-52';assert.equal(await author.locator('article').filter({hasText:spec}).count(),0,'Do not rerun this write scenario over existing evidence');
 for(const [key,value]of Object.entries({qualifier:'90LR — synthetic',nps:'52',od_mm:'1320.8',dimension_mm:'1981.2',spec_number:spec,spec_revision:'A',spec_clause:'DEMO-ONLY-1',source_text:'دادهٔ ساختگی برای آزمون گردش‌کار؛ مجوز ساخت یا طراحی نیست. SYNTHETIC ACCEPTANCE ONLY.'}))await author.locator(`[name="${key}"]`).fill(value);
 await clickNav(author,author.locator('#project-fitting-form button'));let c=author.locator('article').filter({hasText:spec});const original=await c.getAttribute('data-definition');result.original=original;
 await clickNav(author,c.locator('.review-submit button'));assert.equal(await card(author,original).locator('.review-decision').count(),0);result.steps.push('Author submits; no self-approval control');
 await page(pmc);let f=card(pmc,original).locator('.review-decision');await f.locator('select').selectOption('RETURN');await f.locator('textarea').fill('بازگشت نمایشی برای اصلاح مقدار بُعد؛ TEST ONLY.');await clickNav(pmc,f.locator('button'));assert.match(await card(pmc,original).innerText(),/نیازمند اصلاح/);result.steps.push('Assigned consultant returns for correction with a recorded comment');
 await page(author);await clickNav(author,card(author,original).locator('a[href*="correct="]'));assert.equal(await author.locator('[name=spec_revision]').inputValue(),'A');await author.locator('[name=dimension_mm]').fill('2000');await author.locator('[name=correction_reason]').fill('اصلاح نمایشی رونویسی؛ بازنگری Spec همچنان A است.');await clickNav(author,author.locator('#project-fitting-form button'));
 const ids=await author.locator('article').filter({hasText:spec}).evaluateAll(xs=>xs.map(x=>x.dataset.definition));const corrected=ids.find(x=>x!==original);assert.ok(corrected);result.corrected=corrected;
 assert.match(await card(author,original).innerText(),/جایگزین/);assert.match(await card(author,corrected).innerText(),/بازبینی‌نشده/);await clickNav(author,card(author,corrected).locator('.review-submit button'));result.steps.push('Correction appends a linked version, keeps Spec A, starts fresh review');
 // New policy must not change either review's frozen policy revision.
 await page(owner);await owner.locator('#review-policy summary').click();await owner.locator('[name="stages[0]"]').selectOption(own);await owner.locator('[name="stages[1]"]').selectOption('');await clickNav(owner,owner.locator('#review-policy-form button'));
 assert.equal(await card(owner,corrected).locator('.review-decision').count(),0);result.steps.push('Policy v2 saved; owner still cannot bypass consultant step of the submitted v1 run');
 await page(pmc);f=card(pmc,corrected).locator('.review-decision');await f.locator('textarea').fill('تأیید مرحلهٔ اول، صرفاً آزمایش.');await clickNav(pmc,f.locator('button'));
 await page(owner);f=card(owner,corrected).locator('.review-decision');await f.locator('textarea').fill('تأیید نهایی نمایشی؛ قابل استفاده برای ساخت نیست.');await clickNav(owner,f.locator('button'));assert.match(await card(owner,corrected).innerText(),/تأیید پروژه‌ای ثبت‌شده/);assert.equal(await card(owner,corrected).locator('.review-decision').count(),0);result.steps.push('Independent owner completes the second step; both signatures and original return remain visible');
 for(const width of [1440,430]){await owner.setViewportSize({width,height:1000});for(const lang of ['fa','en']){const r=await owner.goto(base+route.replace('lang=fa','lang='+lang));assert.equal(r.status(),200);const metrics=await owner.evaluate(()=>({dir:document.documentElement.dir,overflow:document.documentElement.scrollWidth>innerWidth+1}));assert.equal(metrics.overflow,false);assert.equal(metrics.dir,lang==='fa'?'rtl':'ltr');await owner.screenshot({path:`${dir}/${lang}-${width}.png`,fullPage:true});result.pages.push({width,lang,...metrics});}}
 assert.deepEqual(result.errors,[]);result.ok=true;
}catch(e){result.ok=false;result.error=String(e);if(active)await active.screenshot({path:dir+'/failure.png',fullPage:true});process.exitCode=1;}finally{if(browser)await browser.close();fs.writeFileSync(dir+'/results.json',JSON.stringify(result,null,2));console.log(JSON.stringify(result,null,2));}})();
