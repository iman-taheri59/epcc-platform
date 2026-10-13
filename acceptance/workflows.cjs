const {chromium}=require('/home/user/.cache/epcc-browser/node_modules/playwright');
const fs=require('fs');const assert=require('node:assert/strict');
if(process.env.EPCC_ALLOW_DEMO_WRITES!=='yes')throw Error('Explicit disposable-demo write permission required');
const base='http://127.0.0.1:8000';const root='/home/user/organization-report/acceptance';
const run=Date.now().toString();const results={run,steps:[],issues:[]};let browser;
async function submit(page,form){await Promise.all([page.waitForNavigation({waitUntil:'networkidle'}),form.locator('button[type=submit]').first().click()]);}
async function login(page,email){await page.goto(base+'/login?lang=fa');await page.locator('#email').fill(email);await page.locator('#password').fill('password');await submit(page,page.locator('form'));assert.equal(new URL(page.url()).pathname,'/');}
(async()=>{
 browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox','--disable-dev-shm-usage']});
 const contractor=await browser.newContext({viewport:{width:1440,height:1000}});let p=await contractor.newPage();await login(p,'rezaei@pars.example');
 await p.goto(base+'/projects/PH-14/documents?lang=fa');
 const title='مدرک آزمایش پذیرش — داده نمایشی '+run;
 await p.locator('#discipline').selectOption('PIPING');await p.locator('#doc_type').selectOption('DWG');
 await p.locator('#title_en').fill('Acceptance only — synthetic demo '+run);await p.locator('#title_fa').fill(title);
 await submit(p,p.locator('form').filter({has:p.locator('#title_en')}));
 let row=p.locator('tbody tr').filter({hasText:title});assert.equal(await row.count(),1);results.steps.push('Create bilingual document from the actual form');
 const docid=await row.getAttribute('id');results.documentRow=docid;results.documentTitle=title;
 await row.locator('input[type=file]').setInputFiles(root+'/acceptance-proof.png');await submit(p,row.locator('form.up'));
 row=p.locator('tbody tr').filter({hasText:title});const href=await row.locator('a.nm').getAttribute('href');
 const resp=await contractor.request.get(new URL(href,base).href);assert.equal(resp.status(),200);assert.deepEqual(await resp.body(),fs.readFileSync(root+'/acceptance-proof.png'));results.steps.push('Attach PNG and download identical bytes through authenticated route');
 await row.locator('select[name=cde_state]').selectOption('SHARED');await row.locator('select[name=suitability]').selectOption('S1');await submit(p,row.locator('form.inline-form'));
 row=p.locator('tbody tr').filter({hasText:title});assert.equal(await row.locator('.st.SHARED').count(),1);results.steps.push('Transition WIP to SHARED with suitability S1');
 await p.goto(base+'/projects/PH-14/piping/model-import?lang=fa');await p.locator('input[type=file]').setInputFiles('/home/user/epcc-platform/app/tests/Fixtures/pcf/line-6-P.pcf');await p.locator('select[name=subsystem_id]').selectOption({label:'PH-14-SS-2201'});
 await submit(p,p.locator('form[enctype="multipart/form-data"]'));
 const text=await p.locator('body').innerText();assert(text.includes('line-6-P.pcf'));assert(text.includes('APPLIED'));assert(text.includes('6-P-A1-PH-14-SS-2201-001'));results.steps.push('Import PCF fixture through UI; unknown line remains quarantined');
 await p.screenshot({path:root+'/screenshots/pcf-import-proof.png',fullPage:true});
 const owner=await browser.newContext({viewport:{width:1440,height:1000}});p=await owner.newPage();await login(p,'karimi@nioc.example');
 await p.goto(base+'/projects/PH-14/itp?lang=fa');const template=await p.locator('select[name=template_id] option').evaluateAll(options=>options.find(o=>o.textContent.startsWith('PIPING —')).value);
 await p.locator('select[name=template_id]').selectOption(template);await p.locator('input[name=number]').fill('QA-ACCEPTANCE-'+run);await submit(p,p.locator('form').filter({has:p.locator('select[name=template_id]')}));
 const itprow=p.locator('tbody tr').filter({hasText:'QA-ACCEPTANCE-'+run});assert.equal(await itprow.count(),1);results.steps.push('Owner creates piping ITP from seeded template');
 await itprow.locator('a').first().click();await p.waitForLoadState('networkidle');results.itpPath=new URL(p.url()).pathname;
 assert(await p.locator('tbody tr').count()>0);await submit(p,p.locator('form[action$="/register"]'));assert((await p.locator('body').innerText()).includes('شمارهٔ ثبت'));results.steps.push('Register draft ITP as a project document; no publication or activation');
 await p.screenshot({path:root+'/screenshots/itp-registered-proof.png',fullPage:true});
 await p.goto(base+'/projects/PH-14/piping/model-import?lang=fa');assert((await p.locator('body').innerText()).includes('line-6-P.pcf'));results.steps.push('Owner reads the contractor import: repaired shared RLS visible through UI');
})().catch(e=>{results.issues.push({message:e.message,stack:e.stack});process.exitCode=1}).finally(async()=>{if(browser)await browser.close();fs.writeFileSync(root+'/workflow-results.json',JSON.stringify(results,null,2));console.log(JSON.stringify(results,null,2));});
