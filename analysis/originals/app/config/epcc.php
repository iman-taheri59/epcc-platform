<?php

declare(strict_types=1);

return [
    'pack_dir' => env('EPCC_PACK_DIR', base_path('../standards-packs')),
    'default_pack' => env('EPCC_DEFAULT_PACK', 'ips-iran'),

    // Engineering reference data (ASME B36.10M/B36.19M sizes and schedules, PCF
    // end types, component templates). Universal constants, versioned as files,
    // never in the database - see ADR-007.
    'reference_dir' => env('EPCC_REFERENCE_DIR', base_path('../standards-packs/reference')),

    // Carry the session id in a query parameter when the browser refuses the
    // session cookie. Only ever enabled on sandbox preview hosts - see
    // PreviewSessionFallback for why this is weaker than a cookie.
    'session_url_fallback' => env('SESSION_URL_FALLBACK', false),
];
