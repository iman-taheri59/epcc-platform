<?php

return [
    'title' => 'Controlled MR-linked PO pricing', 'scope' => 'Only the original drafter with current own-buyer draft authority may price all active lines. Quantities and source identities stay unchanged. This is not financial approval, source reservation or issuance; MR-linked execution requires separate current financial approval, issuer authority and full-copy source allocation at issuance.',
    'currency' => 'Currency', 'price' => 'Unit price', 'reason' => 'Pricing reason', 'save' => 'Save complete pricing', 'saved' => 'Complete draft pricing recorded; no MR quantity was reserved or consumed and no order was issued.',
    'history' => 'Pricing history (latest 20; earlier records retained)', 'before' => 'Retained before snapshot', 'after' => 'Retained after snapshot',
    'stale' => 'The PO content changed. Reload and review before pricing.', 'source' => 'The current approved MR and complete active PO copy must match. Reconcile source changes before pricing.',
    'lines' => 'Submit exactly all current active line IDs once; partial, foreign and historical line selections are refused.', 'overflow' => 'The exact total exceeds the supported monetary range.', 'no_change' => 'No commercial pricing change was submitted.', 'reason_required' => 'A non-blank pricing reason is required.', 'refused' => 'Pricing refused:',
    'comparison' => 'Recorded pricing comparison', 'comparison_note' => 'Before/after values from this retained revision, matched by actual line UUID. All stored lines are shown, including unchanged historical lines. This is not a new approval, current eligibility check, currency conversion or signature verification.',
    'before_value' => 'Before', 'after_value' => 'After', 'total' => 'Recorded total', 'line_identity' => 'Line / recorded unit prices', 'unknown_value' => 'Not recorded / unknown',
    'comparison_unavailable' => 'Structured comparison unavailable for this snapshot shape. Review the retained raw snapshots; no values have been inferred.',
    'input_not_restored' => 'Previous pricing input was not restored because its order version, line selection or structure no longer matches this form. Current stored values are shown. Review every price, currency and reason before submitting again.',
];
