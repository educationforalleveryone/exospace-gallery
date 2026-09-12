<?php

return [

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

    'operational_alerts' => [
        'webhook_url'           => env('OPERATIONAL_ALERT_WEBHOOK'),
        'critical_webhook_url'  => env('OPERATIONAL_ALERT_CRITICAL_WEBHOOK'),
        'error_webhook_url'     => env('OPERATIONAL_ALERT_ERROR_WEBHOOK'),
        'warning_webhook_url'   => env('OPERATIONAL_ALERT_WARNING_WEBHOOK'),
        'info_webhook_url'      => env('OPERATIONAL_ALERT_INFO_WEBHOOK'),
        'escalation_webhook_url' => env('OPS_ESCALATION_WEBHOOK'),
    ],

    'billing_export' => [
        'email' => env('BILLING_EXPORT_EMAIL'),
    ],

    'outbound_webhook' => [
        'url'                  => env('OUTBOUND_WEBHOOK_URL'),
        'secret'               => env('OUTBOUND_WEBHOOK_SECRET'),
        'ledger_retention_days' => (int) env('OUTBOUND_WEBHOOK_LEDGER_RETENTION_DAYS', 30),
    ],

    '2checkout' => [
        'account_number'         => env('TWOCHECKOUT_ACCOUNT_NUMBER'),
        'secret_word'            => env('TWOCHECKOUT_SECRET_WORD'),

        'sandbox'                => filter_var(env('TWOCHECKOUT_SANDBOX', false), FILTER_VALIDATE_BOOLEAN),

        'buy_link_secret_word'   => env('TWOCHECKOUT_BUY_LINK_SECRET_WORD'),

        'allow_md5_only'         => filter_var(env('TWOCHECKOUT_ALLOW_MD5_ONLY', false), FILTER_VALIDATE_BOOLEAN),

        'webhook_ip_allowlist'   => env('TWOCHECKOUT_WEBHOOK_IP_ALLOWLIST'),

        'product_id_pro'         => env('TWOCHECKOUT_PRODUCT_ID_PRO'),
        'product_id_studio'      => env('TWOCHECKOUT_PRODUCT_ID_STUDIO'),

        'recurring_product_id_pro'    => env('TWOCHECKOUT_RECURRING_PRODUCT_ID_PRO'),
        'recurring_product_id_studio' => env('TWOCHECKOUT_RECURRING_PRODUCT_ID_STUDIO'),

        'price_pro'                   => env('TWOCHECKOUT_PRICE_PRO'),
        'price_studio'                => env('TWOCHECKOUT_PRICE_STUDIO'),

        'recurring_price_pro_monthly'    => env('TWOCHECKOUT_RECURRING_PRICE_PRO_MONTHLY', '4.99'),
        'recurring_price_studio_monthly' => env('TWOCHECKOUT_RECURRING_PRICE_STUDIO_MONTHLY', '14.99'),

        'coupon_code'            => env('TWOCHECKOUT_COUPON_CODE'),

        'coupon_allowlist'       => env('TWOCHECKOUT_COUPON_ALLOWLIST', ''),

        'affiliate_id'           => env('TWOCHECKOUT_AFFILIATE_ID'),

        'affiliate_allowlist'    => env('TWOCHECKOUT_AFFILIATE_ALLOWLIST', ''),
    ],

    'coolify' => [
        'api_token'    => env('COOLIFY_API_TOKEN'),
        'api_base_url' => rtrim((string) env('COOLIFY_API_BASE_URL', ''), '/'),
        'application_uuid' => env('COOLIFY_APPLICATION_UUID'),
    ],

    'turnstile' => [
        'site_key'   => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    'github' => [
        'client_id'     => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect'      => env('GITHUB_REDIRECT_URI', '/auth/github/callback'),
    ],

    'contact_form' => [
        'email' => env('CONTACT_FORM_EMAIL', 'admin@exospace.gallery'),
    ],

];