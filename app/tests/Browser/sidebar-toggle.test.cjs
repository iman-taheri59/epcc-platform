/**
 * Sidebar collapse / expand behaviour, in a real DOM.
 *
 * The Laravel feature tests can only assert that the markup and script are
 * present - they never execute JavaScript, so "the toggle works" is invisible
 * to them. This runs the page's own inline script in jsdom and drives it.
 *
 * Mirrors prototype/test/sidebar.test.js so the app and the prototype cannot
 * drift apart.
 *
 * Usage:
 *   1. capture a logged-in page to /tmp/sb.html (see REBUILD.md)
 *   2. npm install jsdom     # at /home/user, wiped by sandbox resets
 *   3. node tests/Browser/sidebar-toggle.test.cjs
 *
 * jsdom has no layout engine, so window.matchMedia is stubbed to emulate the
 * breakpoint; that is the ONLY thing faked - the script under test is the real
 * one, read back out of the rendered page.
 */
const {JSDOM} = require(process.env.JSDOM_PATH || '/home/user/node_modules/jsdom');
const fs = require('fs');
let pass=0, fail=0;
const chk=(n,c)=>{ c?(pass++,console.log('  OK   '+n)):(fail++,console.log('  FAIL '+n)); };

function boot(width){
  const fixture = process.env.SB_FIXTURE || '/tmp/sb.html';
  if (!fs.existsSync(fixture)) {
    console.error('fixture missing: ' + fixture + '\n' +
      'Capture a logged-in page first (see the header comment).');
    process.exit(2);
  }
  const html = fs.readFileSync(fixture,'utf8');

  // matchMedia must exist BEFORE the page's scripts run, so they execute in
  // natural document order exactly as a browser would.
  //
  // An earlier version of this test re-evaluated the sidebar script by hand
  // AFTER parsing had finished. That masked a real bug: the script is emitted
  // before the burger button in the document, so getElementById() returned
  // null and it silently bailed. Re-running it later found the button and
  // everything "passed" while the live page did nothing. Never hand-run a
  // script the page already ships - let the parser do it.
  const dom = new JSDOM(html, {
    runScripts: 'dangerously',
    url: 'https://sbx-x.arena.site/',
    pretendToBeVisual: true,
    beforeParse(w) {
      w.matchMedia = (q) => {
        const m = /max-width:\s*(\d+)px/.exec(q);
        const lim = m ? +m[1] : 860;
        return { matches: width <= lim, media: q,
          addEventListener(){}, removeEventListener(){},
          addListener(){}, removeListener(){} };
      };
      Object.defineProperty(w, 'innerWidth', {value: width, writable: true});
    },
  });

  // The page defers its wiring to DOMContentLoaded, so the test must wait for
  // it too - clicking synchronously after construction tests nothing.
  return new Promise((resolve) => {
    if (dom.window.document.readyState === 'complete') return resolve(dom);
    dom.window.addEventListener('load', () => resolve(dom));
  });
}

async function main(){
console.log('== desktop (1200px) ==');
let d = await boot(1200), w = d.window, b = w.document.body;
let burger = w.document.getElementById('sb-burger');
chk('starts expanded (no .rail)', !b.classList.contains('rail'));
chk('aria-expanded=true', burger.getAttribute('aria-expanded')==='true');
burger.dispatchEvent(new w.Event('click',{bubbles:true}));
chk('click collapses to rail', b.classList.contains('rail'));
chk('aria-expanded=false when collapsed', burger.getAttribute('aria-expanded')==='false');
chk('html mirrors the rail class', w.document.documentElement.classList.contains('rail'));
chk('preference persisted', w.localStorage.getItem('epcc.sb.rail')==='1');
burger.dispatchEvent(new w.Event('click',{bubbles:true}));
chk('click again expands', !b.classList.contains('rail'));
chk('no drawer class ever set on desktop', !b.classList.contains('drawer'));
chk('preference cleared', w.localStorage.getItem('epcc.sb.rail')==='0');

console.log('== mobile (600px) ==');
d = await boot(600); w = d.window; b = w.document.body;
burger = w.document.getElementById('sb-burger');
chk('starts closed (no .drawer)', !b.classList.contains('drawer'));
burger.dispatchEvent(new w.Event('click',{bubbles:true}));
chk('click opens drawer', b.classList.contains('drawer'));
chk('burger becomes a close glyph', burger.textContent.trim()==='\u2715');
chk('no rail class on mobile', !b.classList.contains('rail'));
w.document.getElementById('scrim').dispatchEvent(new w.Event('click',{bubbles:true}));
chk('scrim click closes drawer', !b.classList.contains('drawer'));
burger.dispatchEvent(new w.Event('click',{bubbles:true}));
chk('reopened', b.classList.contains('drawer'));
const link = w.document.querySelector('.sb-nav a');
link.dispatchEvent(new w.Event('click',{bubbles:true}));
chk('nav click closes drawer', !b.classList.contains('drawer'));
burger.dispatchEvent(new w.Event('click',{bubbles:true}));
const esc = new w.KeyboardEvent('keydown',{key:'Escape',bubbles:true});
w.document.dispatchEvent(esc);
chk('Escape closes drawer', !b.classList.contains('drawer'));

console.log('\n'+pass+' passed, '+fail+' failed');
process.exit(fail?1:0);
}
main();
