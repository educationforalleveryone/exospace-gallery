<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\ArtisanCommandRunner;
use App\Services\JobHeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ITERATION 7 — monitored backup wrapper.
 *
 * The wrapper calls spatie's backup:run / backup:clean through an
 * injectable ArtisanCommandRunner so it is unit-testable without
 * needing mysqldump + writable disk + zip in the sandbox. The
 * behavior under test:
 *
 *   - success → heartbeat stamped, exit 0
 *   - failure → Slack alert (critical for db/files, warning for
 *     clean), no heartbeat stamp, exit 1 (heartbeat monitor =
 *     second net)
 *   - invalid type → exit 1, no underlying call
 *
 * The schedule swap (raw spatie commands → exospace:backup wrapper)
 * is covered by InfrastructureTest::test_a1_backup_schedule_exists.
 */
class MonitoredBackupTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://hooks.slack.example/backup';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutExceptionHandling();
        config(['services.operational_alerts.webhook_url' => self::WEBHOOK]);
        Http::fake();
    }

    private function fakeRunner(int $exitCode): object
    {
        // Extends ArtisanCommandRunner so the anonymous class satisfies
        // the strict type-hint on RunMonitoredBackup::handle() when the
        // container method-injects it.
        return new class ($exitCode) extends ArtisanCommandRunner {
            /** @var list<array{0:string, 1:array}> */
            public array $calls = [];

            public function __construct(private readonly int $exitCode)
            {
                // No parent constructor to call — ArtisanCommandRunner has none.
            }

            public function __invoke(string $command, array $parameters = []): int
            {
                $this->calls[] = [$command, $parameters];
                return $this->exitCode;
            }
        };
    }

    public function test_db_success_stamps_heartbeat(): void
    {
        $fake = $this->fakeRunner(0);
        $this->app->instance(ArtisanCommandRunner::class, $fake);

        $this->artisan('exospace:backup', ['type' => 'db'])
            ->assertExitCode(0)
            ->expectsOutputToContain('heartbeat stamped');

        $this->assertSame(
            [['backup:run', ['--only-db' => true]]],
            $fake->calls,
            'db type must invoke backup:run --only-db',
        );

        $this->assertSame(
            'fresh',
            app(JobHeartbeatService::class)->status('exospace:backup:db'),
        );
    }

    public function test_files_success_stamps_heartbeat(): void
    {
        $fake = $this->fakeRunner(0);
        $this->app->instance(ArtisanCommandRunner::class, $fake);

        $this->artisan('exospace:backup', ['type' => 'files'])->assertExitCode(0);

        $this->assertSame(
            [['backup:run', ['--only-files' => true]]],
            $fake->calls,
            'files type must invoke backup:run --only-files',
        );

        $this->assertSame('fresh', app(JobHeartbeatService::class)->status('exospace:backup:files'));
    }

    public function test_clean_success_stamps_heartbeat(): void
    {
        $fake = $this->fakeRunner(0);
        $this->app->instance(ArtisanCommandRunner::class, $fake);

        $this->artisan('exospace:backup', ['type' => 'clean'])->assertExitCode(0);

        $this->assertSame(
            [['backup:clean', []]],
            $fake->calls,
            'clean type must invoke backup:clean',
        );

        $this->assertSame('fresh', app(JobHeartbeatService::class)->status('exospace:backup:clean'));
    }

    public function test_db_failure_alerts_critical_and_leaves_heartbeat_unstamped(): void
    {
        $fake = $this->fakeRunner(1);
        $this->app->instance(ArtisanCommandRunner::class, $fake);

        Http::fake([self::WEBHOOK => Http::response([], 200)]);

        $this->artisan('exospace:backup', ['type' => 'db'])
            ->assertExitCode(1)
            ->expectsOutputToContain('failed');

        // Critical alert went out.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'slack.example')
                && str_contains((string) $request->body(), 'Daily database backup')
                && str_contains((string) $request->body(), 'critical');
        });

        // No heartbeat stamp — heartbeat monitor becomes the second net.
        $this->assertSame(
            'missing',
            app(JobHeartbeatService::class)->status('exospace:backup:db'),
        );
    }

    public function test_files_failure_alerts_critical(): void
    {
        $fake = $this->fakeRunner(2);
        $this->app->instance(ArtisanCommandRunner::class, $fake);

        Http::fake([self::WEBHOOK => Http::response([], 200)]);

        $this->artisan('exospace:backup', ['type' => 'files'])
            ->assertExitCode(1);

        Http::assertSent(function ($request) {
            return str_contains((string) $request->body(), 'Weekly file backup')
                && str_contains((string) $request->body(), 'critical');
        });
    }

    public function test_clean_failure_alerts_warning_not_critical(): void
    {
        $fake = $this->fakeRunner(1);
        $this->app->instance(ArtisanCommandRunner::class, $fake);

        Http::fake([self::WEBHOOK => Http::response([], 200)]);

        $this->artisan('exospace:backup', ['type' => 'clean'])
            ->assertExitCode(1);

        // Warning severity for clean — disk-usage check is the
        // independent second net for accumulation.
        Http::assertSent(function ($request) {
            return str_contains((string) $request->body(), 'Backup cleanup')
                && str_contains((string) $request->body(), 'warning');
        });
    }

    public function test_invalid_type_fails_without_calling_underlying(): void
    {
        $fake = $this->fakeRunner(0);
        $this->app->instance(ArtisanCommandRunner::class, $fake);

        $this->artisan('exospace:backup', ['type' => 'nonsense'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Unknown backup type');

        $this->assertSame([], $fake->calls, 'invalid type must not call the underlying command');
    }

    public function test_backup_jobs_registered_in_monitored_jobs(): void
    {
        $monitored = JobHeartbeatService::MONITORED_JOBS;

        $this->assertArrayHasKey('exospace:backup:db', $monitored);
        $this->assertArrayHasKey('exospace:backup:files', $monitored);
        $this->assertArrayHasKey('exospace:backup:clean', $monitored);

        // Cadence sanity — daily db/clean get 36h (1 full missed run
        // + jitter headroom); weekly files gets 8 days.
        $this->assertSame(36, $monitored['exospace:backup:db']);
        $this->assertSame(192, $monitored['exospace:backup:files']);
        $this->assertSame(36, $monitored['exospace:backup:clean']);
    }

    public function test_runner_class_is_injectable_via_container(): void
    {
        // The testable seam itself — proves the container resolves
        // the concrete (so the wrapper's app(ArtisanCommandRunner)
        // resolves, and tests can $this->app->instance(...) it).
        $this->assertInstanceOf(
            ArtisanCommandRunner::class,
            $this->app->make(ArtisanCommandRunner::class),
        );
    }
}
