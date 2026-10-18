/** Behaviour test for the standalone Slice 6 Order Gap Board prototype. */
const { JSDOM } = require('jsdom');
const fs = require('fs');
const path = require('path');

const html = fs.readFileSync(path.join(__dirname, '..', 'order-gap-board.html'), 'utf8');

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
  chk('renders all five gap rows', d.querySelectorAll('.row').length === 5);
  chk('two shortage rows are visible', d.querySelectorAll('.row.shortage').length === 2);
  chk('one over-plan row is visible', d.querySelectorAll('.row.overplan').length === 1);
  chk('unmatched row carries the warn status', d.querySelector('.row.unmatched .status.warn') !== null);
  chk('draft PO is explicitly excluded by a note', /\.note-line\.muted/.test(css) && d.querySelector('.row.shortage .note-line').textContent.includes('پیش‌نویس'));
  chk('unmatched row says identity is never guessed', d.querySelector('.row.unmatched .note-line').textContent.includes('حدس زده شده'));
  chk('no external assets or network references', !/https?:\/\/|\/\/cdn|fonts\.googleapis/i.test(html));
}

{
  const dom = boot(390);
  const d = dom.window.document;

  d.querySelector('[data-filter="overplan"]').click();
  const overRows = d.querySelectorAll('.row');
  chk('over-plan filter isolates a single row', overRows.length === 1 && overRows[0].classList.contains('overplan'));

  d.querySelector('[data-filter="shortage"]').click();
  chk('shortage filter isolates the two shortages', d.querySelectorAll('.row.shortage').length === 2 && d.querySelectorAll('.row').length === 2);

  d.querySelector('[data-filter="unmatched"]').click();
  chk('unmatched filter isolates the unmatched bucket', d.querySelectorAll('.row.unmatched').length === 1 && d.querySelectorAll('.row').length === 1);

  d.querySelector('[data-filter="all"]').click();
  chk('all filter restores every row', d.querySelectorAll('.row').length === 5);
}

{
  const dom = boot(390);
  const d = dom.window.document;
  d.getElementById('langBtn').click();

  chk('language switch changes to English LTR', d.documentElement.lang === 'en' && d.documentElement.dir === 'ltr');
  chk('English title is visible', d.querySelector('h1').textContent === 'Order gap board');
  chk('English filter label is visible', d.querySelector('[data-filter="shortage"]').textContent === 'Shortages only');
  chk('English legend keeps the over-plan rule', d.querySelector('[data-i="legendOver"]').textContent === 'Over plan: never a negative shortage');
  chk('English over-plan gap shows the plus sign', d.querySelector('.row.overplan .meta:nth-child(3) b').textContent === '+12');
}

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
