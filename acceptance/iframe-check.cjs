const {chromium}=require('/home/user/.cache/epcc-browser/node_modules/playwright');const fs=require('fs');const assert=require('node:assert/strict');
(async()=>{const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox','--disable-dev-shm-usage','--no-proxy-server','--host-resolver-rules=MAP preview-test.e2b.app 127.0.0.1, MAP wrapper.test 127.0.0.1']});
try{
const ctx=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1440,height:1000}});let stripped=0;
await ctx.route('https://preview-test.e2b.app:8443/**',async route=>{const headers=await route.request().allHeaders();delete headers.cookie;stripped++;await route.continue({headers});});
const p=await ctx.newPage();await p.goto('https://wrapper.test:8443/');const frame=p.frameLocator('iframe');
await frame.locator('#email').fill('karimi@nioc.example');await frame.locator('#password').fill('password');await frame.locator('button[type=submit]').click();await frame.locator('a[href*="/projects/PH-14"]').first().waitFor();
await frame.locator('a[href*="/projects/PH-14"]').first().click();await frame.locator('a[href*="/documents"]').first().waitFor();await frame.locator('a[href*="/documents"]').first().click();await frame.locator('table[data-tt]').waitFor();
assert((await frame.locator('h1').innerText()).includes('مدارک'));const url=new URL(p.frames().find(f=>f.url().includes('preview-test')).url());assert(url.searchParams.has('sid'));
await p.screenshot({path:'/home/user/organization-report/acceptance/screenshots/cookieless-iframe.png'});
const result={ok:true,cookieHeadersStripped:stripped,flow:['HTTPS cross-site iframe','login without sending cookies','open project','open document register'],notes:'Local TLS/host simulation, not a direct authenticated request through the Arena traffic gateway.'};fs.writeFileSync('/home/user/organization-report/acceptance/iframe-results.json',JSON.stringify(result,null,2));console.log(result);
}finally{await browser.close();}})().catch(e=>{console.error(e.message);process.exit(1)});
