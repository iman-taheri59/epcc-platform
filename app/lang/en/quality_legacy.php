<?php

declare(strict_types=1);

return [
    'title' => 'Legacy warehouse reconciliation',
    'boundary' => 'The cutover closed the legacy warehouse and the GRN guard refuses any purchase order that still has legacy lots. Right — old rows must not be silently mapped onto the controlled chain — but a wall is not a gate. One legacy lot is either taken over (this custody record re-receives it, and must attest the same heat) or retired (it is not coming across, and here is why). Every decision is nominated, reasoned and chained; none is ever edited.',
    'nominations' => 'Nomination register',
    'results' => 'Result recording',
    'signoffs' => 'ITP sign-offs',
    'mtr' => 'MTR registry',

    'your_authority' => 'Your authority in this project',
    'action' => 'Action',
    'you' => 'You',
    'action_reconcile_legacy' => 'Reconcile legacy material',
    'nominated' => 'Nominated — the form is live',
    'not_nominated' => 'Not nominated — the form is not shown',

    'pending' => 'Still blocking: legacy lots nobody has decided about',
    'lot' => 'Lot',
    'item' => 'Item',
    'heat' => 'Heat',
    'unit' => 'Unit',
    'purchase_order' => 'Purchase order',
    'no_pending' => 'No legacy lot is waiting for a decision, so nothing blocks a controlled GRN.',
    'select' => 'Select',
    'open' => 'Open',

    'decided' => 'Lots already decided',
    'decision' => 'Decision',
    'decided_at' => 'Decided at',
    'reason' => 'Reason',
    'custody' => 'Custody record',
    'no_decided' => 'No legacy lot has been reconciled yet.',
    'chain' => 'The decision chain for this lot',
    'sequence' => '#',

    'form_title' => 'Decide about this lot',
    'denied' => 'You are not nominated to reconcile legacy material, so the form is not shown.',
    'take_over_custody' => 'Custody that re-receives it (only receipts attesting this lot’s heat are offered)',
    'reason_help' => 'Every decision about legacy material must be reasoned.',
    'submit' => 'Record the decision',
    'saved' => 'Decision recorded: :decision.',
    'supersedes' => 'This decision supersedes the current one; both stay on the record.',
];
