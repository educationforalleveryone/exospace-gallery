<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeliverOutboundWebhook;
use App\Jobs\RegenerateImageMedia;
use App\Jobs\VerifyCustomDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the queue/worker reliability invariants that span the config file
 * and the container worker flags. The redis retry_after MUST stay above
 * the worker --timeout: a job whose process is killed after the timeout
 * while its envelope is still reserved would be handed to another worker
 * and executed twice.
 */
class QueueConfigInvariantsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string|null> flag name (no "--") => value, null when no "=value" part
     */
    private function workerFlags(): array
    {
        $script = file_get_contents(base_path('docker-start.sh'));

        preg_match('/artisan queue:work ([^\n]+)\n/', $script, $m);

        $this->assertArrayHasKey(1, $m, 'docker-start.sh must run the queue worker via `artisan queue:work`.');

        $flags = [];
        foreach (explode(' ', trim($m[1])) as $token) {
            if ($token === 'redis' || $token === '') {
                continue;
            }

            if (str_starts_with($token, '--')) {
                [$name, $value] = array_pad(explode('=', substr($token, 2), 2), 2, null);
                $flags[$name] = $value;
            }
        }

        return $flags;
    }

    public function test_redis_retry_after_stays_above_the_worker_timeout(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        $this->assertSame(240, $retryAfter, 'The documented redis retry_after is 240s; change config + worker together.');

        $flags = $this->workerFlags();

        $this->assertArrayHasKey('timeout', $flags, 'Worker must set an explicit --timeout.');
        $workerTimeout = (int) $flags['timeout'];

        $this->assertGreaterThan(
            $workerTimeout,
            $retryAfter,
            "redis retry_after ({$retryAfter}s) must exceed the worker --timeout ({$workerTimeout}s) or timed-out jobs will be double-executed."
        );
    }

    public function test_worker_retries_with_a_backoff(): void
    {
        $flags = $this->workerFlags();

        $this->assertArrayHasKey('tries', $flags, 'Worker must set an explicit --tries.');
        $this->assertArrayHasKey(
            'backoff',
            $flags,
            'Worker must set --backoff so jobs without their own backoff do not retry instantly during provider outages.'
        );
    }

    public function test_redis_connection_dispatches_only_after_database_commit(): void
    {
        $this->assertTrue(
            (bool) config('queue.connections.redis.after_commit'),
            'after_commit on the redis queue connection is the transaction-aware-dispatch guarantee for every queued job.'
        );
    }

    public function test_failed_jobs_are_persisted_to_the_database(): void
    {
        $this->assertSame('database-uuids', config('queue.failed.driver'));
        $this->assertSame('failed_jobs', config('queue.failed.table'));
    }

    public function test_declared_job_timeouts_stay_below_the_redis_retry_after(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        foreach ([
            RegenerateImageMedia::class,
            VerifyCustomDomain::class,
            DeliverOutboundWebhook::class,
        ] as $jobClass) {
            $job = new $jobClass(...$this->jobConstructorArgs($jobClass));

            $this->assertLessThan(
                $retryAfter,
                $job->timeout,
                "{$jobClass} timeout must stay below retry_after ({$retryAfter}s) or a long attempt would be re-delivered while still running."
            );
        }
    }

    public function test_scheduler_bypass_guard_keeps_internal_and_external_schedulers_mutually_exclusive(): void
    {
        $script = file_get_contents(base_path('docker-start.sh'));

        $this->assertStringContainsString(
            'BYPASS_SCHEDULER',
            $script,
            'The in-container scheduler loop must stay gated behind BYPASS_SCHEDULER so it cannot double-run alongside the Coolify scheduled task.'
        );
    }

    private function jobConstructorArgs(string $jobClass): array
    {
        return match ($jobClass) {
            VerifyCustomDomain::class => [1],
            RegenerateImageMedia::class => [1],
            DeliverOutboundWebhook::class => ['https://example.test/hook', '{}', null, 'test'],
            default => throw new \InvalidArgumentException("Add constructor args for {$jobClass}."),
        };
    }
}
