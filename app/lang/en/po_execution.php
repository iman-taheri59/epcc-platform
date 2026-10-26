<?php

return [
    'workspace' => 'PO financial / issue workspace', 'back' => 'Back to Procurement', 'scope' => 'Buyer-scoped commercial review only. Drafter, financial approver and issuer must be three different people. Financial approval keeps DRAFT; only issuance creates issued-order coverage.',
    'limits' => 'Eligible manual or full-copy MR-linked drafts require complete pricing. Linked orders also require current controlled-pricing evidence, the same approved source version and full-copy issuance allocation. Drafts and financial approval reserve no source quantities; ambiguous legacy commitments require reconciliation.',
    'number' => 'Order', 'buyer' => 'Buyer', 'vendor' => 'Vendor', 'category' => 'Category', 'value' => 'Total / currency', 'state' => 'State', 'view' => 'Review order', 'empty' => 'No visible human-attributed orders in this scope.',
    'lines' => 'Commercial lines (superseded rows are retained)', 'code' => 'Code', 'description' => 'Description', 'quantity' => 'Quantity', 'unit' => 'Unit', 'price' => 'Unit price', 'lineage' => 'Replaces line ID',
    'hash' => 'Commercial version fingerprint (SHA-256)', 'reason' => 'Reason / decision note', 'reason_required' => 'A non-blank decision reason is required.',
    'finance' => 'Approve this financial version', 'issue' => 'Issue this approved version', 'saved_finance' => 'Financial approval recorded for this version. The PO remains DRAFT.', 'saved_issue' => 'PO issued with separate drafter, financial approver and issuer.',
    'refused' => 'Action refused. Review the current content, pricing, authority and approval:',
    'history' => 'Decision history (latest 50; all earlier records retained)', 'snapshot' => 'Retained exact commercial snapshot', 'none' => 'No decision recorded.', 'approval' => 'Latest financial decision',
    'pending' => 'No action button means current authority, separation, source/pricing eligibility or the current financial approval is missing. The server rechecks all conditions on submission.',
];
