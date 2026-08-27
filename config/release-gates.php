<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Testing Control Center — Release Gates
|--------------------------------------------------------------------------
|
| Rules that decide whether the "Release Readiness" dashboard shows
| 🟢 READY TO SHIP or 🔴 NOT READY. Gates are data-driven so you can tune
| strictness without touching code.
|
| Each gate:
|   profile        which test profile the gate evaluates
|   mode  blocking A failing/missing run of this profile BLOCKS release
|         advisory Failures only produce a warning
|   max_age_hours  runs older than this count as stale (missing)
|   require_passed Whether the latest run must be fully green
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Global freshness window
    |--------------------------------------------------------------------------
    |
    | Any gate whose newest qualifying run is older than this many hours is
    | treated as UNPROVEN (blocking by default). Prevents shipping on the
    | strength of last month's green tick.
    |
    */

    'freshness_hours' => (int) env('QA_RELEASE_FRESHNESS_HOURS', 48),

    /*
    |--------------------------------------------------------------------------
    | Environment-to-profile mapping
    |--------------------------------------------------------------------------
    |
    | Release readiness is always evaluated for a specific environment
    | (default: production deploys). Keys below are environment names from
    | config/test-center.php; each lists profiles that must be green within
    | the freshness window before that environment's release is READY.
    |
    */

    'environments' => [

        'production' => [
            'label' => 'Production deploy',
            'gates' => [

                'build' => [
                    'label'       => 'Build & Lint',
                    'profile'     => 'ci_build',          // synthetic gate fed by CI job status ingestion
                    'mode'        => 'blocking',
                    'require_passed' => true,
                    'max_age_hours'  => null,             // build info never goes stale-stale; CI re-pushes constantly
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
                    // Smoke only exists AFTER a deployment; don't block the *decision*
                    // to ship — block *after* deploy confirmation instead. The UI explains.
                    'max_age_hours'  => 6,
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Failure escalation
    |--------------------------------------------------------------------------
    |
    | When a BLOCKING gate fails, the Control Center raises one grouped Slack
    | notification through OperationalAlertService using this severity and
    | de-duplication TTL (seconds) so a flapping pipeline cannot spam you.
    |
    */

    'notification' => [
        'severity'   => 'critical',
        'dedup_ttl'  => (int) env('QA_ALERT_DEDUP_TTL', 1800),
    ],

];
