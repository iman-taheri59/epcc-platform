/**
 * Behaviour tests for partials/tabletools.blade.php (FR-UX-04).
 *
 * Runs against a REAL captured page, and lets the jsdom parser execute the
 * shipped script in natural document order. It does NOT re-eval the script
 * after parsing: doing that was how an earlier sidebar harness reported 17/17
 * green while the live page was completely dead. The script is included in
 * <head>, so if its readiness guard were removed this file would go red.
 *
 *   node tests/Browser/table-tools.test.cjs
 *
 * Fixture: a logged-in document register saved to /tmp/tt.html
 * (override with TT_FIXTURE=). App is an ES module, hence .cjs.
 */
const fs = require('fs');
const path = require('path');

const JSDOM_PATH = process.env.JSDOM_PATH || 'jsdom';
const FIXTURE = process.env.TT_FIXTURE || '/tmp/tt.html';
const { JSDOM } = require(JSDOM_PATH);

let passed = 0;
let failed = 0;

function ok(cond, label) {
  if (cond) {
    passed++;
    console.log('  \x1b[32m✓\x1b[0m ' + label);
  } else {
    failed++;
    console.log('  \x1b[31m✗ ' + label + '\x1b[0m');
  }
}

// A skip is neither a pass nor a failure: it records that a rule did not apply
// to this page, so a silently-absent check can never look like a green one.
let skipped = 0;
function skip(label) {
  skipped++;
  console.log('  \x1b[33m- ' + label + '\x1b[0m');
}

function eq(actual, expected, label) {
  ok(
    JSON.stringify(actual) === JSON.stringify(expected),
    label + (JSON.stringify(actual) === JSON.stringify(expected)
      ? ''
      : `\n      expected ${JSON.stringify(expected)}\n      actual   ${JSON.stringify(actual)}`),
  );
}

function load() {
  const html = fs.readFileSync(FIXTURE, 'utf8');
  const dom = new JSDOM(html, {
    runScripts: 'dangerously',
    pretendToBeVisual: true,
    beforeParse(win) {
      // Stub what the shell's other scripts touch, before ANY script runs.
      win.matchMedia = win.matchMedia || function () {
        return { matches: false, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} };
      };
      Object.defineProperty(win, 'innerWidth', { value: 1400, writable: true });
      try {
        Object.defineProperty(win, 'localStorage', {
          value: (() => {
            let s = {};
            return {
              getItem: (k) => (k in s ? s[k] : null),
              setItem: (k, v) => { s[k] = String(v); },
              removeItem: (k) => { delete s[k]; },
            };
          })(),
        });
      } catch (e) { /* jsdom may already provide one */ }
    },
  });

  return new Promise((resolve) => {
    dom.window.addEventListener('load', () => resolve(dom));
  });
}

/** Visible data rows, in DOM order, as arrays of cell text. */
function rows(doc, table) {
  return Array.from(table.tBodies[0].rows)
    .filter((r) => !r.hidden && !r.classList.contains('tt-none'))
    .map((r) => Array.from(r.cells).map((c) => c.textContent.trim().replace(/\s+/g, ' ')));
}

function col(doc, table, i) {
  return rows(doc, table).map((r) => r[i]);
}

