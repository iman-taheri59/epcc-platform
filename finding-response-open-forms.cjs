const {chromium}=require('/home/user/.cache/epcc-browser/node_modules/playwright');
const fs=require('fs'),assert=require('node:assert/strict');
if(process.env.EPCC_ALLOW_DEMO_WRITES!=='yes')throw new Error('Explicit demo write flag required');
const base='http://127.0.0.1:8000',path='/projects/PH-14/piping/project-fittings?family=PIPE&lang=fa',dir='/home/user/organization-report/finding-response-browser';
let browser;const result={pages:[],errors:[]};
async function login(email){const ctx=await browser.newContext({viewport:{width:430,height:1000}}),p=await ctx.newPage();p.on('pageerror',e=>result.errors.push(e.message));await p.goto(base+'/login?lang=fa');await p.locator('#email').fill(email);await p.locator('#password').fill('password');await Promise.all([p.waitForURL(u=>u.pathname==='/'),p.locator('button[type=submit]').click()]);return p;}
async function click(p,l){await Promise.all([p.waitForNavigation(),l.click()]);}
(async()=>{try{
 browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox','--disable-dev-shm-usage']});
 const author=await login('rezaei@pars.example'),owner=await login('karimi@nioc.example');
 await author.goto(base+path);const spec='SYNTHETIC-OPEN-FINDING-DISCUSSION';assert.equal(await author.locator('[data-definition]').filter({hasText:spec}).count(),0,'Refuse duplicate write fixture');
 const form=author.locator('#project-fitting-form');
 for(const [key,value] of Object.entries({qualifier:'SEAMLESS — QA',size_designation:'NPS 6 in',reference_standard:'ASME B36.10M-2022',material_spec:'SYNTHETIC QA ONLY',declaration_reason:'Synthetic open discussion fixture; not engineering data.',spec_number:spec,spec_revision:'A',spec_clause:'QA-1',source_text:'دادهٔ ساختگی برای نمایش پاسخ مستند؛ مجوز طراحی یا ساخت نیست.', 'properties[schedule]':'40','properties[wall_thickness_mm]':'7.11','properties[outside_diameter_mm]':'168.3'}))await form.locator(`[name="${key}"]`).fill(value);
 await click(author,form.locator('button'));const card=author.locator('[data-definition]').filter({hasText:spec});result.definition=await card.getAttribute('data-definition');await click(author,card.locator('.review-submit button'));
 for(const [actor,p] of [['author',author],['reviewer',owner]])for(const lang of ['fa','en']){
  const r=await p.goto(base+path.replace('lang=fa','lang='+lang));assert.equal(r.status(),200);
  const c=p.locator(`[data-definition="${result.definition}"]`),thread=c.locator('[data-finding-thread="0"]');await thread.locator(':scope > summary').click();
  const f=thread.locator('.finding-response-form');assert.equal(await f.count(),1);
  const kinds=await f.locator('[name=kind] option').evaluateAll(xs=>xs.map(x=>x.value));assert.deepEqual(kinds,actor==='author'?['EXPLANATION','NA_REQUEST']:['REVIEW_NOTE']);
  if(actor==='author'){await f.locator('[name=kind]').selectOption('NA_REQUEST');await f.locator('[name=message]').fill('پیشنهاد نمایشی عدم‌کاربرد؛ صرفاً برای آزمون رابط.');await f.locator('[name=source_quote]').fill('نقل‌قول ساختگی از منبع؛ داده مهندسی نیست.');}
  await f.locator('button').scrollIntoViewIfNeeded();
  assert.equal(await f.locator('button').evaluate(b=>{const r=b.getBoundingClientRect();return b.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2));}),true,'Button must not be occluded');
  const overflow=await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);assert.equal(overflow,false);
  await thread.screenshot({path:`${dir}/active-${actor}-${lang}-430.png`,animations:'disabled'});
  result.pages.push({actor,lang,width:430,overflow,kinds,buttonUnobstructed:true});
 }
 assert.deepEqual(result.errors,[]);result.ok=true;
}catch(e){result.ok=false;result.error=String(e);process.exitCode=1;}finally{if(browser)await browser.close();fs.writeFileSync(dir+'/open-forms.json',JSON.stringify(result,null,2));console.log(JSON.stringify(result,null,2));}})();
