<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ops\Support\ErrorClassifier;
use PHPUnit\Framework\TestCase;

/**
 * OpsCenter — Iteration 1 — error classification engine.
 *
 * The classifier is the component that turns raw technical strings into
 * operational information (category, severity, likely causes, recommended
 * diagnostics). These tests pin its contract: the brief's flagship example
 * ("SQLSTATE[HY000] [2002] Connection refused" must surface as a CRITICAL
 * DATABASE problem with likely causes) is asserted verbatim.
 */
class OpsErrorClassifierTest extends TestCase
{
    private ErrorClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new ErrorClassifier;
    }

    /**
     * The flagship example from the project brief: a raw DB connection
     * error must become an operational DATABASE event at critical severity.
     */
    public function test_database_connection_refused_is_critical_database(): void
    {
        $result = $this->classifier->classify(
            \Illuminate\Database\QueryException::class,
            'SQLSTATE[HY000] [2002] Connection refused (Connection refused)',
            'error',
        );

        $this->assertSame('DATABASE', $result['category']);
        $this->assertSame('critical', $result['severity']);
        $this->assertSame('Database connection failure', $result['title']);
        $this->assertNotEmpty($result['likely_causes']);
        $this->assertNotEmpty($result['recommended_diagnostics']);
        $this->assertContains('database.connectivity', $result['recommended_diagnostics']);
    }

    public function test_severity_upgrades_from_pattern_floor(): void
    {
        // A connection-refused logged at mere 'warning' must still surface
        // as critical — the pattern floor is the minimum severity.
        $result = $this->classifier->classify(null, 'SQLSTATE[HY000] [2002] Connection refused', 'warning');

        $this->assertSame('critical', $result['severity']);
    }

    public function test_observed_level_wins_when_higher(): void
    {
        // A pattern with an info floor (e.g. 404s) logged at error level
        // keeps the observed severity — never downgraded below the level.
        $result = $this->classifier->classify(
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
            'Route [whatever] not found',
            'error',
        );

        $this->assertSame('error', $result['severity']);
        $this->assertSame('APPLICATION', $result['category']);
    }

    public function test_table_missing_is_a_migration_problem(): void
    {
        $result = $this->classifier->classify(
            \Illuminate\Database\QueryException::class,
            "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'exospace.new_table' doesn't exist",
            'error',
        );

        $this->assertSame('MIGRATION', $result['category']);
        $this->assertSame('critical', $result['severity']);
    }

    public function test_redis_connection_failure(): void
    {
        $result = $this->classifier->classify(null, 'Connection to Redis failed after 3 failures', 'error');

        $this->assertSame('REDIS', $result['category']);
        $this->assertSame('critical', $result['severity']);
    }

    public function test_max_attempts_maps_to_queue(): void
    {
        $result = $this->classifier->classify(
            \Illuminate\Queue\MaxAttemptsExceededException::class,
            'App\\Jobs\\ProcessImage has been attempted too many times.',
            'error',
        );

        $this->assertSame('QUEUE', $result['category']);
    }

    public function test_migration_failure_requires_sqlstate_context(): void
    {
        // The bare word "Migration" must NOT classify as MIGRATION without
        // SQL/syntax/duplicate context (e.g. "Migrating a gallery" is not a
        // migration failure).
        $result = $this->classifier->classify(null, 'Migrating gallery records for user', 'info');

        $this->assertNotSame('MIGRATION', $result['category']);
    }

    public function test_disk_full_is_critical_storage(): void
    {
        $result = $this->classifier->classify(null, 'file_put_contents(): fwrite(): No space left on device', 'error');

        $this->assertSame('STORAGE', $result['category']);
        $this->assertSame('critical', $result['severity']);
    }

    public function test_memory_exhausted(): void
    {
        $result = $this->classifier->classify(null, 'Allowed memory size of 536870912 bytes exhausted (tried to allocate 20480 bytes)', 'critical');

        $this->assertSame('PHP', $result['category']);
        $this->assertSame('critical', $result['severity']);
    }

    public function test_composer_resolution_failure_is_build(): void
    {
        $result = $this->classifier->classify(
            \RuntimeException::class,
            'Your requirements could not be resolved to an installable set of packages.',
            'critical',
        );

        $this->assertSame('BUILD', $result['category']);
        $this->assertSame('critical', $result['severity']);
    }

    public function test_vite_build_failure_is_build(): void
    {
        $result = $this->classifier->classify(null, 'npm ERR! ELIFECYCLE Command failed with exit code 1 during vite build', 'critical');

        $this->assertSame('BUILD', $result['category']);
    }

    public function test_unclassified_error_falls_back_to_unknown(): void
    {
        $result = $this->classifier->classify(\RuntimeException::class, 'Something entirely novel happened', 'error');

        $this->assertSame('UNKNOWN', $result['category']);
        $this->assertSame('error', $result['severity']);
        $this->assertNotSame('', $result['title']);
        $this->assertSame('none', $result['confidence']);
    }

    public function test_level_mapping_from_monolog(): void
    {
        $this->assertSame('critical', ErrorClassifier::levelToSeverity(500));
        $this->assertSame('error', ErrorClassifier::levelToSeverity(400));
        $this->assertSame('warning', ErrorClassifier::levelToSeverity(300));
        $this->assertSame('info', ErrorClassifier::levelToSeverity(200));
    }

    public function test_authentication_exception_is_low_noise(): void
    {
        $result = $this->classifier->classify(
            \Illuminate\Auth\AuthenticationException::class,
            'Unauthenticated.',
            'info',
        );

        $this->assertSame('AUTHENTICATION', $result['category']);
        $this->assertSame('info', $result['severity']);
    }
}
