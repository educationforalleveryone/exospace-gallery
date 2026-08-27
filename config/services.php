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

    // ── A-10: Operational alerting webhook (Slack/Discord/PagerDuty) ──────
    //
    // ITERATION-10 (AUDIT-P1-10.1): Per-severity alert routing.
    //
    // Previously ALL alerts (critical + warning + info) went to a single
    // webhook (OPERATIONAL_ALERT_WEBHOOK). For a premium SaaS, critical
    // alerts (disk full, scheduler dead, backup missing) should optionally
    // route to a different channel (e.g. PagerDuty or a dedicated
    // #exospace-critical Slack channel) while warnings stay in the general
    // channel.
    //
    // Config precedence (per severity):
    //   critical → OPERATIONAL_ALERT_CRITICAL_WEBHOOK → fall back to OPERATIONAL_ALERT_WEBHOOK
    //   error    → OPERATIONAL_ALERT_ERROR_WEBHOOK    → fall back to OPERATIONAL_ALERT_WEBHOOK
    //   warning  → OPERATIONAL_ALERT_WARNING_WEBHOOK  → fall back to OPERATIONAL_ALERT_WEBHOOK
    //   info     → OPERATIONAL_ALERT_INFO_WEBHOOK     → fall back to OPERATIONAL_ALERT_WEBHOOK
    //
    // When the per-severity env vars are absent, ALL alerts go to the
    // default OPERATIONAL_ALERT_WEBHOOK — fully backward-compatible.
    //
    // ITERATION 9 — the escalation channel (the escape hatch). The
    // watchdog's missing-digest alarm rides the SAME webhook it polices:
    // if the webhook itself is dead, the alarm about the dead alarm
    // channel is lost too. Callers that mark an alert escalate=true
    // (currently only the digest watchdog — the meta-monitor) ALSO post
    // the identical payload to OPS_ESCALATION_WEBHOOK, in an independent
    // try/catch. Point it at a DIFFERENT failure domain than the primary
    // (another Slack workspace's incoming webhook, or a personal DM
    // webhook) — an escalation URL in the same dead workspace defeats
    // its own purpose. Unset = exactly the pre-Iteration-9 behavior.
    //
    // Example .env for split routing:
    //   OPERATIONAL_ALERT_WEBHOOK=https://hooks.slack.com/services/T0.../B0.../...  (general)
    //   OPERATIONAL_ALERT_CRITICAL_WEBHOOK=https://hooks.slack.com/services/T0.../B1.../...  (#exospace-critical)
    //   OPS_ESCALATION_WEBHOOK=https://hooks.slack.com/services/T9.../B9.../...  (OTHER workspace)
    'operational_alerts' => [
        'webhook_url'           => env('OPERATIONAL_ALERT_WEBHOOK'),
        'critical_webhook_url'  => env('OPERATIONAL_ALERT_CRITICAL_WEBHOOK'),
        'error_webhook_url'     => env('OPERATIONAL_ALERT_ERROR_WEBHOOK'),
        'warning_webhook_url'   => env('OPERATIONAL_ALERT_WARNING_WEBHOOK'),
        'info_webhook_url'      => env('OPERATIONAL_ALERT_INFO_WEBHOOK'),
        'escalation_webhook_url' => env('OPS_ESCALATION_WEBHOOK'),
    ],

    // ── ITERATION 6/7: scheduled billing digest recipients ───────────────
    // Comma-separated email addresses receiving the weekly billing export
    // (exospace:send-billing-export, Mondays 07:00).
    //
    // ITERATION 7 precedence (SendBillingExport::resolveRecipients):
    //   1. --to command option (testing/manual)
    //   2. UI-managed DB list (billing_digest_recipients rows on
    //      Master Control → Billing Review) — takes over from the env
    //      var the moment any recipient is added there
    //   3. BILLING_EXPORT_EMAIL env var (this config — the zero-deploy
    //      fallback so a fresh install with no UI-managed recipients
    //      still works)
    //
    // Empty list + no env var → the command is a clean no-op (feature
    // OFF — same convention as the 2CO reconcile job's unconfigured path;
    // the heartbeat still stamps).
    //
    // The digest contains customer billing PII in the CSV attachment —
    // configuring this address IS the consent boundary. Every send is
    // audit-logged as billing.exported (actor: system).
    //
    // Example .env:
    //   BILLING_EXPORT_EMAIL=finance@example.com,cfo@example.com
    'billing_export' => [
        'email' => env('BILLING_EXPORT_EMAIL'),
    ],

    // ── AUDIT-P0-1.3 FIX: Outbound webhooks (M-23) ───────────────────────
    // Centralized env reads so that `php artisan config:cache` is safe.
    // Previously OutboundWebhookService read env() directly in dispatch(),
    // which returns null outside config files once the config is cached.
    //
    // When `url` is empty (the default), the service silently skips — no
    // outbound webhooks are dispatched. Set both values in production to
    // enable outbound event notifications.
    //
    // The `secret` is used to HMAC-SHA256-sign each payload (X-Exospace-Signature
    // header). The receiver verifies the signature to authenticate the payload.
    // Generate with: openssl rand -hex 16
    //
    // ITERATION 11 — `ledger_retention_days` controls the daily prune job
    // that deletes webhook_deliveries rows older than N days. Default 30.
    // The ledger is bounded by this retention window — high-volume async
    // dispatches (gallery.published, user.registered — currently deferred
    // per the dispatchAsync docblock) would explode row growth unboundedly
    // without it. The prune command is scheduled daily at 03:17 (off-peak).
    'outbound_webhook' => [
        'url'                  => env('OUTBOUND_WEBHOOK_URL'),
        'secret'               => env('OUTBOUND_WEBHOOK_SECRET'),
        'ledger_retention_days' => (int) env('OUTBOUND_WEBHOOK_LEDGER_RETENTION_DAYS', 30),
    ],

    '2checkout' => [
        'account_number'         => env('TWOCHECKOUT_ACCOUNT_NUMBER'),
        'secret_word'            => env('TWOCHECKOUT_SECRET_WORD'),

        // Iter-002 (2CO-1): Sandbox mode for the TwoCheckoutApiClient.
        // When true, API calls go to https://api-sandbox.2checkout.com
        // instead of the production https://api.2checkout.com. Set to true
        // for testing cancel/reactivate flows before going live.
        'sandbox'                => filter_var(env('TWOCHECKOUT_SANDBOX', false), FILTER_VALIDATE_BOOLEAN),

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

        // M-1: Recurring (subscription) product IDs. When set, the billing
        // portal offers BOTH a one-time purchase AND a monthly/yearly
        // subscription option. When empty, only one-time purchases are
        // offered (the existing behavior).
        //
        // In 2Checkout, recurring products are created separately from
        // one-time products in the merchant dashboard. The recurring
        // product's "Billing Cycle" determines the interval (monthly,
        // yearly, etc.). Exospace reads the interval from the webhook's
        // item_billing_cycle_* fields — it doesn't need to know the
        // interval ahead of time.
        'recurring_product_id_pro'    => env('TWOCHECKOUT_RECURRING_PRODUCT_ID_PRO'),
        'recurring_product_id_studio' => env('TWOCHECKOUT_RECURRING_PRODUCT_ID_STUDIO'),

        // Iter-002 (2CO-2): One-time product prices for signed buy links.
        // These MUST match the prices configured in the 2Checkout merchant
        // dashboard. If they don't match, 2Checkout rejects the signed buy
        // link (the signature won't validate). Format: plain decimal string
        // (e.g. "29.00", "99.00") — no currency symbol, no thousands separator.
        //
        // When these are not set, the signed buy link is SKIPPED (with a
        // warning log) and the buy link is unsigned — 2Checkout still
        // processes it, but without tamper protection.
        'price_pro'                   => env('TWOCHECKOUT_PRICE_PRO'),
        'price_studio'                => env('TWOCHECKOUT_PRICE_STUDIO'),

        // M-1: Subscription pricing display (shown on the pricing page +
        // billing portal). These are display-only — the actual price is
        // set in the 2Checkout merchant dashboard. Keep in sync.
        'recurring_price_pro_monthly'    => env('TWOCHECKOUT_RECURRING_PRICE_PRO_MONTHLY', '4.99'),
        'recurring_price_studio_monthly' => env('TWOCHECKOUT_RECURRING_PRICE_STUDIO_MONTHLY', '14.99'),

        // (Task H54) — optional default coupon code applied to every
        // upgrade. Set in .env to run a site-wide promotion. Override
        // per-link with ?coupon=XXXX on the billing.upgrade route.
        'coupon_code'            => env('TWOCHECKOUT_COUPON_CODE'),

        // SEC-8 FIX: comma-separated allowlist of coupon codes that may be
        // passed via ?coupon=XXXX on the billing.upgrade route. When a
        // user-supplied coupon is not in this list, it is silently dropped
        // (and the site-wide coupon_code above is used instead, if set).
        // Example: "SUMMER20,LAUNCH50,VIP10"
        // When empty (default), NO user-supplied coupons are accepted —
        // only the site-wide default from coupon_code above is applied.
        'coupon_allowlist'       => env('TWOCHECKOUT_COUPON_ALLOWLIST', ''),

        // (Task H58) — optional default affiliate ID. Set in .env for
        // site-wide affiliate crediting. Override per-link with ?ref=ID
        // on the billing.upgrade route.
        'affiliate_id'           => env('TWOCHECKOUT_AFFILIATE_ID'),

        // SEC-8 FIX: comma-separated allowlist of affiliate IDs that may
        // be passed via ?ref=ID on the billing.upgrade route. Same
        // rationale as coupon_allowlist above. When empty, no user-supplied
        // affiliate IDs are accepted — only the site-wide default.
        'affiliate_allowlist'    => env('TWOCHECKOUT_AFFILIATE_ALLOWLIST', ''),
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

    // ── Cloudflare Turnstile (P3-19) ──────────────────────────────────────
    // Privacy-respecting CAPTCHA alternative. Free, no quotas, no Google
    // tracking. Invisible by default (managed challenge mode).
    //
    // Get keys at: https://dash.cloudflare.com/?to=/:account/turnstile
    //
    // When BOTH keys are empty (the default), Turnstile is DISABLED — the
    // TurnstileService::verify() method returns true unconditionally. This
    // is the safe default for local dev. Set both keys in production to
    // protect public forms (contact, newsletter signup, event RSVP).
    'turnstile' => [
        'site_key'   => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    // ── M-24: OAuth/SSO providers (Google + GitHub) ───────────────────────
    // When client_id is empty, the provider is disabled (OAuth buttons hidden).
    // Create credentials at:
    //   Google:  https://console.cloud.google.com/apis/credentials
    //   GitHub:  https://github.com/settings/developers
    // Set the redirect URI to: https://yourdomain.com/auth/{provider}/callback
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

];