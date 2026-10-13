const {chromium}=require('/home/user/.cache/epcc-browser/node_modules/playwright');const fs=require('fs'),assert=require('node:assert/strict');
if(process.env.EPCC_ALLOW_DEMO_WRITES!=='yes')throw new Error('Explicit demo write flag required');
const base='http://127.0.0.1:8000',route='/projects/PH-14/piping/project-fittings?lang=fa&family=PIPE',dir='/home/user/organization-report/finding-response-browser';
const id=JSON.parse(fs.readFileSync(dir+'/open-forms.json','utf8')).definition;let browser;const result={steps:[],pages:[],activeForms:[],errors:[]};
async function login(email){const ctx=await browser.newContext({viewport:{width:430,height:1000}}),p=await ctx.newPage();p.on('pageerror',e=>result.errors.push(e.message));await p.goto(base+'/login?lang=fa');await p.locator('#email').fill(email);await p.locator('#password').fill('password');await Promise.all([p.waitForURL(u=>u.pathname==='/'),p.locator('button[type=submit]').click()]);return p;}
function card(p){return p.locator(`[data-definition="${id}"]`);}
async function thread(p){const d=card(p).locator('[data-finding-thread="0"]');await d.locator(':scope > summary').click();return d;}
(async()=>{try{
 browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox','--disable-dev-shm-usage']});
 const author=await login('rezaei@pars.example'),owner=await login('karimi@nioc.example');await author.goto(base+route);
 assert.equal(await card(author).locator('[data-response]').count(),0,'Refuse to rerun final-write fixture');
 const hash=await card(author).locator('[data-screening-snapshot]').getAttribute('data-result-sha256');
 for(const [p,kind,message,quote] of [[author,'NA_REQUEST','NESTED-FINAL-AUTHOR — پیشنهاد ساختگی برای آزمون، نه مجوز مهندسی.','نقل‌قول ساختگی برای آزمون مسیر ثبت مستند.'],[owner,'REVIEW_NOTE','NESTED-FINAL-REVIEWER — ثبت نظر بازبین؛ تصمیم تأیید هنوز جداگانه است.','']]){
  await p.goto(base+route);const d=await thread(p),f=d.locator('.finding-response-form');
  await f.locator('[name="finding_response[kind]"]').selectOption(kind);await f.locator('[name="finding_response[message]"]').fill(message);await f.locator('[name="finding_response[source_quote]"]').fill(quote);
  await Promise.all([p.waitForNavigation(),f.locator('button').click()]);assert.equal(await p.locator('[role=alert]').count(),0);assert.equal(new URL(p.url()).searchParams.get('family'),'PIPE');
  assert.match(await card(p).locator('.spec-badge').innerText(),/در انتظار/);assert.equal(await card(p).locator('[data-screening-snapshot]').getAttribute('data-result-sha256'),hash);
  result.steps.push(kind+' posted through the scoped payload without changing screening or review state');
 }
 assert.equal(await card(owner).locator('[data-response]').count(),2);
 for(const [actor,p] of [['author',author],['reviewer',owner]])for(const lang of ['fa','en']){
  await p.setViewportSize({width:430,height:1000});await p.goto(base+route.replace('lang=fa','lang='+lang));const d=await thread(p),f=d.locator('.finding-response-form');
  const kinds=await f.locator('[name="finding_response[kind]"] option').evaluateAll(xs=>xs.map(x=>x.value));assert.deepEqual(kinds,actor==='author'?['EXPLANATION','NA_REQUEST']:['REVIEW_NOTE']);
  await f.locator('button').scrollIntoViewIfNeeded();assert.equal(await f.locator('button').evaluate(b=>{const r=b.getBoundingClientRect();return b.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2));}),true);
  assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);
  await d.screenshot({path:`${dir}/final-active-${actor}-${lang}-430.png`,animations:'disabled'});result.activeForms.push({actor,lang,width:430,kinds,unobstructed:true,overflow:false});
 }
 for(const family of ['FITTING','PIPE','FLANGE','VALVE','GASKET','BOLTING'])for(const width of [1440,430])for(const lang of ['fa','en']){
  await owner.setViewportSize({width,height:1000});const r=await owner.goto(base+route.replace('lang=fa','lang='+lang).replace('family=PIPE','family='+family));assert.equal(r.status(),200);
  const m=await owner.evaluate(()=>({dir:document.documentElement.dir,overflow:document.documentElement.scrollWidth>innerWidth+1}));assert.equal(m.overflow,false);assert.equal(m.dir,lang==='fa'?'rtl':'ltr');
  assert.equal(await owner.locator('[data-screening-snapshot]').count(),family==='PIPE'?3:0);assert.equal(await owner.locator('[data-response]').count(),family==='PIPE'?7:0);
  if(family==='PIPE' && width===430){await thread(owner);assert.equal(await owner.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);}
  result.pages.push({family,width,lang,...m});
 }
 assert.deepEqual(result.errors,[]);result.ok=true;
}catch(e){result.ok=false;result.error=String(e);process.exitCode=1;}finally{if(browser)await browser.close();fs.writeFileSync(dir+'/final-results.json',JSON.stringify(result,null,2));console.log(JSON.stringify(result,null,2));}})();
