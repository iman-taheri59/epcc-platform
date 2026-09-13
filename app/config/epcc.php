<?php

declare(strict_types=1);

return [
    'pack_dir' => env('EPCC_PACK_DIR', base_path('../standards-packs')),
    'default_pack' => env('EPCC_DEFAULT_PACK', 'ips-iran'),

    // Carry the session id in a query parameter when the browser refuses the
    // session cookie. Only ever enabled on sandbox preview hosts - see
    // PreviewSessionFallback for why this is weaker than a cookie.
    'session_url_fallback' => env('SESSION_URL_FALLBACK', false),
];
