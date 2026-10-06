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

    /**
     * Key-order-insensitive strict assertion for decoded JSON values.
     *
     * MySQL re-serialises JSON columns (shortest key first, then alphabetical),
     * so a decoded object rarely has the same key order as the array that was
     * written. assertSame() on arrays is order-sensitive; this canonicalises
     * both sides first (list order is still respected).
     */
    protected function assertSameJson(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertSame(
            json_canonical($expected),
            json_canonical($actual),
            $message
        );
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

            try {
                $pdo = $connection->getPdo();
            } catch (\Throwable) {
                // This run points the sqlite connection at something
                // unconnectable (e.g. a mysql-fidelity pass leaves DB_DATABASE
                // pointing at a mysql database name). The compat functions
                // only matter once a sqlite query actually runs — skip here
                // instead of failing every setUp.
                continue;
            }

            if (! method_exists($pdo, 'sqliteCreateFunction')) {
                continue;
            }

            // Register idempotently; re-registering overwrites harmlessly.
            $pdo->sqliteCreateFunction('CHAR_LENGTH', static fn ($value) => $value === null ? null : mb_strlen((string) $value));
            $pdo->sqliteCreateFunction('CHARACTER_LENGTH', static fn ($value) => $value === null ? null : mb_strlen((string) $value));
        }
    }
}
