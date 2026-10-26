<?php

return [
    'scope' => 'Full-copy approved MR source. Drafts and financial approvals do not reserve quantity; allocation is committed only with issuance. No MR state/signature changes, partial purchasing or automatic cancellation release.',
    'title' => 'Issued MR quantity allocations', 'source_hash' => 'Source version fingerprint (opaque SHA-256)', 'po_line' => 'Actual PO line UUID', 'mr_line' => 'Actual MR line UUID', 'approved' => 'Approved source quantity at issuance',
    'PO_EXEC_MR_SOURCE' => 'The PO must still be a complete exact copy of its current approved same-buyer source. Reconcile source/line changes first.',
    'PO_EXEC_MR_PRICING_REQUIRED' => 'A current complete controlled-pricing revision for this exact PO version is required.',
    'PO_EXEC_MR_LEGACY_RECONCILIATION_REQUIRED' => 'Unverified historical source commitments exist. Issuance is blocked until legacy commitments are reconciled; zero prior consumption is not assumed.',
    'PO_EXEC_MR_QUANTITY_EXCEEDED' => 'Issuance would exceed the approved source quantity. Another issued order already commits capacity; financial approvals do not reserve it.',
];
