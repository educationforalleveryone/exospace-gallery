<?php

use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains will receive stateful API session
    | cookies. The Exospace SPA is Blade-rendered, so only the first-party
    | application domain needs stateful access. Custom domains are scoped
    | per-request by App\Http\Middleware\ScopeSessionDomain when a verified
    | custom-domain gallery request arrives.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | ITERATION-1 FIX (API token hygiene): the default (null) meant personal
    | access tokens NEVER expired — a token leaked via an exported script or
    | a developer laptop granted read access to a user's galleries forever,
    | and outlived password changes. One year bounds the blast window while
    | being long enough that legitimate API consumers (analytics pulls,
    | embed integrations) are not forced through a disruptive re-auth cycle;
    | tokens are also revoked immediately when an account is banned
    | (CheckBanned middleware).
    |
    | Existing tokens issued before this config landed keep their original
    | (null) expires_at and remain valid — they age out only as users mint
    | new tokens. For a hard cutover, an operator can wipe
    | personal_access_tokens once and announce the re-auth.
    |
    */

    'expiration' => env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 60 * 24 * 365),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'exo_'),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
