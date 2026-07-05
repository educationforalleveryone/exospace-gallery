<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plan Limits (TD-27 unified source of truth)
    |--------------------------------------------------------------------------
    |
    | This file is the SINGLE source of truth for plan feature limits.
    | Previously these values were duplicated across:
    |   - app/Models/User.php::planLimits()         (the authoritative copy)
    |   - resources/views/pages/pricing.blade.php   (UI display)
    |   - resources/views/billing/index.blade.php   (UI display)
    |   - database/seeders/VenueTemplateSeeder.php  (plan_required per venue)
    |   - app/Services/VenueConfigExporter.php      (plan gating for decorations)
    |   - app/Services/PlanDowngradeService.php     (downgrade target limits)
    |
    | Now all callers should read from config('plans.limits.{plan}').
    | The User::planLimits() method still exists but now reads from this
    | config (backward compatibility for any callers that haven't been
    | updated). The pricing/billing blades still hardcode the display
    | values — a future iteration can convert them to read from config
    | too, but the values must stay in sync with this file.
    |
    | Format: [plan_key => [limit_key => limit_value]]
    |
    | If you change a value here, also update:
    |   - resources/views/pages/pricing.blade.php (UI display)
    |   - resources/views/billing/index.blade.php (UI display)
    |   - database/seeders/VenueTemplateSeeder.php (plan_required per venue)
    |   - app/Services/VenueConfigExporter.php (plan gating for decorations)
    |
    | The 'free' plan is the default and is keyed under 'default' in the
    | match statement in User::planLimits() — any unknown plan falls back
    | to free limits.
    |
    | Plan prices are also defined here for UI convenience (the pricing
    | page reads them). The 2Checkout product IDs live in
    | config/services.php under the '2checkout' key — those are kept
    | separate because they're payment-provider-specific.
    |
    */

    'limits' => [
        'studio' => ['max_galleries' => 999, 'max_images' => 500],
        'pro'    => ['max_galleries' => 5,   'max_images' => 100],
        'free'   => ['max_galleries' => 1,   'max_images' => 10],
    ],

    // Display metadata for the pricing page + billing portal.
    // Keep in sync with resources/views/pages/pricing.blade.php.
    'display' => [
        'free' => [
            'name'        => 'Free',
            'price'       => 0,
            'price_label' => 'Free',
            'tagline'     => '1 gallery · 10 images',
            'features'    => [
                '1 gallery',
                '10 images per gallery',
                'Standard venue templates',
                'Community support',
            ],
        ],
        'pro' => [
            'name'        => 'Pro',
            'price'       => 29,
            'price_label' => '$29',
            'tagline'     => '5 galleries · 100 images',
            'features'    => [
                '5 galleries',
                '100 images per gallery',
                'Background music',
                'No Exospace watermark',
                'Priority email support',
            ],
        ],
        'studio' => [
            'name'        => 'Studio',
            'price'       => 99,
            'price_label' => '$99',
            'tagline'     => 'Unlimited galleries · 500 images',
            'features'    => [
                'Unlimited galleries',
                '500 images per gallery',
                'Custom domains',
                'White-label (no Exospace branding)',
                'Custom gallery logos',
                'Priority support',
            ],
        ],
    ],

    // Plan tier ranking — higher = more features. Used for upgrade/downgrade
    // direction checks in BillingController::upgrade().
    'rank' => [
        'free'   => 0,
        'pro'    => 1,
        'studio' => 2,
    ],
];
