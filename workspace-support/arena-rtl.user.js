// ==UserScript==
// @name         Arena.ai — چیدمان راست‌به‌چپ
// @namespace    local
// @version      1.0
// @description  Forces right-to-left layout on Arena chat messages while keeping code, terminal output and tables left-to-right where they must be.
// @match        https://arena.ai/*
// @match        https://*.arena.ai/*
// @run-at       document-idle
// @grant        none
// ==/UserScript==

(function () {
  'use strict';

  const css = `
    /* --- the conversation itself -------------------------------------- */
    /* dir:auto lets the browser pick per paragraph from its first strong
       character, so a Persian answer reads right-to-left and an English one
       is left alone. That is better than forcing rtl everywhere, which
       mangles replies that are mostly English. */
    [class*="message"], [class*="Message"],
    [data-message], [role="article"],
    .prose, .markdown, [class*="markdown"] {
      direction: auto !important;
      unicode-bidi: plaintext !important;
    }

    /* --- the composer --------------------------------------------------- */
    textarea, [contenteditable="true"] {
      direction: auto !important;
      unicode-bidi: plaintext !important;
      text-align: start !important;
    }

    /* --- CODE MUST STAY LEFT-TO-RIGHT ----------------------------------- */
    /* A shell command or a JSON blob rendered rtl is unreadable and, worse,
       copies out in the wrong order. This rule is the reason the script is
       worth having rather than just setting dir=rtl on <body>. */
    pre, code, kbd, samp,
    pre *, code *,
    [class*="code"], [class*="Code"],
    [class*="highlight"], [class*="hljs"],
    [class*="terminal"], [class*="Terminal"] {
      direction: ltr !important;
      unicode-bidi: embed !important;
      text-align: left !important;
    }

    /* --- tables ---------------------------------------------------------- */
    /* Header and body must agree, or the columns transpose against each
       other and the table becomes actively misleading. */
    table { direction: inherit !important; }
    th, td { text-align: start !important; }
  `;

  const style = document.createElement('style');
  style.id = 'arena-rtl';
  style.textContent = css;
  document.head.appendChild(style);

  // Arena renders messages after load and on every reply, so the attribute
  // has to be reapplied rather than set once.
  const mark = () => {
    document
      .querySelectorAll('p, li, h1, h2, h3, h4, blockquote, td, th')
      .forEach((el) => {
        if (el.closest('pre, code')) return;
        if (!el.hasAttribute('dir')) el.setAttribute('dir', 'auto');
      });
  };

  mark();
  new MutationObserver(mark).observe(document.body, {
    childList: true,
    subtree: true,
  });
})();
