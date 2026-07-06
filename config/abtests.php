<?php

/**
 * M-15: A/B testing configuration.
 *
 * Defines experiments with variants + traffic allocation. Each experiment
 * has a name, variants (A, B, C, ...), and the percentage of traffic that
 * gets each variant. Users are assigned a variant based on a hash of their
 * session ID (deterministic — the same user always sees the same variant).
 *
 * To add a new experiment:
 *   1. Add an entry to the $experiments array
 *   2. Use @abtest('experiment_name') in Blade to show variant-specific content
 *   3. Track conversion events per variant (future: integrate with analytics)
 *
 * Example Blade usage:
 *   @abtest('pricing_cta')
 *       @variant('A')
 *           <a href="/billing/upgrade/pro">Upgrade to Pro — $29</a>
 *       @endvariant
 *       @variant('B')
 *           <a href="/billing/upgrade/pro">Get Pro — $29 one-time</a>
 *       @endvariant
 *   @endabtest
 *
 * Or in PHP:
 *   $variant = ABTest::variant('pricing_cta');
 *   if ($variant === 'B') { ... }
 */

return [

    'experiments' => [
        // Example: pricing CTA wording test
        'pricing_cta' => [
            'variants' => [
                'A' => 50,  // 50% of traffic
                'B' => 50,  // 50% of traffic
            ],
            // The variant content is defined in Blade, not here.
            // This config only controls traffic allocation.
        ],

        // Example: pricing page layout test (disabled — set to empty to disable)
        // 'pricing_layout' => [
        //     'variants' => [
        //         'A' => 50,  // single-column cards (current)
        //         'B' => 50,  // two-column comparison
        //     ],
        // ],
    ],

];
