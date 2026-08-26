<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Transaction;
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

    // ── ITERATION 8: audit-target fix + spatie stdout capture ───────

    public function test_backup_failure_writes_audit_row_targeting_newest_transaction(): void
    {
        // ITERATION 8 FIX (audit-finding C-1): the previous
        // implementation read config('exospace.system_audit_email',
        // 'system@exospace.gallery') and looked up that User row —
        // but no config/exospace.php exists and the fallback email
        // isn't seeded, so the audit row NEVER wrote on a default
        // install. The fix mirrors SendBillingExport's convention of
        // targeting Transaction::orderByDesc('id')->first() (a real
        // row, with an empty-install skip path).
        $fake = $this->fakeRunner(1);
        $this->app->instance(ArtisanCommandRunner::class, $fake);

        Http::fake([self::WEBHOOK => Http::response([], 200)]);

        // Seed a Transaction so the audit row has a target.
        $tx = Transaction::factory()->create();

        $this->artisan('exospace:backup', ['type' => 'db'])
            ->assertExitCode(1);

        $audit = AdminAuditLog::where('action', 'backup.failed')->latest('id')->first();
        $this->assertNotNull($audit, 'backup.failed audit row must be written when transactions exist');
        $this->assertSame($tx->id, $audit->target_id, 'audit target is the newest transaction');
        $this->assertSame('db', $audit->payload['type'] ?? null);
        $this->assertSame(1, $audit->payload['exit_code'] ?? null);
    }

    public function test_backup_failure_skips_audit_row_on_empty_install(): void
    {
        // No transactions on a fresh install → no real row to target.
        // The audit row is skipped (the absence is explainable in
        // the laravel.log) — same convention as SendBillingExport.
        $fake = $this->fakeRunner(1);
        $this->app->instance(ArtisanCommandRunner::class, $fake);

        Http::fake([self::WEBHOOK => Http::response([], 200)]);

        $this->artisan('exospace:backup', ['type' => 'db'])
            ->assertExitCode(1);

        $this->assertSame(
            0,
            AdminAuditLog::where('action', 'backup.failed')->count(),
            'no audit row on a fresh install with no transactions (no PII to attribute)',
        );
    }

    public function test_spatie_diagnostic_appended_to_alert_message(): void
    {
        // ITERATION 8 FIX (audit-finding C-2): the Slack alert copy
        // previously said "exited with code N. Check logs." The
        // operator had to log in and tail the scheduler log to see
        // WHY. The ArtisanCommandRunner now captures the underlying
        // command's stdout via the $outputBuffer parameter, and the
        // wrapper appends the last ~300 chars of it to the alert.
        $fake = new class (1, 'mysqldump: command not found — aborting after 3 retries') extends ArtisanCommandRunner {
            public function __construct(
                private readonly int $exitCode,
                private readonly string $output,
            ) {
                // No parent constructor to call — ArtisanCommandRunner has none.
            }

            public function __invoke(string $command, array $parameters = []): int
            {
                return $this->exitCode;
            }

            public function lastOutput(): string
            {
                return $this->output;
            }
        };
        $this->app->instance(ArtisanCommandRunner::class, $fake);

        Http::fake([self::WEBHOOK => Http::response([], 200)]);

        Transaction::factory()->create();

        $this->artisan('exospace:backup', ['type' => 'db'])
            ->assertExitCode(1);

        Http::assertSent(function ($request) {
            $body = (string) $request->body();
            return str_contains($body, 'Daily database backup')
                && str_contains($body, 'mysqldump: command not found')
                && str_contains($body, 'Underlying spatie output');
        });
    }
}
