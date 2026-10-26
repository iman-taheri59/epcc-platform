<?php

return [
    'title' => 'Material requisition details', 'back' => 'Back to procurement',
    'scope' => 'Read-only stored requisition and visible related orders. Quantities with different units are not added. A draft link is not a reservation or an issued commitment; this sheet provides no official remaining quantity or issuance certificate.',
    'company' => 'Requesting company', 'discipline' => 'Discipline', 'state' => 'Stored state', 'category' => 'Material category',
    'lines' => 'Stored requisition lines', 'line' => 'Line', 'id' => 'Actual source line UUID', 'code' => 'Item code', 'description' => 'Description', 'quantity' => 'Stored quantity', 'spelling' => 'Original quantity spelling, when retained', 'unit' => 'Unit', 'subsystem' => 'Subsystem', 'specification' => 'Specification',
    'unknown' => 'Not recorded / not visible', 'empty' => 'No visible lines.',
    'approvals' => 'Recorded workflow fields', 'approval_note' => 'Actor UUIDs and dates are stored metadata, not verified legal signatures or a complete event history. No hidden user directory or buyer journal is opened.',
    'raised' => 'Raised', 'technical_approved' => 'Technical approval', 'commercial_approved' => 'Commercial approval', 'rejected' => 'Rejection', 'actor' => 'Recorded actor UUID', 'date' => 'Recorded date', 'reason' => 'Recorded rejection reason',
    'related' => 'Related visible same-buyer orders (25 per page)', 'related_note' => 'Relationships use actual header/source-line IDs, never item-code matching. Hidden or discipline-excluded orders may be absent; this is not a complete commitment assessment.', 'number' => 'Order', 'relationship' => 'Relationship', 'header' => 'Header-linked MR', 'line_only' => 'Source-line link / different or missing header MR', 'empty_orders' => 'No related orders visible on this page.', 'discipline_note' => 'Your current discipline scope may omit related records.',
];
