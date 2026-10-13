const {chromium}=require('/home/user/.cache/epcc-browser/node_modules/playwright');
const fs=require('fs'),assert=require('node:assert/strict');
(async()=>{const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox','--disable-dev-shm-usage']});const p=await browser.newPage(),errors=[],pages=[];p.on('pageerror',e=>errors.push(e.message));try{
await p.goto('http://127.0.0.1:8000/login?lang=fa');await p.locator('#email').fill('karimi@nioc.example');await p.locator('#password').fill('password');await Promise.all([p.waitForURL(u=>u.pathname==='/'),p.locator('button[type=submit]').click()]);
for(const family of ['FITTING','PIPE','FLANGE','VALVE','GASKET','BOLTING'])for(const width of [1440,430])for(const lang of ['fa','en']){
 await p.setViewportSize({width,height:1000});const r=await p.goto(`http://127.0.0.1:8000/projects/PH-14/piping/project-fittings?lang=${lang}&family=${family}`);assert.equal(r.status(),200);
 const m=await p.evaluate(()=>({dir:document.documentElement.dir,overflow:document.documentElement.scrollWidth>innerWidth+1}));assert.equal(m.overflow,false);assert.equal(m.dir,lang==='fa'?'rtl':'ltr');
 if(['PIPE','BOLTING'].includes(family))await p.screenshot({path:`/home/user/organization-report/materials-review-browser/${family}-${lang}-${width}.png`,fullPage:true});pages.push({family,width,lang,...m});
}assert.deepEqual(errors,[]);fs.writeFileSync('/home/user/organization-report/materials-pages.json',JSON.stringify({ok:true,pages,errors},null,2));console.log('24 final read-only page checks passed.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
