
<style>
  .tt-bar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0 0 10px; }
  .tt-search { position:relative; flex:1 1 240px; min-width:190px; max-width:420px; }
  .tt-search input {
    width:100%; padding:8px 32px 8px 12px; font:inherit; font-size:13px; border-radius:9px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line);
  }
  html[dir="rtl"] .tt-search input { padding:8px 12px 8px 32px; }
  .tt-search input:focus { outline:2px solid var(--accent); outline-offset:1px; border-color:transparent; }
  .tt-search .tt-clear {
    position:absolute; inset-inline-end:6px; top:50%; transform:translateY(-50%);
    background:none; border:0; color:var(--muted); cursor:pointer; font-size:16px;
    line-height:1; padding:2px 5px; border-radius:5px; font-family:inherit;
  }
  .tt-search .tt-clear:hover { color:#e2e8f0; background:rgba(148,163,184,.14); }
  .tt-facet select {
    padding:7px 10px; font:inherit; font-size:12.5px; border-radius:8px;
    background:#0b1220; color:#e2e8f0; border:1px solid var(--line); max-width:190px;
  }
  .tt-facet select:focus { outline:2px solid var(--accent); outline-offset:1px; }
  .tt-count { font-size:12px; color:var(--muted); font-variant-numeric:tabular-nums; margin-inline-start:auto; }
  .tt-count.tt-filtered { color:#fcd34d; }
  .tt-reset {
    background:transparent; border:1px solid var(--line); color:#cbd5e1; border-radius:8px;
    padding:6px 11px; font:inherit; font-size:12px; cursor:pointer;
  }
  .tt-reset:hover { background:rgba(148,163,184,.12); color:#fff; }

  /* Sortable headers. The whole cell is the hit target, and it is a real
     <button> so it is reachable and operable from the keyboard. */
  th.tt-sortable { padding:0; }
  th.tt-sortable > button {
    all:unset; box-sizing:border-box; display:flex; align-items:center; gap:6px; width:100%;
    padding:11px 14px; cursor:pointer; font:inherit; font-size:12px; text-transform:uppercase;
    letter-spacing:.05em; color:var(--muted); font-weight:600;
  }
  th.tt-sortable > button:hover { color:#e2e8f0; background:rgba(148,163,184,.07); }
  th.tt-sortable > button:focus-visible { outline:2px solid var(--accent); outline-offset:-2px; }
  .tt-ind { flex:none; width:9px; height:9px; opacity:.35; transition:opacity .12s ease; }
  th[aria-sort="ascending"] .tt-ind,
  th[aria-sort="descending"] .tt-ind { opacity:1; color:var(--accent); }
  th[aria-sort="ascending"] > button,
  th[aria-sort="descending"] > button { color:#e2e8f0; }
  .tt-none td { text-align:center; color:var(--muted); font-size:13px; padding:26px 14px; }
  /* A row hidden by the filter must be hidden from assistive tech too, which
     `hidden` does and a class alone does not. */
  tr[hidden] { display:none; }
  @media (prefers-reduced-motion: reduce) { .tt-ind { transition:none; } }
</style>
<script>
(function () {
  'use strict';

  var FA = <?php echo json_encode($lang ?? 'fa', 15, 512) ?> === 'fa';
  var T = {
    search:  FA ? 'جستجو در جدول…'   : 'Search this table…',
    clear:   FA ? 'پاک کردن'          : 'Clear',
    reset:   FA ? 'بازنشانی'          : 'Reset',
    all:     FA ? 'همه'               : 'All',
    none:    FA ? 'موردی یافت نشد.'   : 'Nothing matches that filter.',
    of:      FA ? 'از'                : 'of',
    rows:    FA ? 'ردیف'              : 'rows',
    sortBy:  FA ? 'مرتب‌سازی بر اساس' : 'Sort by'
  };

  /* Persian and Arabic-Indic digits -> ASCII.
     Without this, typing "14" would not find "۱۴-P-۱۲۰۱" and a numeric column
     rendered in Persian digits would sort as text. Both are everyday cases in
     this product, not edge cases. */
  var DIGITS = { '۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9',
                 '٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9' };

  function fold(s) {
    if (!s) return '';
    var out = '';
    for (var i = 0; i < s.length; i++) {
      var ch = s[i];
      if (DIGITS[ch]) { out += DIGITS[ch]; continue; }
      /* Arabic yeh/kaf vs Persian yeh/kaf, and the zero-width non-joiner.
         A user typing on an Arabic keyboard - or pasting from an older Windows
         document - must still match text stored with Persian characters. */
      if (ch === 'ي' || ch === 'ی' || ch === 'ئ') { out += 'ی'; continue; }
      if (ch === 'ك') { out += 'ک'; continue; }
      if (ch === 'ة') { out += 'ه'; continue; }
      if (ch === 'أ' || ch === 'إ' || ch === 'آ' || ch === 'ا') { out += 'ا'; continue; }
      if (ch === '\u200c' || ch === '\u200f' || ch === '\u200e') { out += ' '; continue; }
      /* Arabic/Persian diacritics carry no search value. */
      if (ch >= '\u064B' && ch <= '\u0652') { continue; }
      out += ch;
    }
    return out.toLowerCase().replace(/\s+/g, ' ').trim();
  }

  function numify(s) {
    var t = fold(s).replace(/[^0-9.\-]/g, '');
    if (t === '' || t === '-' || t === '.') return null;
    var n = parseFloat(t);
    return isNaN(n) ? null : n;
  }

  function cellText(row, i) {
    var td = row.cells[i];
    if (!td) return '';
    /* An explicit sort key wins: the displayed text is for humans, this is for
       ordering. */
    if (td.hasAttribute('data-sortkey')) return td.getAttribute('data-sortkey');
    return (td.textContent || '').trim();
  }

  /* What a faceted column groups by. An explicit `data-facet-value` wins over
     the cell's text, which may contain far more than the headline. */
  function facetValue(row, i) {
    var td = row.cells[i];
    if (!td) return '';
    if (td.hasAttribute('data-facet-value')) return td.getAttribute('data-facet-value');
    return (td.textContent || '').trim().replace(/\s+/g, ' ');
  }

  var collator = null;
  try { collator = new Intl.Collator(FA ? 'fa' : 'en', { numeric: true, sensitivity: 'base' }); } catch (e) {}

  function enhance(table) {
    var thead = table.tHead, tbody = table.tBodies[0];
    if (!thead || !tbody) return;

    var headers = Array.prototype.slice.call(thead.rows[0].cells);
    /* Rows spanning the table (an "empty" message) are structural, never data. */
    var dataRows = Array.prototype.slice.call(tbody.rows).filter(function (r) {
      return !r.querySelector('td[colspan]');
    });
    if (dataRows.length === 0) return;

    /* Remember the server's order so a third click can restore it. The server
       ordered these deliberately (discipline, then sequence); "no sort" has to
       mean that, not whatever the last sort left behind. */
    dataRows.forEach(function (r, i) { r.setAttribute('data-tt-i', String(i)); });

    var wrap = document.createElement('div');
    var bar = document.createElement('div');
    bar.className = 'tt-bar';
    table.parentNode.insertBefore(bar, table);

    /* ---- free-text search ---- */
    var sBox = document.createElement('div');
    sBox.className = 'tt-search';
    var input = document.createElement('input');
    input.type = 'search';
    input.placeholder = T.search;
    input.setAttribute('aria-label', T.search);
    var clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'tt-clear';
    clear.innerHTML = '&times;';
    clear.title = T.clear;
    clear.setAttribute('aria-label', T.clear);
    clear.hidden = true;
    sBox.appendChild(input);
    sBox.appendChild(clear);
    bar.appendChild(sBox);

    /* ---- one dropdown per faceted column ---- */
    var facets = [];
    headers.forEach(function (th, i) {
      if (!th.hasAttribute('data-facet')) return;
      var seen = [], sel = document.createElement('select');
      var label = (th.textContent || '').trim();
      sel.setAttribute('aria-label', label);
      var opt0 = document.createElement('option');
      opt0.value = '';
      opt0.textContent = label + ': ' + T.all;
      sel.appendChild(opt0);
      dataRows.forEach(function (r) {
        var v = facetValue(r, i);
        if (v && seen.indexOf(v) === -1) seen.push(v);
      });
      seen.sort(function (a, b) { return collator ? collator.compare(a, b) : (a < b ? -1 : 1); });
      seen.forEach(function (v) {
        var o = document.createElement('option');
        o.value = v; o.textContent = v;
        sel.appendChild(o);
      });
      var box = document.createElement('div');
      box.className = 'tt-facet';
      box.appendChild(sel);
      bar.appendChild(box);
      facets.push({ index: i, select: sel });
    });

    var reset = document.createElement('button');
    reset.type = 'button';
    reset.className = 'tt-reset';
    reset.textContent = T.reset;
    reset.hidden = true;
    bar.appendChild(reset);

    var count = document.createElement('span');
    count.className = 'tt-count';
    count.setAttribute('aria-live', 'polite');
    bar.appendChild(count);

    /* Empty-state row, created once and reused. */
    var noneRow = document.createElement('tr');
    noneRow.className = 'tt-none';
    var noneCell = document.createElement('td');
    noneCell.colSpan = headers.length;
    noneCell.textContent = T.none;
    noneRow.appendChild(noneCell);
    noneRow.hidden = true;
    tbody.appendChild(noneRow);

    /* Cache the folded text of each row once. Re-folding on every keystroke is
       what makes this kind of filter feel sluggish. */
    var haystack = dataRows.map(function (r) { return fold(r.textContent || ''); });

    function localeNum(n) {
      var s = String(n);
      if (!FA) return s;
      return s.replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; });
    }

    function apply() {
      var q = fold(input.value);
      var active = q !== '';
      var shown = 0;

      dataRows.forEach(function (row, i) {
        var ok = q === '' || haystack[i].indexOf(q) !== -1;
        if (ok) {
          for (var f = 0; f < facets.length; f++) {
            var want = facets[f].select.value;
            if (!want) continue;
            active = true;
            if (facetValue(row, facets[f].index) !== want) { ok = false; break; }
          }
        }
        row.hidden = !ok;
        if (ok) shown++;
      });

      noneRow.hidden = shown !== 0;
      clear.hidden = input.value === '';
      reset.hidden = !active;
      count.classList.toggle('tt-filtered', active && shown !== dataRows.length);
      count.textContent = active
        ? localeNum(shown) + ' ' + T.of + ' ' + localeNum(dataRows.length) + ' ' + T.rows
        : localeNum(dataRows.length) + ' ' + T.rows;
    }

    /* ---- sorting ---- */
    var sortCol = -1, sortDir = 0; /* 0 none, 1 asc, -1 desc */

    function sortBy(index, mode) {
      var rows = dataRows.slice();

      if (sortDir === 0) {
        rows.sort(function (a, b) {
          return (+a.getAttribute('data-tt-i')) - (+b.getAttribute('data-tt-i'));
        });
      } else {
        rows.sort(function (a, b) {
          var x = cellText(a, index), y = cellText(b, index), r;
          if (mode === 'num') {
            var nx = numify(x), ny = numify(y);
            /* Empty cells sort last in BOTH directions. A blank is missing
               data, not the smallest value; flipping it to the top on a
               descending sort just buries the rows the user asked to see. */
            if (nx === null && ny === null) r = 0;
            else if (nx === null) return 1;
            else if (ny === null) return -1;
            else r = nx - ny;
          } else {
            r = collator ? collator.compare(x, y) : (x === y ? 0 : (x < y ? -1 : 1));
          }
          /* Stable tie-break on the server's original order, so equal values
             never shuffle between clicks. */
          if (r === 0) r = (+a.getAttribute('data-tt-i')) - (+b.getAttribute('data-tt-i'));
          return r * sortDir;
        });
      }

      rows.forEach(function (r) { tbody.appendChild(r); });
      tbody.appendChild(noneRow);

      headers.forEach(function (th, i) {
        if (!th.classList.contains('tt-sortable')) return;
        th.setAttribute('aria-sort',
          i === index && sortDir === 1 ? 'ascending'
          : i === index && sortDir === -1 ? 'descending'
          : 'none');
      });
    }

    headers.forEach(function (th, i) {
      var mode = th.getAttribute('data-sort');
      if (!mode || mode === 'none') return;
      th.classList.add('tt-sortable');
      th.setAttribute('aria-sort', 'none');

      var label = (th.textContent || '').trim();
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.setAttribute('aria-label', T.sortBy + ' ' + label);
      btn.appendChild(document.createTextNode(label));

      var ind = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      ind.setAttribute('class', 'tt-ind');
      ind.setAttribute('viewBox', '0 0 10 10');
      ind.setAttribute('aria-hidden', 'true');
      var up = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      up.setAttribute('fill', 'currentColor');
      up.setAttribute('d', 'M5 0 L9 4 H1 Z');
      var dn = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      dn.setAttribute('fill', 'currentColor');
      dn.setAttribute('d', 'M5 10 L1 6 H9 Z');
      ind.appendChild(up);
      ind.appendChild(dn);
      btn.appendChild(ind);

      th.textContent = '';
      th.appendChild(btn);

      btn.addEventListener('click', function () {
        if (sortCol === i) {
          /* asc -> desc -> back to the server's order. The third state matters:
             without it there is no way to undo a sort. */
          sortDir = sortDir === 1 ? -1 : (sortDir === -1 ? 0 : 1);
        } else {
          sortCol = i; sortDir = 1;
        }
        if (sortDir === 0) sortCol = -1;
        sortBy(i, mode);
      });
    });

    input.addEventListener('input', apply);
    input.addEventListener('search', apply);
    clear.addEventListener('click', function () { input.value = ''; input.focus(); apply(); });
    facets.forEach(function (f) { f.select.addEventListener('change', apply); });
    reset.addEventListener('click', function () {
      input.value = '';
      facets.forEach(function (f) { f.select.value = ''; });
      if (sortDir !== 0) { sortDir = 0; sortCol = -1; sortBy(0, 'text'); }
      apply();
      input.focus();
    });

    apply();
  }

  function init() {
    var tables = document.querySelectorAll('table[data-tt]');
    for (var i = 0; i < tables.length; i++) enhance(tables[i]);
  }

  /* The same readiness guard the sidebar needs: this partial is included in
     <head>, long before the tables it enhances have been parsed. Running
     immediately would find nothing and fail silently. */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
</script>
<?php /**PATH /home/user/epcc-platform/app/resources/views/partials/tabletools.blade.php ENDPATH**/ ?>