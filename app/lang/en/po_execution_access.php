<?php

return [
    'title' => 'PO financial / issuance nominations', 'scope' => 'Separate financial approvers and issuers for each target buyer. Drafter, financial approver and issuer must be three different people for each PO. No default or inherited MR/PMS/draft-author authority.',
    'stage' => 'Nominations alone do not approve an order. Use the scoped execution workspace for eligible version-bound financial approval and issuance.',
    'buyer' => 'Target buyer', 'finance' => 'Financial approver nominees', 'issue' => 'Issuer nominees', 'none' => 'No nominees', 'unconfigured' => 'Not configured — no nomination',
    'version' => 'Policy version', 'configure' => 'Configure this buyer', 'reason' => 'Reason', 'help' => 'People or company-scoped roles of participating companies. Ctrl/Command selects multiple. An empty purpose list grants nobody that purpose. Overlapping lists do not waive the future three-person rule.',
    'suspend' => 'Suspend both purposes for this buyer', 'save' => 'Save new policy version', 'saved' => 'Buyer-scoped financial/issuance nominations saved; no PO was approved or issued.',
    'history' => 'History (latest 20 per buyer; earlier versions retained)', 'buyer_invalid' => 'Buyer must be a participating OWN/EPC/SUB company in this project.',
    'stale' => 'This buyer policy changed. Reload and review before saving.', 'reason_required' => 'A non-blank reason is required.',
    'selector_invalid' => 'A nominee is invalid or no longer an active participating project member/role.', 'selection_required' => 'Select at least one nominee or explicitly suspend both purposes.',
];
