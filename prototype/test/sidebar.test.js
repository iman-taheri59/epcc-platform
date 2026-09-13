const {JSDOM}=require('jsdom');
const fs=require('fs');
const html=fs.readFileSync(require('path').join(__dirname,'..','index.html'),'utf8');

function boot(width){
  // matchMedia must exist BEFORE the page script runs, or it captures jsdom's stub
  return new JSDOM(html,{runScripts:'dangerously',pretendToBeVisual:true,
    beforeParse(w){
      Object.defineProperty(w,'innerWidth',{value:width,configurable:true});
      w.matchMedia=(q)=>{
        const m=/max-width:\s*(\d+)px/.exec(q);
        return {matches:m?width<=+m[1]:false,media:q,
          addEventListener(){},removeEventListener(){},addListener(){},removeListener(){}};
      };
    }});
}
let pass=0,fail=0;
const chk=(name,cond)=>{ cond?pass++:fail++; console.log(`${cond?'  ok  ':'  FAIL'} ${name}`); };

// ---------- DESKTOP ----------
{
  const dom=boot(1400), w=dom.window, d=w.document;
  // re-run the script's listener wiring against our matchMedia by dispatching a click
  const body=d.body, burger=d.getElementById('burger');
  chk('desktop: starts expanded (no .rail)', !body.classList.contains('rail'));
  burger.click();
  chk('desktop: click collapses to rail', body.classList.contains('rail'));
  chk('desktop: aria-expanded=false when collapsed', burger.getAttribute('aria-expanded')==='false');
  chk('desktop: icon stays ☰ (not ✕)', burger.textContent==='☰');
  burger.click();
  chk('desktop: click again expands', !body.classList.contains('rail'));
  chk('desktop: no drawer class ever set', !body.classList.contains('drawer'));
}
// ---------- MOBILE ----------
{
  const dom=boot(420), w=dom.window, d=w.document;
  const body=d.body, burger=d.getElementById('burger');
  chk('mobile: starts closed (no .drawer)', !body.classList.contains('drawer'));
  burger.click();
  chk('mobile: click opens drawer', body.classList.contains('drawer'));
  chk('mobile: icon becomes ✕', burger.textContent==='✕');
  chk('mobile: aria-expanded=true when open', burger.getAttribute('aria-expanded')==='true');
  // scrim closes it
  d.getElementById('scrim').click();
  chk('mobile: scrim click closes drawer', !body.classList.contains('drawer'));
  // reopen, then navigate -> should auto-close
  burger.click();
  chk('mobile: reopened', body.classList.contains('drawer'));
  d.querySelector('#nav a[data-p="payment"]').click();
  chk('mobile: nav click closes drawer', !body.classList.contains('drawer'));
  chk('mobile: nav click changed page', /صورت‌وضعیت|Payment Certificate/.test(d.getElementById('content').innerHTML));
  // escape key
  burger.click();
  d.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape'}));
  chk('mobile: Escape closes drawer', !body.classList.contains('drawer'));
}
// ---------- RTL + language switch ----------
{
  const dom=boot(420), w=dom.window, d=w.document;
  chk('boots RTL (Persian default)', d.documentElement.dir==='rtl');
  d.getElementById('lang').click();
  chk('lang toggle -> ltr', d.documentElement.dir==='ltr');
  const burger=d.getElementById('burger');
  burger.click();
  chk('drawer works after lang switch', d.body.classList.contains('drawer'));
}
// ---------- table wrapping ----------
{
  const dom=boot(420), d=dom.window.document;
  const wraps=d.querySelectorAll('#content .tblwrap');
  chk('wide tables wrapped for horizontal scroll', wraps.length>0);
}
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail?1:0);
