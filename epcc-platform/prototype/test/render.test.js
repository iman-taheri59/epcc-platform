/**
 * Render smoke test for the prototype.
 *
 * Renders every page under every combination of standards pack, language and
 * company role, and fails on empty output or leaked `undefined` /
 * `[object Object]` / `NaN` in the HTML.
 *
 * Usage:  node prototype/test/render.test.js
 */
const fs = require('fs');
const path = require('path');

const HTML = path.join(__dirname, '..', 'index.html');
const src = fs.readFileSync(HTML, 'utf8').match(/<script>([\s\S]*)<\/script>/)[1];

// Minimal DOM stub - enough for the module-level wiring to execute.
const mkEl = () => ({
  classList: { toggle() {}, contains() { return false; }, remove() {}, add() {} },
  addEventListener() {}, options: [{}, {}, {}],
  querySelectorAll() { return []; }, querySelector() { return null; },
  getAttribute() { return null; }, setAttribute() {},
  textContent: '', innerHTML: '', title: '', dataset: {},
  parentElement: { classList: { contains() { return true; } } },
  parentNode: { insertBefore() {} }, appendChild() {},
});
global.window = { matchMedia: () => ({ matches: false, addEventListener() {} }), innerWidth: 1400 };
global.document = {
  documentElement: {},
  body: { classList: { toggle() {}, contains() { return false; }, remove() {}, add() {} } },
  getElementById: () => mkEl(), querySelectorAll: () => [],
  addEventListener() {}, createElement: () => mkEl(),
};

const code = src.replace(/render\(\);\s*$/, '') +
  '\nreturn {PAGES, set:(p,l,c)=>{PACK=p;L=l;CO=c;}};';
const { PAGES, set } = new Function(code)();

const PACKS = ['ips', 'iso', 'awp'];
const LANGS = ['fa', 'en'];
const COMPANIES = ['EPC', 'OWN', 'PMC', 'TPI', 'MC', 'SUB'];

let n = 0, fail = 0;
for (const p of PACKS) for (const l of LANGS) for (const c of COMPANIES) {
  for (const pg of Object.keys(PAGES)) {
    set(p, l, c);
    try {
      const h = PAGES[pg]();
      n++;
      if (!h || h.length < 50) { console.log(`  FAIL thin output  ${p}/${l}/${c}/${pg}`); fail++; }
      const bad = h.match(/.{0,50}(undefined|\[object Object\]|NaN).{0,50}/);
      if (bad) { console.log(`  FAIL leak  ${p}/${l}/${c}/${pg} | ${bad[0].replace(/\s+/g, ' ')}`); fail++; }
    } catch (e) {
      console.log(`  FAIL throw  ${p}/${l}/${c}/${pg}: ${e.message}`); fail++;
    }
  }
}

console.log(`\n${n} renders (${PACKS.length} packs x ${LANGS.length} languages x ${COMPANIES.length} roles x ${Object.keys(PAGES).length} pages), ${fail} problems`);
process.exit(fail ? 1 : 0);
