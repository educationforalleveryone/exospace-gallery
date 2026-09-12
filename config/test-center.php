<?php

declare(strict_types=1);

return [

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

    'ingest_token' => env('QA_INGEST_TOKEN'),

    // Maximum accepted JUnit artifact size in kilobytes.
    'max_artifact_kb' => env('QA_MAX_ARTIFACT_KB', 20480),

    'mysql_test' => [
        'host'     => env('TEST_MYSQL_HOST'),
        'port'     => (int) env('TEST_MYSQL_PORT', 3306),
        'database' => env('TEST_MYSQL_DATABASE', 'exospace_test'),
        'username' => env('TEST_MYSQL_USERNAME', 'root'),
        'password' => env('TEST_MYSQL_PASSWORD'),
    ],

    'lock_seconds'    => (int) env('QA_LOCK_SECONDS', 3600),
    'timeout_seconds' => (int) env('QA_TIMEOUT_SECONDS', 1800),
    'phpunit_binary'  => env('QA_PHPUNIT_BINARY', 'vendor/bin/phpunit'),

    // Directory (relative to storage_path) where artifacts are stored.
    'artifact_disk' => 'control-center',

    'github_repo' => env('GITHUB_REPO'),

    'admin_emails' => array_filter(array_map('trim', explode(',', (string) env('CONTROL_CENTER_ADMINS', '')))),

];
