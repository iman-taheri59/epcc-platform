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

    // Gate 1 hardening: bound credential attempts per IP+email pair. The
    // attempt handler clears the limiter on success, so honest logins are
    // never throttled by their own history.
    'auth' => [
        'login_throttle_per_minute' => (int) env('EPCC_LOGIN_THROTTLE_PER_MINUTE', 10),
    ],

    // Listing seeded demo accounts on the unauthenticated login screen is a
    // convenience for local demos and an identity-disclosure leak anywhere
    // else. Defaults to APP_DEBUG so production (debug=false) never lists.
    'demo_login_hints' => (bool) env('EPCC_DEMO_LOGIN_HINTS', env('APP_DEBUG', false)),

    // `migrate:fresh --seed` writes demo identities with well-known
    // passwords. Production seeding must be a deliberate, explicit act.
    'allow_demo_seed' => (bool) env('EPCC_ALLOW_DEMO_SEED', false),
];
