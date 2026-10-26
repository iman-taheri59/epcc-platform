<?php

declare(strict_types=1);

return [
    'title' => 'Piping receipt responsibilities',
    'scope' => 'Owner-only, independently nominated responsibilities. Configuration does not create receipts, approve material, post GRN or grant PO/certificate reading.',
    'sod' => 'MRR preparer, receiving QC and QA acceptor must be three different people at execution. GRN may be posted by one of them only with its independent warehouse nomination. Overlapping selector sets are not proof of compliant execution.',
    'unconfigured' => 'Unconfigured — no default custodian or nominees.',
    'configured' => 'Responsibilities configured — custody and receiving observations are separate actions; QA/GRN are not operational.',
    'suspended' => 'Suspended — all four nomination sets are empty.',
    'readonly' => 'Archived project — retained configuration/history is read-only.',
    'custodian' => 'Participating custodian company',
    'choose' => 'Select explicitly',
    'custody_mrr' => 'Material controller / custody and MRR',
    'receiving_qc' => 'Receiving QC review',
    'acceptance_qa' => 'QA final acceptance',
    'post_grn' => 'Warehouse GRN posting',
    'reason' => 'Reason',
    'save' => 'Save new configuration version',
    'suspend' => 'Suspend all four responsibilities',
    'version' => 'Version',
    'history' => 'Retained configuration history',
    'history_limit' => 'Latest 20 versions shown; older records remain retained.',
    'selector_help' => 'Choose one or more active project people or company-bound roles for each action. No default/inherited nomination; source access remains independent.',
    'saved' => 'New receipt responsibility configuration saved. No stock or receipt was created.',
    'invalid' => 'Invalid custodian, selector or configuration. Refresh and select current project options.',
    'stale' => 'Configuration changed. Refresh, review the current version and submit again; your old selections are not silently rebased.',
    'back' => 'Back to warehouse',
    'recorded_custodian' => 'Recorded custodian',
    'unavailable' => 'Recorded selection no longer available; choose a current company or suspend.',
    'no_options' => 'No eligible current options.',
];
