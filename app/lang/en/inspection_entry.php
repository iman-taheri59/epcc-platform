<?php

return [
    'access_title' => 'Inspection recorder access', 'access_scope' => 'Owner project.configure independently nominates active project people or company-scoped roles. No default recorders; no inherited MR/PO/MTO authority or new PO read delegation.',
    'access_unconfigured' => 'No inspection recorders configured.', 'access_suspended' => 'New inspection entry is suspended.',
    'access_version' => 'Policy version', 'access_configure' => 'Configure inspection recorders', 'access_selectors' => 'People or company-scoped project roles',
    'access_selector_help' => 'Select current project participants only. Role nominations follow current membership; nomination never unlocks a hidden order.',
    'access_suspend' => 'Suspend new inspection entry', 'access_save' => 'Save new inspection policy version', 'access_saved' => 'Independent inspection recorder policy saved.',
    'access_history' => 'Recorder nomination history', 'access_history_limit' => 'Latest 20 versions; earlier records remain stored.',
    'access_stale' => 'Policy changed. Reload before configuring.', 'access_reason_required' => 'A nonblank policy-change reason is required.',
    'access_select_required' => 'Select recorders or explicitly suspend entry.', 'access_invalid_selector' => 'Invalid recorder selection, fields or changed membership.', 'reason' => 'Reason',
    'title' => 'Record an inspection for this visible PO', 'scope' => 'Create a new attributed observation only. The Gregorian date and findings are user-reported, not certified signatures, commercial-version approval or proof of usable stock. Inspection remains advisory and does not block receipt. No PO/source/lot status is changed. No editing/deleting existing records or delegation of hidden PO access.',
    'result' => 'Reported result', 'stage' => 'Inspection stage', 'date' => 'Reported inspection date (Gregorian, stored at UTC midnight)',
    'findings_en' => 'Findings — English (optional)', 'findings_fa' => 'Findings — Persian (optional)', 'save' => 'Record inspection',
    'saved' => 'Inspection recorded; no order, source, stock status or receipt eligibility changed.', 'policy' => 'Recorded nomination policy', 'recorded' => 'Server recorded time (UTC)',
    'refused' => 'Inspection entry refused:', 'sequence_exhausted' => 'Inspection sequence range exhausted.', 'number_conflict' => 'Inspection number conflicts with historical data; no record was written. Review numbering before retrying.',
    'findings_required' => 'A failed or conditional inspection must record findings in at least one language.',
    'independence' => 'Existing inspector independence is retained: only TPI, PMC, MC or owner-company personnel may be nominated. Contractors/vendors cannot sign off their own material. FAIL/CONDITIONAL requires findings. Numbering retains the existing IRN format.',
];
