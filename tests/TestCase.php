<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
