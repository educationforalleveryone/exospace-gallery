<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use App\Ops\Services\PlatformSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 1 — Coolify platform sync.
 *
 * The sync is what makes the control plane platform-wide. These tests pin
 * its upsert/idempotency contract and its degradation contract (API down
 * must never crash the scheduler chain — it becomes an observable event).
 */
class OpsPlatformSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'services.coolify.api_token' => 'test-token',
            'services.coolify.api_base_url' => 'http://coolify.test',
            'services.coolify.application_uuid' => 'app-uuid-1',
            'ops.self.coolify_uuid' => 'app-uuid-1',
            'ops.platform_sync.enabled' => true,
        ]);
    }

    /**
     * A STATEFUL fake for a single application whose status the test
     * mutates between syncs. (Http::fake() stubs are append-only — the
     * first matching pattern wins — so a second Http::fake() would NOT
     * replace the first; transitions must be simulated within one fake.)
     *
     * @return callable(string): void mutator: set the status the Coolify
     *                                API will report on the next sync.
     */
    private function statefulFake(string $uuid, string $initialStatus): callable
    {
        $status = $initialStatus;

        Http::fake(function ($request) use (&$status, $uuid) {
            $path = parse_url((string) $request->url(), PHP_URL_PATH);

            return match ($path) {
                '/api/v1/applications' => Http::response([
                    ['uuid' => $uuid, 'name' => 'project-b', 'status' => $status],
                ]),
                "/api/v1/applications/{$uuid}/deployments" => Http::response([]),
                default => Http::response([]),
            };
        });

        return function (string $newStatus) use (&$status): void {
            $status = $newStatus;
        };
    }

    private function fakeCoolify(array $applications = [], array $deployments = [], array $servers = []): void
    {
        Http::fake([
            'http://coolify.test/api/v1/servers' => Http::response($servers),
            'http://coolify.test/api/v1/applications' => Http::response($applications),
            'http://coolify.test/api/v1/databases' => Http::response([]),
            'http://coolify.test/api/v1/services' => Http::response([]),
            'http://coolify.test/api/v1/applications/*/deployments' => Http::response($deployments),
            '*' => Http::response([], 200),
        ]);
    }

    public function test_sync_upserts_applications(): void
    {
        $this->fakeCoolify(applications: [
            ['uuid' => 'app-uuid-1', 'name' => 'exospace', 'status' => 'running:healthy'],
            ['uuid' => 'app-uuid-2', 'name' => 'project-b', 'status' => 'running:healthy', 'fqdn' => 'https://project-b.example.com'],
            ['uuid' => 'db-uuid-1',  'name' => 'exospace-db', 'status' => 'running:healthy'],
        ]);

        $result = app(PlatformSyncService::class)->sync();

        $this->assertTrue($result['api_ok']);
        // app-uuid-1 merges into the self row → 3 total rows.
        $this->assertSame(3, OpsApplication::count());

        $projectB = OpsApplication::where('provider_uuid', 'app-uuid-2')->first();
        $this->assertNotNull($projectB);
        $this->assertSame('running', $projectB->health);
        // fqdn is extracted as the display URL (primary = first of the list).
        $this->assertSame('https://project-b.example.com', $projectB->url);
    }

    public function test_self_application_is_correlated_with_its_coolify_row(): void
    {
        // The self row is created by the ingestor before sync runs (local
        // errors arrive first in real life too).
        app(\App\Ops\Services\OpsEventIngestor::class)::selfApplication();

        $this->fakeCoolify(applications: [
            ['uuid' => 'app-uuid-1', 'name' => 'exospace', 'status' => 'running:healthy'],
        ]);

        app(PlatformSyncService::class)->sync();

        // Exactly ONE row is the self application (merged, not duplicated).
        $this->assertSame(1, OpsApplication::where('is_self', true)->count());
        $self = OpsApplication::where('is_self', true)->first();
        $this->assertSame('app-uuid-1', $self->provider_uuid);
        $this->assertSame('running', $self->health);
    }

    public function test_sync_is_idempotent(): void
    {
        $this->fakeCoolify(applications: [
            ['uuid' => 'app-uuid-2', 'name' => 'project-b', 'status' => 'running:healthy'],
        ]);

        app(PlatformSyncService::class)->sync();
        app(PlatformSyncService::class)->sync();

        $this->assertSame(1, OpsApplication::where('provider_uuid', 'app-uuid-2')->count());
    }

    public function test_status_degradation_creates_an_event(): void
    {
        $setStatus = $this->statefulFake('app-uuid-2', 'running:healthy');

        app(PlatformSyncService::class)->sync();
        $this->assertSame(0, OpsEvent::count());

        // Next sync: the container has exited.
        $setStatus('exited:1');
        app(PlatformSyncService::class)->sync();

        $this->assertSame(1, OpsEvent::count());
        $event = OpsEvent::first();
        $this->assertSame('CONTAINER', $event->category);
        $this->assertSame('application:app-uuid-2', $event->application->slug);

        // A third identical sync must NOT re-page (dedup by fingerprint).
        app(PlatformSyncService::class)->sync();
        $this->assertSame(1, OpsEvent::count());
    }

    public function test_recovery_does_not_create_an_event(): void
    {
        $setStatus = $this->statefulFake('app-uuid-2', 'exited:1');

        app(PlatformSyncService::class)->sync();
        $this->assertSame(1, OpsEvent::count()); // the outage event

        // Recovers.
        $setStatus('running:healthy');
        app(PlatformSyncService::class)->sync();

        $this->assertSame(1, OpsEvent::count()); // no new event
        $this->assertSame('running', OpsApplication::where('provider_uuid', 'app-uuid-2')->first()->health);
    }

    public function test_failed_deployment_becomes_deployment_event(): void
    {
        $this->fakeCoolify(
            applications: [['uuid' => 'app-uuid-2', 'name' => 'project-b', 'status' => 'running:healthy']],
            deployments: [
                ['deployment_uuid' => 'dep-1', 'status' => 'failed', 'commit' => 'abc1234', 'duration' => 45],
                ['deployment_uuid' => 'dep-2', 'status' => 'finished', 'commit' => 'def5678'],
            ],
        );

        $result = app(PlatformSyncService::class)->sync();

        $this->assertSame(1, $result['events_created']);

        $event = OpsEvent::where('category', 'DEPLOYMENT')->first();
        $this->assertNotNull($event);
        $this->assertSame('critical', $event->severity);
        $this->assertSame('abc1234', data_get($event->context, 'commit'));
        $this->assertSame('45s', data_get($event->context, 'duration'));
    }

    public function test_api_unreachable_records_rate_limited_event(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $service = app(PlatformSyncService::class);
        $service->recordApiUnreachable();

        $this->assertSame(1, OpsEvent::where('category', 'INFRASTRUCTURE')->count());

        // Immediately again → suppressed (cooldown).
        $service->recordApiUnreachable();
        $this->assertSame(1, OpsEvent::where('category', 'INFRASTRUCTURE')->count());
    }

    public function test_command_exits_successfully_when_api_down(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $this->artisan('ops:sync-platform')->assertExitCode(0);
    }

    public function test_unreachable_endpoint_degrades_gracefully(): void
    {
        // Deployments endpoint 404s (older Coolify version) — the rest of
        // the sync must still work.
        Http::fake([
            'http://coolify.test/api/v1/applications' => Http::response([
                ['uuid' => 'app-uuid-2', 'name' => 'project-b', 'status' => 'running:healthy'],
            ]),
            'http://coolify.test/api/v1/applications/*/deployments' => Http::response(null, 404),
            'http://coolify.test/api/v1/servers' => Http::response([]),
            'http://coolify.test/api/v1/databases' => Http::response([]),
            'http://coolify.test/api/v1/services' => Http::response([]),
        ]);

        $result = app(PlatformSyncService::class)->sync();

        $this->assertTrue($result['api_ok']);
        $this->assertSame(1, OpsApplication::where('provider_uuid', 'app-uuid-2')->count());
    }
}
