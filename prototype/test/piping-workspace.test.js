const {JSDOM}=require('jsdom');
const fs=require('fs');
const path=require('path');
const html=fs.readFileSync(path.join(__dirname,'..','piping-workspace.html'),'utf8');

function boot(width=390){
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

// ---------- Persian boot and filter localisation ----------
{
  const dom=boot(390), d=dom.window.document;
  chk('boots in Persian', d.documentElement.lang==='fa' && d.documentElement.dir==='rtl');
  chk('subsystem default is Persian', d.querySelector('#subsystem option').textContent.trim()==='همهٔ زیرسیستم‌ها');
  chk('search placeholder is Persian', d.getElementById('q').getAttribute('placeholder')==='جستجو: خط، زیرسیستم، کلاس…');
  const checkboxLabels=[...d.querySelectorAll('.bar .check [data-fa]')].map(n=>n.textContent.trim()).join(' | ');
  chk('checkbox labels are Persian', checkboxLabels.includes('فقط مسدودها') && checkboxLabels.includes('آماده قدم بعد'));
  chk('Persian visible filter text has no stale English literals', !/All subsystems|Ready next|Blocked only|line, subsystem, class/i.test(checkboxLabels+' '+d.getElementById('q').getAttribute('placeholder')+' '+d.querySelector('#subsystem option').textContent));
}

// ---------- English switch ----------
{
  const dom=boot(390), d=dom.window.document;
  d.getElementById('langBtn').click();
  chk('language switch sets LTR English', d.documentElement.lang==='en' && d.documentElement.dir==='ltr');
  chk('subsystem default switches to English', d.querySelector('#subsystem option').textContent.trim()==='All subsystems');
  chk('search placeholder switches to English', d.getElementById('q').getAttribute('placeholder')==='Search: line, subsystem, class…');
}

// ---------- responsive structure guards ----------
{
  const d=boot(390).window.document;
  const css=[...d.querySelectorAll('style')].map(s=>s.textContent).join('\n');
  chk('filter bar is constrained grid', /\.bar\{display:grid;grid-template-columns:minmax\(240px,1fr\)/.test(css));
  chk('mobile filter becomes one column', /@media\(max-width:760px\)[\s\S]*\.bar\{padding:10px;grid-template-columns:minmax\(0,1fr\)/.test(css));
  chk('checkbox text can wrap', css.includes('overflow-wrap:anywhere') && css.includes('grid-template-columns:auto minmax(0,1fr)'));
  chk('line table rows carry mobile labels', [...d.querySelectorAll('.line-table tbody tr:first-child td')].every(td=>td.hasAttribute('data-label')));
}

// ---------- task-first behaviours ----------
{
  const dom=boot(390), d=dom.window.document;
  d.getElementById('blockedOnly').click();
  d.getElementById('blockedOnly').dispatchEvent(new dom.window.Event('input',{bubbles:true}));
  const rows=[...d.querySelectorAll('.line-table tbody tr')];
  chk('blocked-only filter narrows the list', rows.length>0 && rows.length<8);
  rows[0].click();
  chk('line click opens detail drawer', d.getElementById('drawer').classList.contains('open'));
  chk('drawer contains readiness actions', /ارسال به مرحله بعد|Move to next step/.test(d.getElementById('drawerBody').textContent));
}

// ---------- air-gap guard ----------
{
  chk('prototype has no external references', !/https?:\/\/|\/\/cdn|fonts\.googleapis|ui-avatars/i.test(html));
}

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail?1:0);
