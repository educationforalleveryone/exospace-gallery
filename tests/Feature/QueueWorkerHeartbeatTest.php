<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\OperationalAlertService;
use App\Services\QueueWorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class QueueWorkerHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.operational_alerts.webhook_url' => null]);
        Cache::forget(QueueWorkerHeartbeat::CACHE_KEY);
    }

    public function test_stamp_records_recent_heartbeat(): void
    {
        QueueWorkerHeartbeat::stamp();

        $age = QueueWorkerHeartbeat::ageSeconds();

        $this->assertNotNull($age);
        $this->assertLessThan(5, $age);
    }

    public function test_missing_heartbeat_alone_does_not_alert(): void
    {
        // A fresh container has no heartbeat yet — that is not evidence of
        // a dead worker, so no alert may fire.
        Log::spy();

        app(OperationalAlertService::class)->checkQueueWorkerHealth();

        Log::shouldNotHaveReceived('critical');
    }

    public function test_stale_worker_heartbeat_raises_critical_alert(): void
    {
        Log::spy();

        Cache::put(QueueWorkerHeartbeat::CACHE_KEY, now()->subMinutes(15)->timestamp, 1800);

        app(OperationalAlertService::class)->checkQueueWorkerHealth();

        Log::shouldHaveReceived('critical')
            ->withArgs(fn ($message) => str_contains($message, 'Queue worker may be down'))
            ->atLeast()
            ->once();
    }

    public function test_fresh_worker_heartbeat_does_not_alert(): void
    {
        Log::spy();

        QueueWorkerHeartbeat::stamp();

        app(OperationalAlertService::class)->checkQueueWorkerHealth();

        Log::shouldNotHaveReceived('critical');
    }

    public function test_database_queue_backlog_still_raises_alert(): void
    {
        Log::spy();

        QueueWorkerHeartbeat::stamp();

        DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => json_encode(['job' => 'test']),
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => now()->subMinutes(15)->timestamp,
            'created_at'   => now()->subMinutes(15)->timestamp,
        ]);

        app(OperationalAlertService::class)->checkQueueWorkerHealth();

        Log::shouldHaveReceived('critical')
            ->withArgs(fn ($message) => str_contains($message, 'Queue worker may be down'))
            ->atLeast()
            ->once();
    }

    public function test_redis_queue_retry_after_exceeds_worker_timeout(): void
    {
        // The worker runs with --timeout=120 (docker-start.sh); a shorter
        // retry_after lets a still-running job be picked up and run twice.
        $this->assertGreaterThan(
            120,
            (int) config('queue.connections.redis.retry_after'),
            'redis queue retry_after must exceed the worker timeout so a running job is never re-assigned.'
        );
    }
}
