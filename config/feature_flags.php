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
 *   - 'command_palette' — ITERATION-3 ⌘K command palette in admin (default: true)
 *   - 'venue_previews' — Iteration 1 walkable venue previews (default: true)
 *   - 'arrival_choreography' — Iteration 4 composed first frame (default: true)
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

        // ITERATION-3 (AUDIT-P1-3.2): ⌘K command palette — fuzzy-search
        // navigation + actions from any admin page. Progressive enhancement
        // (no impact when disabled or when JS fails). Default true.
        'command_palette' => filter_var(env('FEATURE_FLAG_COMMAND_PALETTE', true), \FILTER_VALIDATE_BOOLEAN),

        // Iteration 1 "The Rehearsal" (roadmap P1.1): public walkable venue
        // previews (GET /venues/{slug}/preview + "Walk through" affordances
        // on picker cards and venue pages). Default true — the chooser test
        // is the point. Rollback: set FEATURE_FLAG_VENUE_PREVIEWS=false →
        // the route 404s (indistinguishable from nonexistent) and every
        // "Walk through" button disappears; nothing else changes.
        'venue_previews' => filter_var(env('FEATURE_FLAG_VENUE_PREVIEWS', true), \FILTER_VALIDATE_BOOLEAN),

        // Iteration 4 "Arrival" (roadmap P1.4): the composed first frame —
        // spawn facing the exhibition's hero artwork, 1.5 s ease-out dolly
        // into the classic spawn pose, then control handoff. Reduced-motion
        // users get an instant composed cut (no dolly). The flag is exposed
        // to the 3D runtime as GALLERY_DATA.arrival_enabled by the gallery
        // viewer, venue preview, and admin live-preview payloads. Rollback:
        // set FEATURE_FLAG_ARRIVAL=false → the classic inert spawn is
        // restored 1:1 (the dolly ends at the exact spawn point RoomBuilder
        // always set, so off-state behaviour is byte-identical to pre-IT4).
        'arrival_choreography' => filter_var(env('FEATURE_FLAG_ARRIVAL', true), \FILTER_VALIDATE_BOOLEAN),
    ],

];
