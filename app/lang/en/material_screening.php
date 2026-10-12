<?php
return [
    'current_title' => 'Current technical screening',
    'current_note' => 'Recomputed using current rules and loaded references; not historical approval evidence.',
    'snapshot_title' => 'Screening captured at submission',
    'snapshot_note' => 'Immutable submission-time record, separate from today’s screening and human decisions. It does not grant engineering approval.',
    'submitted_at' => 'Submission time',
    'snapshot_identity' => 'Stored source and capture identity',
    'run' => 'Review run',
    'definition' => 'Definition version',
    'submitter' => 'Submitted by',
    'result_hash' => 'Result SHA-256',
    'implementation_hash' => 'Implementation file SHA-256',
    'legacy_missing' => 'No submission-time screening was captured for this older review. Today’s result is not substituted as historical evidence.',
    'capture_on_submit' => 'Screening evidence will be captured when this version is submitted for review.',

    'title' => 'Technical screening',
    'notice' => 'Read-only automated findings, separate from project review status. This is not engineering approval or a submission gate. Check actual Spec, applicability and tolerances; source values are unchanged.',
    'evidence' => 'Rule version, source fingerprint and references',
    'DISCREPANCY' => 'Discrepancy',
    'INCOMPLETE' => 'Incomplete',
    'REVIEW_REQUIRED' => 'Source review required',
];
