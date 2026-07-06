<?php

/**
 * M-14: Feature flags configuration.
 *
 * Feature flags allow new features to be safely rolled out to a subset of
 * users (or disabled entirely) without code changes. Each flag is a
 * boolean config value that can be:
 *   - Set globally via .env (e.g. FEATURE_FLAG_SUBSCRIPTIONS=true)
 *   - Checked in Blade via @featureFlag('subscriptions')
 *   - Checked in PHP via FeatureFlag::isEnabled('subscriptions')
 *   - Used as middleware: ->middleware('feature_flag:subscriptions')
 *
 * Flag values:
 *   - true  → feature is ENABLED for all users
 *   - false → feature is DISABLED for all users (code is skipped)
 *   - null  → feature is DISABLED (same as false; explicit null makes
 *             the "not yet configured" state visible in config dumps)
 *
 * To add a new feature flag:
 *   1. Add an entry to the $flags array below
 *   2. Add the corresponding env var to .env.example
 *   3. Wrap the feature's UI/code in @featureFlag('flag_name') / FeatureFlag::isEnabled('flag_name')
 *   4. (Optional) Add ->middleware('feature_flag:flag_name') to routes
 *
 * Existing flags:
 *   - 'subscriptions' — M-1 recurring subscription billing (default: true)
 *   - 'dunning' — M-9 dunning email sequence (default: true)
 *   - 'invoicing' — M-10 PDF invoice generation (default: true)
 *   - 'feature_comparison_table' — CONV-2 pricing comparison table (default: true)
 *   - 'turnstile_captcha' — P3-19 Cloudflare Turnstile on public forms (default: true)
 *   - 'turbo_drive' — PERF-26 Hotwire Turbo on admin pages (default: true)
 */

return [

    'flags' => [
        // M-1: Subscription / recurring billing
        'subscriptions' => filter_var(env('FEATURE_FLAG_SUBSCRIPTIONS', true), \FILTER_VALIDATE_BOOLEAN),

        // M-9: Dunning management (failed payment recovery emails)
        'dunning' => filter_var(env('FEATURE_FLAG_DUNNING', true), \FILTER_VALIDATE_BOOLEAN),

        // M-10: Customer invoicing (PDF generation + download)
        'invoicing' => filter_var(env('FEATURE_FLAG_INVOICING', true), \FILTER_VALIDATE_BOOLEAN),

        // CONV-2: Feature comparison table on pricing page
        'feature_comparison_table' => filter_var(env('FEATURE_FLAG_COMPARISON_TABLE', true), \FILTER_VALIDATE_BOOLEAN),

        // P3-19: Cloudflare Turnstile captcha on public forms
        'turnstile_captcha' => filter_var(env('FEATURE_FLAG_TURNSTILE', true), \FILTER_VALIDATE_BOOLEAN),

        // PERF-26: Hotwire Turbo Drive on admin pages
        'turbo_drive' => filter_var(env('FEATURE_FLAG_TURBO_DRIVE', true), \FILTER_VALIDATE_BOOLEAN),

        // M-13: Admin impersonation ("Login As User")
        'admin_impersonation' => filter_var(env('FEATURE_FLAG_ADMIN_IMPERSONATION', true), \FILTER_VALIDATE_BOOLEAN),
    ],

];
