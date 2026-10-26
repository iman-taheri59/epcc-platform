<?php

declare(strict_types=1);

return [
    'title' => 'Custody source / heat amendments',
    'boundary' => 'Only the actual original MRR preparer with a live custody_mrr nomination can append an amendment. Arrival facts, quantity and unit are never edited. No stock or GRN is created.',
    'refresh' => 'Each amendment supersedes the working source/heat basis, not the arrival record. Fresh receiving QC and QA are required on that exact version; old acceptance never transfers automatically.',
    'current' => 'Current working basis (arrival remains unchanged)',
    'original' => 'Original arrival / no amendment',
    'bound' => 'Bound source/heat amendment',
    'hidden' => 'The current corrected source is not independently readable. No fallback to an older source and no new review or correction is allowed.',
    'source' => 'Exact source line UUID',
    'heat' => 'Heat / batch reference',
    'unlinked' => 'Why source is still unresolved (required only when no source UUID)',
    'reason' => 'Reason and supporting basis for this correction',
    'save' => 'Append immutable amendment',
    'readonly' => 'Read-only: only the original preparer with a live custody_mrr grant may correct; archived projects and unavailable current sources cannot be changed.',
    'history' => 'Amendment history',
    'catalog' => 'Readable same-unit PIPING source lines (50 per page)',
    'catalog_help' => 'Copy the exact line UUID. Other pages open separately to preserve this form. No unit conversion; quantity/unit remain those recorded at arrival.',
    'source_hidden' => 'Source details are hidden by current original-source visibility. This does not mean the historical amendment was unlinked.',
    'saved' => 'Amendment recorded. Fresh QC and QA are required; no stock was created.',
    'invalid' => 'Invalid amendment. Check the reason, exact source UUID and heat fields; arrival facts cannot be changed here.',
    'source_error' => 'The source is unavailable, not PIPING or its exact unit does not match the original receipt.',
    'stale' => 'The amendment head or nomination changed. Refresh and review before submitting.',
    'retry' => 'This request differs from an existing request. Refresh; no history was overwritten.',
    'back' => 'Back to physical custody',
    'snapshot' => 'Frozen non-price source snapshot',
    'server_time' => 'Server-recorded UTC time',
    'unit_source' => 'Current source and heat',
    'no_amendments' => 'No amendment recorded.',
];
