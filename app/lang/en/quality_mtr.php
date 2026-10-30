<?php

declare(strict_types=1);

return [
    'title' => 'Material certificates (MTR) and custody bindings',
    'boundary' => 'A certificate says "heat H of this item meets the specification"; custody says "we received heat H". A binding joins the two, and it is derived, never declared: the heat and lot come from the certificate, the custody record comes from the project. Where custody attests nothing, the certificate can still be kept — as unresolved, with a reason, never silently.',
    'nominations' => 'Nomination register',
    'results' => 'Result recording',
    'signoffs' => 'ITP sign-offs',

    'your_authority' => 'Your authority in this project',
    'action' => 'Action',
    'you' => 'You',
    'action_register_certificate' => 'Register a material certificate',
    'action_bind_certificate' => 'Bind a certificate to custody',
    'nominated' => 'Nominated — the form is live',
    'not_nominated' => 'Not nominated — the form is not shown',

    'registry' => 'Certificate registry',
    'certificate' => 'Certificate',
    'certificate_ref' => 'Certificate number',
    'certificate_type' => 'Type',
    'heat' => 'Heat',
    'lot' => 'Lot',
    'issuer' => 'Issuer',
    'registered_at' => 'Registered at',
    'binding' => 'Binding',
    'no_certificates' => 'No material certificate has been registered in this project.',
    'select' => 'Select',
    'open' => 'Open',

    'register_title' => 'Register a certificate you were given',
    'denied_register' => 'You are not nominated to register material certificates, so the form is not shown.',
    'submit_certificate' => 'Register the certificate',

    'bind_title' => 'Bind this certificate to the custody that attests its heat',
    'denied_bind' => 'You are not nominated to bind certificates, so the form is not shown.',
    'unlinked_reason' => 'Reason, if custody attests nothing (required to keep it as unresolved)',
    'submit_binding' => 'Bind the certificate',
    'saved' => 'Certificate bound as :binding.',
    'certificate_saved' => 'Certificate registered. Bind it to the custody that attests its heat.',

    'history' => 'Bindings recorded for this certificate',
    'bound_at' => 'Bound at',
    'custody' => 'Custody record',
    'item_code' => 'Item',
    'reason' => 'Reason',
    'none' => 'This certificate has not been bound yet.',

    'coverage' => 'Coverage — the turnover question',
    'coverage_attested' => 'Heats custody attests (a zero means no certificate covers it)',
    'coverage_unresolved' => 'Certificates kept as unresolved, with the reason',
    'custody_records' => 'Custody records',
    'certificates' => 'Certificates',
    'no_attested' => 'Custody does not attest any heat yet, so there is nothing to cover.',
    'no_unresolved' => 'No certificate is unresolved.',
];
