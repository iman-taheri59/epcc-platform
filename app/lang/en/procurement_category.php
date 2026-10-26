<?php

return [
    'label' => 'Material category', 'choose' => 'Select one category', 'all' => 'All categories',
    'legacy' => 'Unclassified legacy document', 'filter' => 'Filter MR / PO',
    'help' => 'One category per document. Different categories require separate MRs / POs. Free-text lines are manually declared, not catalogue-verified.',
    'material_id' => 'Exact catalogue material UUID (optional)',
    'identity_help' => 'Optional catalogue UUID must match this project or global library, category, discipline, exact code and base unit. Leave blank for declared manual entry.',
    'declared' => 'Manually declared category — identity not verified', 'catalogue' => 'Catalogue-linked category',
    'mismatch' => 'All lines must belong to the document material category.',
    'identity_mismatch' => 'Material identity is unavailable or does not match category, project, discipline, exact code or unit.',
    'PIPE' => 'Pipes', 'FITTING' => 'Fittings', 'FLANGE' => 'Flanges', 'VALVE' => 'Valves',
    'GASKET' => 'Gaskets', 'BOLT_NUT' => 'Bolts and nuts', 'SPECIALTY' => 'Specialties', 'SUPPORT' => 'Supports',
];
