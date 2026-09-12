<?php

declare(strict_types=1);

return [

    'freshness_hours' => (int) env('QA_RELEASE_FRESHNESS_HOURS', 48),

    'environments' => [

        'production' => [
            'label' => 'Production deploy',
            'gates' => [

                'build' => [
                    'label'       => 'Build & Lint',
                    'profile'     => 'ci_build',
                    'mode'        => 'blocking',
                    'require_passed' => false,
                    'max_age_hours'  => null,             // never expires: CI re-proves on every push
                ],

                'tests' => [
                    'label'       => 'Pre-Release suite',
                    'profile'     => 'pre_release',
                    'mode'        => 'blocking',
                    'require_passed' => true,
                ],

                'security' => [
                    'label'       => 'Security suite',
                    'profile'     => 'security',
                    'mode'        => 'blocking',
                    'require_passed' => true,
                ],

                'billing' => [
                    'label'       => 'Billing & webhooks',
                    'profile'     => 'billing',
                    'mode'        => 'blocking',
                    'require_passed' => true,
                ],

                'seo' => [
                    'label'       => 'SEO validation',
                    'profile'     => 'seo',
                    'mode'        => 'advisory',
                    'require_passed' => true,
                ],

                'database' => [
                    'label'       => 'Database fidelity',
                    'profile'     => 'database',
                    'mode'        => 'advisory',
                    'require_passed' => true,
                ],

                'smoke' => [
                    'label'       => 'Post-deploy smoke',
                    'profile'     => 'smoke',
                    'mode'        => 'blocking',
                    'require_passed' => true,
                    'max_age_hours'  => 6,
                ],
            ],
        ],
    ],

    'notification' => [
        'severity'   => 'critical',
        'dedup_ttl'  => (int) env('QA_ALERT_DEDUP_TTL', 1800),
    ],

];
