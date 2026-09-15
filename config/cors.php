<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | The public /api/v1 read endpoints are consumed by third-party clients,
    | including browsers on arbitrary origins, so api/* stays open for
    | unauthenticated reads. Browser-facing app routes (web group) are never
    | cross-origin reachable: they rely on SameSite=Lax session cookies plus
    | CSRF tokens, both of which are meaningless cross-origin.
    |
    | supports_credentials stays false — no origin may make credentialed
    | cross-origin requests, so API clients authenticate with Bearer tokens.
    |
    | The Sanctum csrf-cookie route is intentionally absent from paths: the
    | app authenticates via Blade forms and personal access tokens, never
    | via the SPA cookie flow.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
