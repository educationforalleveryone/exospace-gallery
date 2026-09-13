<?php

declare(strict_types=1);

return [

    'groups' => [

        'unit' => [
            'label'       => 'Unit',
            'description' => 'Isolated classes and pure functions (diagnostic registry, error classifier, health score, log redactor).',
            'paths'       => ['tests/Unit'],
            'danger'      => 'test-only',
        ],

        'authentication' => [
            'label'       => 'Authentication',
            'description' => 'Login, registration, e-mail verification, password reset/update/confirm flows.',
            'paths'       => ['tests/Feature/Auth'],
            'danger'      => 'test-only',
        ],

        'authorization' => [
            'label'       => 'Authorization & Access Control',
            'description' => 'Gallery/team ownership boundaries, ops RBAC tiers (viewer/operator), MFA-gated surfaces.',
            'paths'       => [
                'tests/Feature/GalleryAuthorizationTest.php',
                'tests/Feature/PublicAccessControlTest.php',
                'tests/Feature/OpsAccessControlTest.php',
                'tests/Feature/OpsOperatorAccessTest.php',
                'tests/Feature/OpsDashboardAccessTest.php',
            ],
            'danger'      => 'test-only',
        ],

        'security' => [
            'label'       => 'Security Hardening',
            'description' => 'OAuth takeover resistance, MFA replay protection, session fixation, rate limits, host-header trust, CSP, GDPR anonymization, unsubscribe signature enforcement.',
            'paths'       => [
                'tests/Feature/OAuthSecurityTest.php',
                'tests/Feature/OAuthAndPasswordSecurityTest.php',
                'tests/Feature/MfaReplayProtectionTest.php',
                'tests/Feature/SecurityHardeningTest.php',
                'tests/Feature/SecurityAccessControlTest.php',
                'tests/Feature/SignedBuyLinkAndTrialFraudTest.php',
                'tests/Feature/GdprPiiAnonymizationTest.php',
                'tests/Feature/UserDeletionGdprTest.php',
                'tests/Feature/MarketingConsentTest.php',
                'tests/Feature/Rfc8058UnsubscribeTest.php',
                'tests/Feature/WebhookSecurityTest.php',
                'tests/Feature/TrustedProxiesTest.php',
                'tests/Feature/InvoicePdfAndSessionFixationTest.php',
                'tests/Feature/LastLoginTrackingTest.php',
            ],
            'danger'      => 'test-only',
        ],

        'billing' => [
            'label'       => 'Billing & Payments',
            'description' => '2Checkout IPN pipeline, MD5+HMAC verification, entitlement grants, refunds/chargebacks, invoices + VAT, cancellation/renewal/reconciliation, exports.',
            'paths'       => [
                'tests/Feature/BillingAndExportTest.php',
                'tests/Feature/BillingCancelAndRenewalTest.php',
                'tests/Feature/BillingExportTest.php',
                'tests/Feature/ScheduledBillingExportTest.php',
                'tests/Feature/DigestRecipientManagementTest.php',
                'tests/Feature/SubscriptionReconciliationTest.php',
                'tests/Feature/TaxComplianceTest.php',
                'tests/Feature/InvoicePdfAndSessionFixationTest.php',
                'tests/Feature/PlanExpiryTeamTest.php',
                'tests/Feature/PublishWorkflowTest.php', // plan gating lives here too
            ],
            'danger'      => 'test-only',
        ],

        'webhooks' => [
            'label'       => 'Webhooks (inbound + outbound)',
            'description' => '2Checkout ingress idempotency, delivery ledger, retries/backoff, replay tooling, outbound fan-out signatures, subscription management.',
            'paths'       => [
                'tests/Feature/WebhookBillingTest.php',
                'tests/Feature/WebhookDeliveryHistoryTest.php',
                'tests/Feature/WebhookDeliveryLedgerTest.php',
                'tests/Feature/WebhookDeliveryManagementUiTest.php',
                'tests/Feature/WebhookLedgerAlertTest.php',
                'tests/Feature/WebhookLedgerAndReplayTest.php',
                'tests/Feature/WebhookSubscriptionDispatchTest.php',
                'tests/Feature/WebhookSubscriptionManagementTest.php',
                'tests/Feature/WebhookSubscriptionModelTest.php',
                'tests/Feature/PruneWebhookDeliveriesTest.php',
            ],
            'danger'      => 'test-only',
        ],

        'seo' => [
            'label'       => 'SEO Engine',
            'description' => 'Metadata layer, canonicalization, structured data (JSON-LD), sitemap groups/warming/caching, robots, redirects, internal linking, entity pages, acquisition capture.',
            'paths'       => [
                'tests/Feature/SeoFoundationTest.php',
                'tests/Feature/SeoEntityPagesTest.php',
                'tests/Feature/SeoPagesTest.php',
                'tests/Feature/SeoSchemaAndLinkingTest.php',
                'tests/Feature/SeoSitemapSystemTest.php',
                'tests/Feature/SeoImprovementsTest.php',
                'tests/Feature/SeoAdminToolingTest.php',
                'tests/Feature/SeoMeasurementAndRegressionTest.php',
                'tests/Feature/SitemapEventsGroupTest.php',
                'tests/Feature/SitemapWarmTest.php',
            ],
            'danger'      => 'test-only',
        ],

        'galleries_media' => [
            'label'       => 'Galleries, Artists, Artwork & Media',
            'description' => 'Core domain behaviour: publish workflow, PIN protection, artwork metadata, media library, view-count pipeline, quota enforcement.',
            'paths'       => [
                'tests/Feature/PublishWorkflowTest.php',
                'tests/Feature/PublicAccessControlTest.php',
                'tests/Feature/GalleryViewIncrementJobTest.php',
                'tests/Feature/QueryPerformanceOptimizationTest.php',
                'tests/Feature/UserDeletionGdprTest.php',
            ],
            'danger'      => 'test-only',
        ],

        'analytics' => [
            'label'       => 'Analytics, Onboarding & Retention',
            'description' => 'Cohort retention matrices, onboarding funnel metrics/snapshots, trend anomaly detection, retention alerts.',
            'paths'       => [
                'tests/Feature/CohortRetentionHistoryTest.php',
                'tests/Feature/FunnelStageTrendTest.php',
                'tests/Feature/OnboardingMetricsTest.php',
                'tests/Feature/OnboardingSnapshotTrendTest.php',
                'tests/Feature/TrendAnomalyTest.php',
            ],
            'danger'      => 'test-only',
        ],

        'operations' => [
            'label'       => 'Operations & Infrastructure',
            'description' => 'OpsCenter control plane (events/incidents/diagnostics/digests/access), monitored backups, job heartbeats, scheduler contracts, metrics endpoints, migrations integrity.',
            'paths'       => [
                'tests/Feature/Ops*Test.php',
                'tests/Feature/InfrastructureTest.php',
                'tests/Feature/JobHeartbeatTest.php',
                'tests/Feature/MonitoredBackupTest.php',
                'tests/Feature/MasterControlBackupTileTest.php',
                'tests/Feature/MigrateFreshTest.php',
                'tests/Feature/DatabaseIntegrityTest.php',
                'tests/Unit/Ops*.php',
            ],
            'danger'      => 'test-only',
        ],

        'email' => [
            'label'       => 'Email Lifecycle',
            'description' => 'Mailable coverage, template contracts, dispatch wiring, unsubscribe headers.',
            'paths'       => [
                'tests/Feature/MailableCoverageTest.php',
                'tests/Feature/EmailTemplateTest.php',
                'tests/Feature/EmailDispatchTest.php',
            ],
            'danger'      => 'test-only',
        ],

        'regression_locks' => [
            'label'       => 'Regression Locks',
            'description' => 'Cross-cutting regression pins (CSP doc locks, scheduler registrations, API token abilities…).',
            'paths'       => [
'tests/Feature/ProrationConfigAndUiComponentsTest.php',
                'tests/Feature/AuditLoggingAndAffiliateDashboardTest.php',
                'tests/Feature/PiiAnonymizationCommandsTest.php',
                'tests/Feature/OperationalHealthChecksTest.php',
                'tests/Feature/AlertDeduplicationTest.php',
                'tests/Feature/ApiTokenHashingSecurityTest.php',
                'tests/Feature/MultiDiskBackupConfigTest.php',
                'tests/Feature/AlertWebhookRoutingTest.php',
                'tests/Feature/MetricsEndpointFormatTest.php',
                'tests/Feature/CspSecurityHeaderRegressionTest.php',
                'tests/Feature/FrontendQueueAndApiTest.php',
                'tests/Feature/AccessibilityAndLayoutTest.php',
            ],
            'danger'      => 'test-only',
        ],

        'frontend_api' => [
            'label'       => 'Frontend & Public API',
            'description' => 'Public surface smoke, Sanctum token abilities, gallery API responses.',
            'paths'       => [
                'tests/Feature/HomepageSmokeTest.php',
                'tests/Feature/FrontendQueueAndApiTest.php',
            ],
            'danger'      => 'test-only',
        ],

    ],

    'profiles' => [

        'quick_check' => [
            'label'             => 'Quick Check',
            'icon'              => '⚡',
            'color'             => 'sky',
            'description'       => 'Fast signal for day-to-day development: unit tests plus authentication, authorization and security-boundary suites.',
            'safety'            => 'test-only',
            'strategy'          => 'phpunit',
            'groups'            => ['unit', 'authentication', 'authorization'],
            'database'          => 'sqlite',
            'conflicts_with'    => ['full_regression', 'pre_release'],
            'estimated_minutes' => 4,
            'artifacts'         => ['junit'],
        ],

        'pre_release' => [
            'label'             => 'Pre-Release',
            'icon'              => '🚦',
            'color'             => 'violet',
            'description'       => 'The recommended gate before shipping: the full Feature + Unit suites (every profile group), ideally against MySQL for engine fidelity.',
            'safety'            => 'test-only',
            'strategy'          => 'phpunit',
            'groups'            => '*',          // every group below Browser (Dusk excluded by design)
            'exclude_paths'     => ['tests/Browser'],
            'database'          => 'mysql',      // auto-falls back to sqlite with an explicit warning when mysql unreachable
            'conflicts_with'    => ['full_regression', 'quick_check'],
            'estimated_minutes' => 25,
            'artifacts'         => ['junit'],
        ],

        'full_regression' => [
            'label'             => 'Full Regression',
            'icon'              => '🔁',
            'color'             => 'indigo',
            'description'       => 'Everything appropriate for deep validation — identical to Pre-Release plus explicit re-runs under SQLite to catch engine-specific drift.',
            'safety'            => 'test-only',
            'strategy'          => 'phpunit',
            'groups'            => '*',
            'exclude_paths'     => ['tests/Browser'],
            'database'          => 'mysql',
            'extra_passes'      => [             // second pass config: key=value env overrides
                ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', '__label_suffix' => '(sqlite)'],
            ],
            'conflicts_with'    => ['pre_release', 'quick_check'],
            'estimated_minutes' => 45,
            'artifacts'         => ['junit'],
        ],

        'security' => [
            'label'             => 'Security',
            'icon'              => '🛡️',
            'color'             => 'rose',
            'description'       => 'Defensive validation: OAuth takeover, MFA replay, session fixation, IDOR/ownership matrix, webhook auth, signed links, CSRF exemptions, PII handling.',
            'safety'            => 'test-only',
            'strategy'          => 'phpunit',
            'groups'            => ['security', 'authorization', 'webhooks'],
            'database'          => 'sqlite',
            'conflicts_with'    => [],
            'estimated_minutes' => 8,
            'artifacts'         => ['junit'],
        ],

        'billing' => [
            'label'             => 'Billing',
            'icon'              => '💳',
            'color'             => 'emerald',
            'description'       => '2Checkout signature verification, entitlement lifecycle, refunds/chargebacks, VAT/invoices, reconciliation and expiry enforcement.',
            'safety'            => 'test-only',
            'strategy'          => 'phpunit',
            'groups'            => ['billing', 'webhooks'],
            'database'          => 'sqlite',
            'conflicts_with'    => [],
            'estimated_minutes' => 9,
            'artifacts'         => ['junit'],
        ],

        'seo' => [
            'label'             => 'SEO',
            'icon'              => '🔎',
            'color'             => 'cyan',
            'description'       => 'Full SEO validation: metadata layer, canonical rules, JSON-LD graphs, sitemap groups + warming, robots, redirects, internal linking, CMS pages.',
            'safety'            => 'test-only',
            'strategy'          => 'phpunit',
            'groups'            => ['seo'],
            'database'          => 'sqlite',
            'conflicts_with'    => [],
            'estimated_minutes' => 6,
            'artifacts'         => ['junit'],
        ],

        'database' => [
            'label'             => 'Database',
            'icon'              => '🗄️',
            'color'             => 'amber',
            'description'       => 'Schema fidelity: migrate:fresh completeness, rollback/down() viability, constraints, soft deletes, partition columns. Requires MySQL (SQLite hides strict-mode/partition behaviour).',
            'safety'            => 'test-only',
            'strategy'          => 'phpunit',
            'groups'            => [],           // paths take precedence below
            'paths'             => [
                'tests/Feature/MigrateFreshTest.php',
                'tests/Feature/DatabaseIntegrityTest.php',
                'tests/Feature/WebhookSubscriptionModelTest.php',
            ],
            'database'          => 'mysql-required',
            'conflicts_with'    => [],
            'estimated_minutes' => 5,
            'artifacts'         => ['junit'],
        ],

        'smoke' => [
            'label'             => 'Post-Deploy Smoke',
            'icon'              => '💨',
            'color'             => 'orange',
            'description'       => 'Safe read-only HTTP verification that a deployed build actually serves traffic: reachability, login/register pages, health endpoint, sitemap/robots, public assets.',
            'safety'            => 'prod-safe-read',
            'strategy'          => 'http-smoke',
            'target_environments' => ['staging', 'production'],
            'database'          => null,
            'conflicts_with'    => [],
            'estimated_minutes' => 1,
            'artifacts'         => ['json'],
        ],

        'production_health' => [
            'label'             => 'Production Health',
            'icon'              => '🩺',
            'color'             => 'teal',
            'description'       => 'In-process read-only diagnostics: app booted, DB SELECT 1, Redis ping, cache round-trip, queue table depth, storage writability, scheduler heartbeat age, disk usage.',
            'safety'            => 'prod-safe-read',
            'strategy'          => 'in-process-checks',
            'target_environments' => ['local', 'staging', 'production'],
            'database'          => null,
            'conflicts_with'    => [],
            'estimated_minutes' => 1,
            'artifacts'         => ['json'],
        ],

    ],

];
