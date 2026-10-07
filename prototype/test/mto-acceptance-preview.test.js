/** Mobile-first smoke test for the standalone Slice 4 MTO preview. */
const { JSDOM } = require('jsdom');
const fs = require('fs');
const path = require('path');

const html = fs.readFileSync(path.join(__dirname, '..', 'mto-acceptance-preview.html'), 'utf8');

function boot(width = 390) {
  return new JSDOM(html, {
    runScripts: 'dangerously',
    pretendToBeVisual: true,
    beforeParse(window) {
      Object.defineProperty(window, 'innerWidth', { value: width, configurable: true });
    },
  });
}

let pass = 0;
let fail = 0;
const chk = (name, condition) => {
  condition ? pass++ : fail++;
  console.log(`${condition ? '  ok  ' : '  FAIL'} ${name}`);
};

{
  const dom = boot(390);
  const d = dom.window.document;
  const css = [...d.querySelectorAll('style')].map(style => style.textContent).join('\n');

  chk('boots Persian-first and RTL', d.documentElement.lang === 'fa' && d.documentElement.dir === 'rtl');
  chk('has mobile viewport metadata', /width=device-width/.test(d.querySelector('meta[name="viewport"]').content));
  chk('uses mobile card layout', /\.row-meta\{display:grid/.test(css) && /@media\(min-width:650px\)/.test(css));
  chk('has safe-area mobile action bar', css.includes('env(safe-area-inset-bottom)'));
  chk('acceptance is initially disabled by the blocked row', d.getElementById('acceptBtn').disabled);
  chk('blocked row is visible', d.querySelectorAll('.row.blocked').length === 1);
  chk('no external assets or network references', !/https?:\/\/|\/\/cdn|fonts\.googleapis/i.test(html));
}

{
  const dom = boot(390);
  const d = dom.window.document;
  d.getElementById('fixBtn').click();

  chk('sample issue can be fixed in the preview', !d.getElementById('acceptBtn').disabled);
  chk('fixed state announces readiness', d.getElementById('bannerTitle').textContent.includes('آماده'));

  d.getElementById('acceptBtn').click();
  chk('acceptance changes revision to accepted', d.getElementById('revisionStatus').textContent.includes('پذیرفته'));
  chk('accepted rows are marked frozen', d.querySelectorAll('.row.accepted .frozen').length === 2);
  chk('accepted action cannot be repeated', d.getElementById('acceptBtn').disabled);
}

{
  const dom = boot(390);
  const d = dom.window.document;
  d.getElementById('langBtn').click();

  chk('language switch changes to English LTR', d.documentElement.lang === 'en' && d.documentElement.dir === 'ltr');
  chk('English title is visible', d.querySelector('h1').textContent === 'Accept MTO revision');
  chk('English filter label is visible', d.querySelector('[data-filter="blocked"]').textContent === 'Blocked only');
}

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