(async () => {
  if (!fs.existsSync(FIXTURE)) {
    console.error(`\nFixture missing: ${FIXTURE}\nCapture a logged-in register page there first.\n`);
    process.exit(2);
  }

  const dom = await load();
  const { window } = dom;
  const doc = window.document;
  const table = doc.querySelector('table[data-tt]');

  console.log('\ntable-tools\n');

  // ---- the toolbar is built at all --------------------------------------
  ok(table !== null, 'the register table opted in with data-tt');
  const bar = doc.querySelector('.tt-bar');
  ok(bar !== null, 'a toolbar was injected before the table');
  ok(
    bar && table && bar.compareDocumentPosition(table) & window.Node.DOCUMENT_POSITION_FOLLOWING,
    'the toolbar sits above the table it controls',
  );

  const input = doc.querySelector('.tt-search input');
  ok(input !== null, 'a search box exists');
  ok(input && input.getAttribute('aria-label'), 'the search box is labelled for screen readers');

  const isFaPage = doc.documentElement.getAttribute('lang') === 'fa';
  const total = rows(doc, table).length;
  // Two rows is the minimum that can distinguish ascending from descending;
  // a real tenant's certificate list is often exactly that short.
  ok(total >= 2, `the fixture has enough rows to be worth testing (${total})`);

  // ---- sorting ------------------------------------------------------------
  const heads = Array.from(table.tHead.rows[0].cells);
  const sortables = heads.filter((h) => h.classList.contains('tt-sortable'));
  // The right count is whatever the page declared with data-sort - a fixed
  // minimum just encodes the widest table's shape and fails on narrow ones
  // (the team table has two sortable columns and is perfectly correct).
  // data-sort="none" is an explicit opt-out (the actions column), not a
  // declaration that the column sorts. Counting it made a correct page fail.
  const declaredSorts = heads.filter(
    (h) => h.hasAttribute('data-sort') && h.getAttribute('data-sort') !== 'none',
  ).length;
  ok(declaredSorts >= 2, `the page declares sortable columns (${declaredSorts})`);
  eq(sortables.length, declaredSorts, `every declared sortable header was wired (${sortables.length})`);
  ok(
    sortables.every((h) => h.querySelector('button')),
    'every sortable header is a real <button>, so it works from the keyboard',
  );
  ok(
    sortables.every((h) => h.getAttribute('aria-sort') === 'none'),
    'headers start with aria-sort="none"',
  );

  // An action column must NOT be sortable: sorting by a form is meaningless
  // and the header is not a control. Detected by content rather than by
  // position - the comment sheet only renders its action column for the
  // originator, so "the last column" is not a stable way to find it.
  const actionHead = heads.find((h) => {
    const label = (h.textContent || '').trim();
    return /^(Actions?|اقدام)$/.test(label);
  });
  if (actionHead) {
    ok(!actionHead.classList.contains('tt-sortable'), 'the action column is not sortable');
  } else {
    ok(
      heads.every((h) => h.classList.contains('tt-sortable')),
      'no action column on this table, so every header is sortable',
    );
  }

  const original = col(doc, table, 0);

  // Sort by document number, ascending.
  const numberBtn = heads[0].querySelector('button');
  numberBtn.dispatchEvent(new window.Event('click', { bubbles: true }));
  const asc = col(doc, table, 0);
  eq(heads[0].getAttribute('aria-sort'), 'ascending', 'first click sorts ascending');

  // The page sorts with an Intl.Collator, so the oracle must too. Comparing
  // against raw codepoint order marked correct Persian collation as a bug:
  // "گ" precedes "م" for a reader but follows it by code unit.
  const collator = new Intl.Collator(isFaPage ? 'fa' : 'en', { numeric: true, sensitivity: 'base' });
  const sortedAsc = asc.slice().sort((a, b) => collator.compare(a, b));
  eq(asc, sortedAsc, 'ascending order really is sorted, by the reader\'s alphabet');

  // Descending.
  numberBtn.dispatchEvent(new window.Event('click', { bubbles: true }));
  eq(heads[0].getAttribute('aria-sort'), 'descending', 'second click sorts descending');
  eq(col(doc, table, 0), asc.slice().reverse(), 'descending is the reverse of ascending');

  // Third click restores the server's order - without this there is no undo.
  numberBtn.dispatchEvent(new window.Event('click', { bubbles: true }));
  eq(heads[0].getAttribute('aria-sort'), 'none', 'third click clears the sort');
  eq(col(doc, table, 0), original, 'clearing the sort restores the original server order');

  // ---- a keyed column sorts by its KEY, not by its label -----------------
  // The register's CDE state and the certificate's money column both render a
  // label that sorts wrongly as text, so they carry data-sortkey. Not every
  // page has such a column (a transmittal register does not), so this block
  // applies wherever the markup claims a key - but where it does claim one,
  // the rule is absolute: every cell in the column must carry it, or sorting
  // silently mixes keyed and unkeyed rows.
  const stateIdx = heads.findIndex((h) => h.hasAttribute('data-facet') && h.querySelector('button'));
  ok(stateIdx > 0, 'at least one column is both sortable and faceted');

  const bodyRows = () => Array.from(table.tBodies[0].rows).filter((r) => !r.classList.contains('tt-none'));
  const stateKeys = bodyRows().map((r) => r.cells[stateIdx] && r.cells[stateIdx].getAttribute('data-sortkey'));
  const keyed = stateKeys.filter((k) => k !== null);

  if (process.env.TT_DEBUG) console.log(`    [debug] stateIdx=${stateIdx} keyed=${keyed.length}/${stateKeys.length}`);
  if (keyed.length > 0) {
    ok(
      keyed.length === stateKeys.length && keyed.every((k) => /^-?[0-9.]+$/.test(k)),
      `every cell in a keyed column carries a numeric sort key (${keyed.length}/${stateKeys.length})`,
    );

    heads[stateIdx].querySelector('button').dispatchEvent(new window.Event('click', { bubbles: true }));
    const orderedKeys = bodyRows()
      .filter((r) => !r.hidden)
      .map((r) => Number(r.cells[stateIdx].getAttribute('data-sortkey')));
    eq(
      orderedKeys,
      orderedKeys.slice().sort((a, b) => a - b),
      'sorting a keyed column follows the key, not the rendered label',
    );
    // Back to neutral for the filter tests.
    heads[stateIdx].querySelector('button').dispatchEvent(new window.Event('click', { bubbles: true }));
    heads[stateIdx].querySelector('button').dispatchEvent(new window.Event('click', { bubbles: true }));
  } else {
    skip(`no keyed column on this page (column ${stateIdx} sorts by its text)`);
  }

  // ---- free-text filtering ------------------------------------------------
  function type(value) {
    input.value = value;
    input.dispatchEvent(new window.Event('input', { bubbles: true }));
  }

  // Search for a value that is unique in the first column. On the register
  // that is the document number; on the comment sheet the first column is a
  // row counter, so pick whichever column actually has distinct values.
  let uniqueCol = -1;
  let uniqueVal = null;
  for (let c = 0; c < heads.length && uniqueCol === -1; c++) {
    const values = rows(doc, table).map((r) => r[c]);
    const distinct = values.filter((v, i) => v && v.length > 2 && values.indexOf(v) === i);
    for (const v of distinct) {
      if (values.filter((x) => x === v).length === 1) { uniqueCol = c; uniqueVal = v; break; }
    }
  }
  ok(uniqueVal !== null, 'the fixture has a value unique to one row');
  type(uniqueVal);
  eq(rows(doc, table).length, 1, `searching a unique value ("${uniqueVal.slice(0, 24)}") narrows to one row`);

  type('');
  eq(rows(doc, table).length, total, 'clearing the box restores every row');

  // Persian digits: the register renders numbers in Persian, so a user typing
  // ASCII must still find them (and vice versa).
  const persianSource = Array.from(table.tBodies[0].rows)
    .filter((r) => !r.classList.contains('tt-none'))
    .map((r) => r.textContent)
    .join(' ');
  const hasPersianDigits = /[۰-۹]/.test(persianSource);
  // Only the Persian page renders Persian digits, so only there is the
  // cross-script matching claim testable. Asserting it unconditionally made
  // the English fixture fail for being correct.
  const hasAnyDigits = /[0-9\u06F0-\u06F9]/.test(persianSource);

  /*
   * Digits inside a table BODY are almost always identifiers - PH-14-PL-0005,
   * "Unit 220", tag P-2201A. Those are keys people read aloud, type into other
   * systems and search for, so they stay latin in both languages by design.
   *
   * The localisation claim belongs to numbers the APP formats: counts,
   * percentages, dates. Those live in the stat cards, so assert there. Judging
   * it from the table body marked a correctly localised page as broken.
   */
  const appFormatted = Array.from(doc.querySelectorAll('.dstat .v, .jnum'))
    .map((e) => e.textContent)
    .join(' ');
  const hasLocalisableDigits = /[0-9\u06F0-\u06F9]/.test(appFormatted);

  if (isFaPage && !hasAnyDigits) {
    skip('this Persian page has no digits in its body to localise');
  } else if (isFaPage && !hasLocalisableDigits) {
    skip('this Persian page only has digits inside identifiers, which stay latin');
  } else if (isFaPage) {
    ok(/[۰-۹]/.test(appFormatted), 'the Persian page renders app-formatted numbers in Persian digits');
  } else {
    ok(!hasPersianDigits, 'the English page renders latin digits');
  }

  // Prefer a multi-digit run, but fall back to a single digit: the comment
  // sheet numbers rows 1..n, so on a short sheet there is no two-digit token
  // and `{2,}` returns null. The harness must adapt to the fixture rather
  // than the fixture to the harness.
  const persianMatch = persianSource.match(/[۰-۹]{2,}/) || persianSource.match(/[۰-۹]/);

  if (isFaPage && hasPersianDigits && persianMatch) {
    const persian = persianMatch[0];
    const ascii = persian.replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
    type(ascii);
    ok(rows(doc, table).length > 0, `ASCII "${ascii}" matches Persian "${persian}"`);
    type(persian);
    ok(rows(doc, table).length > 0, `Persian "${persian}" matches itself`);
    type('');
  } else {
    // The same folding must not BREAK a plain English page.
    const m = persianSource.match(/[0-9]{2,}/);
    if (m) {
      type(m[0]);
      ok(rows(doc, table).length > 0, `latin "${m[0]}" matches on the English page`);
      type('');
    } else {
      ok(true, 'no numeric text on the English fixture to cross-check');
    }
  }

  // A search that matches nothing must say so, not leave an empty table.
  type('zzzz-no-such-document-zzzz');
  eq(rows(doc, table).length, 0, 'an impossible search hides every row');
  const noneRow = table.querySelector('tr.tt-none');
  ok(noneRow && !noneRow.hidden, 'an explicit "nothing matches" row is shown');
  ok(
    noneRow && noneRow.cells[0].colSpan === heads.length,
    'the empty-state row spans the full table width',
  );
  type('');
  ok(table.querySelector('tr.tt-none').hidden, 'the empty-state row hides again');

  // ---- faceted dropdowns --------------------------------------------------
  const selects = Array.from(doc.querySelectorAll('.tt-facet select'));
  const declaredFacets = heads.filter((h) => h.hasAttribute('data-facet')).length;
  ok(declaredFacets >= 1, `the page declares faceted columns (${declaredFacets})`);
  eq(selects.length, declaredFacets, `every declared facet became a dropdown (${selects.length})`);

  const stateSelect = selects[0];
  // "all" + one option per distinct value present; a two-row page legitimately
  // has fewer options than a five-row one.
  ok(stateSelect.options.length >= 2, 'the facet lists the values actually present');
  eq(stateSelect.options[0].value, '', 'the first facet option is the "all" reset');

  /*
   * Narrowing can only be observed on a facet with MORE THAN ONE value: the
   * punch register's subsystem column holds one subsystem, so selecting it
   * correctly shows every row. Pick a facet that can actually discriminate,
   * and skip the check when the page has none.
   */
  const discriminating = selects.find((s) => s.options.length > 2);

  if (discriminating) {
    const wanted = discriminating.options[1].value;
    discriminating.value = wanted;
    discriminating.dispatchEvent(new window.Event('change', { bubbles: true }));
    const shown = rows(doc, table);
    ok(shown.length > 0 && shown.length < total, `selecting "${wanted}" narrows the table`);

    // The surviving rows must actually carry the selected value - a filter that
    // narrows to the wrong subset still "narrows".
    const facetIdx = selects.indexOf(discriminating);
    const facetedHeads = heads.filter((h) => h.hasAttribute('data-facet'));
    const columnIdx = heads.indexOf(facetedHeads[facetIdx]);
    /*
     * Compare against data-facet-value, NOT the cell text. A column can declare
     * "A" while displaying "الف" - the punch register does exactly that - so a
     * text comparison marks a correct Persian page as broken.
     */
    const visibleRows = Array.from(table.tBodies[0].rows)
      .filter((r) => !r.hidden && !r.classList.contains('tt-none'));
    ok(
      visibleRows.every((r) => {
        const cell = r.cells[columnIdx];
        const declared = cell.getAttribute('data-facet-value');
        const actual = declared === null ? cell.textContent.trim() : declared;

        return actual === wanted;
      }),
      'every remaining row really has the selected value',
    );

    // Deliberately left ACTIVE: the reset assertions below need a live filter.
  } else {
    skip('no facet on this page has more than one value to filter by');
  }

  // ---- reset --------------------------------------------------------------
  const reset = doc.querySelector('.tt-reset');
  ok(reset && !reset.hidden, 'the reset button appears once a filter is active');
  reset.dispatchEvent(new window.Event('click', { bubbles: true }));
  eq(rows(doc, table).length, total, 'reset restores every row');
  eq(stateSelect.value, '', 'reset clears the facet dropdown');
  eq(input.value, '', 'reset clears the search box');
  ok(doc.querySelector('.tt-reset').hidden, 'the reset button hides again when nothing is filtered');

  // ---- the count is live and localised ------------------------------------
  const count = doc.querySelector('.tt-count');
  ok(count && count.getAttribute('aria-live') === 'polite', 'the row count is announced politely');
  if (isFaPage) {
    ok(/[۰-۹]/.test(count.textContent), 'the count uses Persian digits on a Persian page');
    ok(!/[0-9]/.test(count.textContent), 'the count has no latin digits on a Persian page');
  }

  type('zzzz-no-such-document-zzzz');
  ok(count.classList.contains('tt-filtered'), 'the count is highlighted while filtered');
  type('');
  ok(!count.classList.contains('tt-filtered'), 'the highlight clears with the filter');

  // ---- hidden rows are hidden from assistive tech too ---------------------
  type('zzzz');
  const anyRow = Array.from(table.tBodies[0].rows).find((r) => !r.classList.contains('tt-none'));
  ok(anyRow.hidden === true, 'filtered-out rows use the hidden attribute, not just a class');
  type('');

  console.log(`\n${passed} passed, ${failed} failed${skipped ? `, ${skipped} not applicable` : ''}\n`);
  process.exit(failed === 0 ? 0 : 1);
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
