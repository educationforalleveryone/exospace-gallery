<?php

return [

    'flags' => [
        // Subscription / recurring billing
        'subscriptions' => filter_var(env('FEATURE_FLAG_SUBSCRIPTIONS', true), \FILTER_VALIDATE_BOOLEAN),

        // Dunning management (failed payment recovery emails)
        'dunning' => filter_var(env('FEATURE_FLAG_DUNNING', true), \FILTER_VALIDATE_BOOLEAN),

        // Customer invoicing (PDF generation + download)
        'invoicing' => filter_var(env('FEATURE_FLAG_INVOICING', true), \FILTER_VALIDATE_BOOLEAN),

        // Feature comparison table on pricing page
        'feature_comparison_table' => filter_var(env('FEATURE_FLAG_COMPARISON_TABLE', true), \FILTER_VALIDATE_BOOLEAN),

        // Cloudflare Turnstile captcha on public forms
        'turnstile_captcha' => filter_var(env('FEATURE_FLAG_TURNSTILE', true), \FILTER_VALIDATE_BOOLEAN),

        // Hotwire Turbo Drive on admin pages
        'turbo_drive' => filter_var(env('FEATURE_FLAG_TURBO_DRIVE', true), \FILTER_VALIDATE_BOOLEAN),

        // Admin impersonation ("Login As User")
        'admin_impersonation' => filter_var(env('FEATURE_FLAG_ADMIN_IMPERSONATION', true), \FILTER_VALIDATE_BOOLEAN),

        'command_palette' => filter_var(env('FEATURE_FLAG_COMMAND_PALETTE', true), \FILTER_VALIDATE_BOOLEAN),

        'venue_previews' => filter_var(env('FEATURE_FLAG_VENUE_PREVIEWS', true), \FILTER_VALIDATE_BOOLEAN),

        'arrival_choreography' => filter_var(env('FEATURE_FLAG_ARRIVAL', true), \FILTER_VALIDATE_BOOLEAN),

        'venue_authoring' => filter_var(env('FEATURE_FLAG_VENUE_AUTHORING', true), \FILTER_VALIDATE_BOOLEAN),

        'venue_try_on' => filter_var(env('FEATURE_FLAG_VENUE_TRY_ON', false), \FILTER_VALIDATE_BOOLEAN),
    ],

];
