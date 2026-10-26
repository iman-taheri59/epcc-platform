<?php

return [
    'mr' => 'Requisition filters', 'po' => 'Purchase order filters',
    'search' => 'Search number / title', 'placeholder' => 'Number or English / Persian title',
    'all_states' => 'All recorded states', 'reset' => 'Reset search / state (keep category)',
    'scope' => 'MR and PO searches are independent literal substrings of visible document numbers or English/Persian titles; case-insensitive where supported by the database locale. Category also applies to both. MR/PO and late-order counts reflect these results from the server filters (not local table filters), not project-wide totals or remaining capacity. The inspection result set is not narrowed by MR/PO filters. Searching grants no new read or action authority.',
    'empty_mr' => 'No visible requisitions match these filters.', 'empty_po' => 'No visible purchase orders match these filters.',
    'page_summary' => 'Rows :first–:last of :total matching documents · Page :page of :pages',
    'first_page' => 'First page', 'previous_page' => 'Previous page', 'next_page' => 'Next page',
    'empty_page' => 'No rows on this page; matching documents exist. Use First page to return to the results.',
    'pagination_scope' => 'MR, PO and inspections each show up to 25 records per page. Summary counters cover all visible server-filtered matches; rows and local table tools cover only this page. Apply filters to restart all three registers at page 1. Money is the exact stored decimal amount in its recorded currency, without conversion or financial approval.',
    'mr_pages' => 'Requisition pages', 'po_pages' => 'Purchase order pages',
    'inspection_pages' => 'Inspection pages', 'inspection_scope' => 'Inspection totals cover all records visible in this project, independently of MR/PO filters. Inspection remains advisory; paging does not authorize recording a verdict or block goods receipt.',
];
