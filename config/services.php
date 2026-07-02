<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
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

        // Optional but strongly recommended — see WebhookController::verify2CheckoutSignature()
        // When set, the webhook's `signature` field is verified via HMAC SHA-256
        // over the security-critical IPN fields (customer_email, item_id, amount, etc.).
        // Configure 2Checkout to send the `signature` parameter in your merchant
        // dashboard (Notification settings → Advanced → HMAC SHA-256 signature).
        'buy_link_secret_word'   => env('TWOCHECKOUT_BUY_LINK_SECRET_WORD'),

        // Optional comma-separated IP allowlist for 2Checkout INS servers.
        // 2Checkout publishes their INS IP ranges in their merchant docs.
        // Example: "198.61.180.0/22,162.242.218.0/24"
        'webhook_ip_allowlist'   => env('TWOCHECKOUT_WEBHOOK_IP_ALLOWLIST'),

        'product_id_pro'         => env('TWOCHECKOUT_PRODUCT_ID_PRO'),
        'product_id_studio'      => env('TWOCHECKOUT_PRODUCT_ID_STUDIO'),
    ],

];