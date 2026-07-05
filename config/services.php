<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have a
    | conventional file to locate these service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    '2checkout' => [
        'account_number'         => env('TWOCHECKOUT_ACCOUNT_NUMBER'),
        'secret_word'            => env('TWOCHECKOUT_SECRET_WORD'),

        // P0-2 FIX (audit): HMAC SHA-256 signature verification is now
        // MANDATORY in production. When set, the webhook's `signature`
        // field is verified via HMAC SHA-256 over the security-critical
        // IPN fields (customer_email, item_id, message_type, amount, etc.).
        // This closes the replay-tamper attack where an attacker captures
        // a valid IPN, then changes customer_email / item_id_1 / message_type
        // and re-POSTs it — the MD5 hash still validates (those fields
        // aren't signed by MD5), but the HMAC SHA-256 fails.
        //
        // Configure 2Checkout to send the `signature` parameter in your
        // merchant dashboard (Notification settings → Advanced →
        // HMAC SHA-256 signature). The buy-link secret word is configured
        // in the same dashboard section.
        //
        // If this is empty in production, the webhook FAILS CLOSED
        // (returns 403 on every IPN) unless TWOCHECKOUT_ALLOW_MD5_ONLY=true
        // is explicitly set as an emergency escape hatch.
        'buy_link_secret_word'   => env('TWOCHECKOUT_BUY_LINK_SECRET_WORD'),

        // Emergency escape hatch for MD5-only mode. Defaults to false.
        // Set to true ONLY during 2Checkout account migration or if the
        // HMAC signature configuration is temporarily unavailable.
        // Every webhook received in this mode logs a CRITICAL warning.
        // filter_var is used because env() returns strings — without it,
        // the string 'false' would be truthy in PHP.
        'allow_md5_only'         => filter_var(env('TWOCHECKOUT_ALLOW_MD5_ONLY', false), FILTER_VALIDATE_BOOLEAN),

        // Optional comma-separated IP allowlist for 2Checkout INS servers.
        // 2Checkout publishes their INS IP ranges in their merchant docs.
        // Example: "198.61.180.0/22,162.242.218.0/24"
        //
        // When NOT set in production, the webhook logs a CRITICAL warning
        // on every request (but does not reject — HMAC is the primary
        // defense, IP allowlist is defense-in-depth).
        'webhook_ip_allowlist'   => env('TWOCHECKOUT_WEBHOOK_IP_ALLOWLIST'),

        'product_id_pro'         => env('TWOCHECKOUT_PRODUCT_ID_PRO'),
        'product_id_studio'      => env('TWOCHECKOUT_PRODUCT_ID_STUDIO'),

        // (Task H54) — optional default coupon code applied to every
        // upgrade. Set in .env to run a site-wide promotion. Override
        // per-link with ?coupon=XXXX on the billing.upgrade route.
        'coupon_code'            => env('TWOCHECKOUT_COUPON_CODE'),

        // (Task H58) — optional default affiliate ID. Set in .env for
        // site-wide affiliate crediting. Override per-link with ?ref=ID
        // on the billing.upgrade route.
        'affiliate_id'           => env('TWOCHECKOUT_AFFILIATE_ID'),
    ],

    // ── Coolify custom-domain automation (task C14) ──────────────────────
    // Centralized env reads so that `php artisan config:cache` is safe.
    // Previously CoolifyDomainManager read env() directly in its constructor,
    // which breaks under config:cache because env() returns null outside of
    // config files once the config is cached.
    'coolify' => [
        'api_token'    => env('COOLIFY_API_TOKEN'),
        'api_base_url' => rtrim((string) env('COOLIFY_API_BASE_URL', ''), '/'),
        'application_uuid' => env('COOLIFY_APPLICATION_UUID'),
    ],

];
