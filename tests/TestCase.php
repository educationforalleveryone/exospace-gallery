<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /*
     | QA-Control-Center (Iteration 1): engine-portability shim.
     |
     | The SEO audit/quality-gate queries (app/Services/Seo/SeoAuditService)
     | intentionally use MySQL's CHAR_LENGTH() for byte-accurate description
     | thresholds in production. SQLite — which the suite defaults to for
     | speed (:memory:) — does not ship that function, so any test that hit
     | the artwork gate 500'd with "no such function: CHAR_LENGTH".
     |
     | Rather than weakening production SQL, we register the function on
     | SQLite connections only. MySQL behaviour is unchanged everywhere.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerSqliteCompatibilityFunctions();
    }

    private function registerSqliteCompatibilityFunctions(): void
    {
        foreach (['mysql', 'sqlite', 'testing'] as $connectionName) {
            try {
                $connection = \DB::connection($connectionName);
            } catch (\Throwable) {
                continue; // connection not configured (e.g. mysql stubs without server)
            }

            if ($connection->getDriverName() !== 'sqlite') {
                continue;
            }

            $pdo = $connection->getPdo();

            if (! method_exists($pdo, 'sqliteCreateFunction')) {
                continue;
            }

            // Register idempotently; re-registering overwrites harmlessly.
            $pdo->sqliteCreateFunction('CHAR_LENGTH', static fn ($value) => $value === null ? null : mb_strlen((string) $value));
            $pdo->sqliteCreateFunction('CHARACTER_LENGTH', static fn ($value) => $value === null ? null : mb_strlen((string) $value));
        }
    }
}
