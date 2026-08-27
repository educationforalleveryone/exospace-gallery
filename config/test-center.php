<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Testing Control Center — core runtime configuration
|--------------------------------------------------------------------------
|
| This config defines HOW and WHERE the Exospace Testing Control Center is
| allowed to execute validation workloads. It encodes the production-safety
| model: which environments may run full test suites, which are limited to
| safe read-only diagnostics, and how run results are ingested.
|
| Profiles themselves (what gets tested) live in config/test-profiles.php.
| Release gate rules live in config/release-gates.php.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Execution Environments
    |--------------------------------------------------------------------------
    |
    | A target environment describes a place where tests/checks can run.
    | `allow_suite_execution` gates PHPUnit-style suites (they rebuild the
    | database they run against); `allow_destructive` additionally allows
    | migration rolls backs, seed wipes, cache flushes, etc. Production
    | NEVER receives either — only `prod_safe_read` profiles are permitted.
    |
    */

    'environments' => [
        'local' => [
            'label'                  => 'Local',
            'description'            => 'Developer machine / isolated sandbox. Full suites, database rebuilds and destructive maintenance are allowed.',
            'allow_suite_execution'  => true,
            'allow_destructive'      => true,
            'badge'                  => 'green',
        ],
        'ci' => [
            'label'                  => 'CI Runner',
            'description'            => 'GitHub Actions runner with ephemeral service containers (SQLite / MySQL 8). Fresh state every run.',
            'allow_suite_execution'  => true,
            'allow_destructive'      => true,
            'badge'                  => 'blue',
        ],
        'staging' => [
            'label'                  => 'Staging',
            'description'            => 'Shared pre-production deployment on Coolify. Read-only checks and smoke tests by default; suite execution must be explicitly enabled because it mutates staging data.',
            'allow_suite_execution'  => (bool) env('TEST_CENTER_STAGING_SUITES', false),
            'allow_destructive'      => false,
            'base_url'               => env('STAGING_URL'),
            'badge'                  => 'amber',
        ],
        'production' => [
            'label'                  => 'Production',
            'description'            => 'exospace.gallery live environment. Suite execution is permanently blocked. Only explicitly prod-safe read-only health, connectivity and smoke checks are permitted.',
            'allow_suite_execution'  => false,
            'allow_destructive'      => false,
            'base_url'               => env('APP_URL'),
            'badge'                  => 'red',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Safety classes
    |--------------------------------------------------------------------------
    |
    | Every profile declares one of these safety classes:
    |
    |   test-only       May only ever run against local/ci. Mutates its own
    |                   throwaway database (RefreshDatabase) freely.
    |   staging-safe    May mutate a shared staging environment's data; runs
    |                   no destructive schema operations.
    |   prod-safe-read  Strictly read-only HTTP/network/in-process probes.
    |                   The ONLY class permitted to target production.
    |
    */

    'safety_classes' => [
        'test-only' => [
            'allowed_environments' => ['local', 'ci', 'staging'], // staging only when TEST_CENTER_STAGING_SUITES=true
            'targets_production'   => false,
        ],
        'staging-safe' => [
            'allowed_environments' => ['local', 'ci', 'staging'],
            'targets_production'   => false,
        ],
        'prod-safe-read' => [
            'allowed_environments' => ['local', 'ci', 'staging', 'production'],
            'targets_production'   => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Result ingestion API
    |--------------------------------------------------------------------------
    |
    | CI runners POST JUnit XML artifacts to the Control Center after every
    | profile run so history, flaky detection and release readiness have data.
    |
    | QA_INGEST_TOKEN: requests must send this value in the X-QA-Token header.
    | When it is unset the ingest endpoint deliberately reports 404
    | (same fail-closed convention as /api/ops/ingest and /metrics).
    |
    */

    'ingest_token' => env('QA_INGEST_TOKEN'),

    // Maximum accepted JUnit artifact size in kilobytes.
    'max_artifact_kb' => env('QA_MAX_ARTIFACT_KB', 20480),

    /*
    |--------------------------------------------------------------------------
    | Database targets for suite execution
    |--------------------------------------------------------------------------
    |
    | quick_check prefers SQLite (:memory:, zero I/O). Fidelity profiles prefer
    | MySQL because several application behaviours differ between engines
    | (strict mode, collation, partitioning — see MigrateFreshTest notes).
    |
    | When a profile asks for mysql but the runner cannot reach any server,
    | qa:run BLOCKS with an actionable message instead of producing hundreds
    | of meaningless connection failures.
    |
    */

    'mysql_test' => [
        'host'     => env('TEST_MYSQL_HOST'),
        'port'     => (int) env('TEST_MYSQL_PORT', 3306),
        'database' => env('TEST_MYSQL_DATABASE', 'exospace_test'),
        'username' => env('TEST_MYSQL_USERNAME', 'root'),
        'password' => env('TEST_MYSQL_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Runner behaviour
    |--------------------------------------------------------------------------
    |
    | lock_seconds: mutex TTL while a profile executes (prevents incompatible
    | concurrent profiles stomping each other). phpunit_binary + timeout_secs
    | control the subprocess used for suite strategies.
    |
    */

    'lock_seconds'    => (int) env('QA_LOCK_SECONDS', 3600),
    'timeout_seconds' => (int) env('QA_TIMEOUT_SECONDS', 1800),
    'phpunit_binary'  => env('QA_PHPUNIT_BINARY', 'vendor/bin/phpunit'),

    // Directory (relative to storage_path) where artifacts are stored.
    'artifact_disk' => 'control-center',

    // "org/repo" used by the dashboard to deep-link GitHub Actions profile
    // dispatches when local execution is not possible (production etc.).
    'github_repo' => env('GITHUB_REPO'),

    /*
    |--------------------------------------------------------------------------
    | Control Center access (dashboard ships in iteration 2/3)
    |--------------------------------------------------------------------------
    |
    | CONTROL_CENTER_ADMINS holds comma-separated e-mail addresses of people
    | allowed to open /control-center or trigger runs from it. Leave empty to
    | keep the whole section disabled (fail-closed 404).
    |
    */

    'admin_emails' => array_filter(array_map('trim', explode(',', (string) env('CONTROL_CENTER_ADMINS', '')))),

];
