<?php

return [
    'action' => 'PO draft from this MR', 'scope' => 'Copies all 1–20 lines from this approved own-company MR. Independent PO author nomination is required. No issue, quantity reservation or MR consumption. Multiple drafts do not mean multiple approved purchases.',
    'vendor' => 'Vendor', 'date' => 'Contractual delivery (Gregorian input, optional)', 'save' => 'Create linked PO draft',
    'created' => 'Linked draft :number created; MR remains approved and quantities are not reserved or consumed.',
    'not_eligible' => 'Source MR must be approved and have an explicit material category.', 'line_limit' => 'Full-copy entry requires 1–20 source lines; partial imports are not supported.',
    'source_line' => 'Source MR line', 'hidden_source' => 'Source MR line is not visible in your access scope.',
    'provenance' => 'The MR link records draft provenance. It does not itself reserve or consume MR quantities or authorize issuance.',
];
