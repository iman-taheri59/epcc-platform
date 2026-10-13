/**
 * Sidebar collapse ANIMATION, asserted on computed styles.
 *
 * The toggle worked but felt broken: the desktop rail snapped open and shut
 * with no animation at all. Two independent causes, and fixing either alone
 * leaves it still wrong:
 *
 *   1. `.sb` had NO transition on desktop. The only transition in the file
 *      lived inside the mobile media query and applied to `transform`, so the
 *      desktop width change simply snapped.
 *   2. The labels were hidden with `display:none`, which is a DISCRETE
 *      property - it cannot be interpolated. However smooth the container is,
 *      the text vanishes in a single frame.
 *
 * So this asserts the mechanism, not just the appearance: labels must collapse
 * via animatable properties (`max-width` / `opacity` / `height`) and must never
 * regress to `display:none`.
 *
 * Usage:
 *   1. capture a logged-in page to /tmp/sb.html (see REBUILD.md)
 *   2. npm ci                # at the project root
 *   3. node tests/Browser/sidebar-animation.test.cjs
 *
 * jsdom applies real CSS cascade to getComputedStyle, which is what makes this
 * meaningful - it is not string-matching the stylesheet.
 */
const {JSDOM}=require(process.env.JSDOM_PATH || 'jsdom');
const fs=require('fs');
let pass=0,fail=0;
const chk=(n,c,extra)=>{c?(pass++,console.log('  OK   '+n)):(fail++,console.log('  FAIL '+n+(extra?' -> '+extra:'')));};

const fixture = process.env.SB_FIXTURE || '/tmp/sb.html';
if (!fs.existsSync(fixture)) {
  console.error('fixture missing: ' + fixture + '\nCapture a logged-in page first (see the header comment).');
  process.exit(2);
}
const html=fs.readFileSync(fixture,'utf8');
const dom=new JSDOM(html,{runScripts:'dangerously',url:'https://x.arena.site/',pretendToBeVisual:true,
  beforeParse(w){ w.matchMedia=q=>({matches:false,media:q,addEventListener(){},removeEventListener(){},addListener(){},removeListener(){}});
    Object.defineProperty(w,'innerWidth',{value:1200,writable:true}); }});

dom.window.addEventListener('load',()=>{
  const w=dom.window,d=w.document,cs=el=>w.getComputedStyle(el);
  const sb=d.getElementById('sb');
  const label=d.querySelector('.sb-i > span');
  const sec=d.querySelector('.sb-sec');
  const burger=d.getElementById('sb-burger');

  console.log('== expanded ==');
  const t=cs(sb).transition||'';
  chk('sidebar has a width transition', /flex-basis|width/.test(t) && /s\b/.test(t), JSON.stringify(t));
  const lt=cs(label).transition||'';
  chk('labels animate via max-width', /max-width/.test(lt), JSON.stringify(lt));
  chk('label visible when expanded', cs(label).maxWidth!=='0px' && cs(label).opacity!=='0');

  // REGRESSION GUARD: max-width and overflow are ignored on a non-replaced
  // inline box. The language switch is an <a> (inline by default); when it
  // inherited the collapse rules it laid out at zero usable width and
  // disappeared from the EXPANDED sidebar entirely. Every element collapsed
  // this way must establish a block-ish box first.
  const collapsibles = [
    ['.sb-acts a', 'language switch'],
    ['.sb-i > span', 'nav label'],
    ['.sb-brand-t', 'brand text'],
    ['.sb-user-t', 'user text'],
  ];
  for (const [sel, name] of collapsibles) {
    const el = d.querySelector(sel);
    if (!el) { chk(name + ' exists', false, sel); continue; }
    const disp = cs(el).display;
    chk(name + ' is not inline (max-width would be ignored)',
        disp !== 'inline', sel + ' display=' + disp);
  }
  // ...and it must actually be visible when the sidebar is open.
  const langLink = d.querySelector('.sb-acts a');
  chk('language switch is visible when expanded',
      cs(langLink).opacity !== '0' && cs(langLink).maxWidth !== '0px' &&
      cs(langLink).visibility !== 'hidden' && cs(langLink).display !== 'none',
      'opacity=' + cs(langLink).opacity + ' maxWidth=' + cs(langLink).maxWidth);
  chk('language switch has its label', langLink.textContent.trim().length > 0);

  burger.dispatchEvent(new w.Event('click',{bubbles:true}));
  console.log('== collapsed ==');
  chk('rail class applied', d.body.classList.contains('rail'));
  chk('sidebar target width is 60px', cs(sb).width==='60px'||cs(sb).flexBasis==='60px', cs(sb).width+' / '+cs(sb).flexBasis);
  // The critical one: labels must collapse WITHOUT display:none.
  chk('labels NOT display:none (unanimatable)', cs(label).display!=='none', 'display='+cs(label).display);
  chk('labels collapsed to max-width:0', cs(label).maxWidth==='0px', cs(label).maxWidth);
  chk('labels faded out', cs(label).opacity==='0', cs(label).opacity);
  chk('labels not clickable when collapsed', cs(label).pointerEvents==='none');
  chk('section headers collapse by height', cs(sec).height==='0px'||cs(sec).height==='0', cs(sec).height);
  chk('section header not display:none', cs(sec).display!=='none', 'display='+cs(sec).display);
  chk('panel clips overflow-x', cs(sb).overflowX==='hidden', cs(sb).overflowX);
  const lang2 = d.querySelector('.sb-acts a');
  chk('language switch collapses in the rail', cs(lang2).maxWidth==='0px', cs(lang2).maxWidth);

  console.log('\n'+pass+' passed, '+fail+' failed');
  process.exit(fail?1:0);
});
