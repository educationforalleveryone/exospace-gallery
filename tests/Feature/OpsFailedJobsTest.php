<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Ops\Actions\OpsActionService;
use App\Ops\Diagnostics\DiagnosticEngine;
use App\Ops\Models\OpsAccessGrant;
use App\Ops\Models\OpsEvent;
use App\Services\ArtisanCommandRunner;
use App\Services\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 10 — the failed-jobs lifecycle.
 *
 * The queue.failed-jobs diagnostic used to end its guidance with "retry
 * deliberately (php artisan queue:retry from a terminal)" — the last
 * workflow in the platform that pointed at a terminal. This iteration
 * closes it. These tests pin:
 *
 *   1. The BROWSER: /ops/queue is viewer-visible, paginated, filterable,
 *      honest about a missing table, and parses the human job name.
 *   2. The two ACTIONS (queue.retry / queue.forget) through the SAME
 *      four-layer security model as app.restart: allow-list, kill
 *      switch, inline password, typed phrase — plus audit + Slack
 *      announcement + a QUEUE event in the timeline.
 *   3. The AUTHORITATIVE ROW-VERIFICATION contract: success is "the row
 *      is gone", not the artisan exit code — a fake runner that returns
 *      0 without deleting must NOT report success.
 *   4. The TERMINAL GUIDANCE REMOVAL: the diagnostic now points at
 *      /ops/queue, never at a terminal.
 *
 * The real-command tests run against QUEUE_CONNECTION=sync (SyncQueue::
 * pushRaw is a no-op) with payloads that carry no data.command — so
 * Laravel's queue:retry/queue:forget execute their real database paths
 * (failer find → push → forget) without dispatching anything.
 */
class OpsFailedJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'services.operational_alerts.webhook_url' => null,
            'services.operational_alerts.critical_webhook_url' => null,
            'ops.actions.enabled' => true,
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function asMfaSuperAdmin()
    {
        $admin = User::factory()->withMfa()->create([
            'is_super_admin' => true,
            'email_verified_at' => now(),
        ]);

        return $this->actingAs($admin)->withSession([
            'mfa_verified' => true,
            'mfa_verified_at' => now()->timestamp,
        ]);
    }

    private function asViewer()
    {
        $user = User::factory()->withMfa()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        OpsAccessGrant::create([
            'user_id' => $user->id,
            'level' => OpsAccessGrant::LEVEL_VIEWER,
            'granted_by' => User::factory()->create(['is_super_admin' => true])->id,
            'granted_at' => now(),
        ]);

        return $this->actingAs($user)->withSession([
            'mfa_verified' => true,
            'mfa_verified_at' => now()->timestamp,
        ]);
    }

    /**
     * One failed_jobs row. Payload carries a displayName and NO
     * data.command — the real queue:retry then never unserializes
     * anything, and pushRaw on the sync connection is a no-op.
     */
    private function failedJob(array $overrides = []): array
    {
        $uuid = $overrides['uuid'] ?? 'job-'.uniqid('', false);

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => $overrides['connection'] ?? 'sync',
            'queue' => $overrides['queue'] ?? 'default',
            'payload' => $overrides['payload'] ?? json_encode([
                'uuid' => $uuid,
                'displayName' => 'App\\Jobs\\'.($overrides['job'] ?? 'ExportInvoice'),
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => null,
                'attempts' => 3,
            ]),
            'exception' => $overrides['exception'] ?? "RuntimeException: the payment gateway timed out\n#0 /app/Jobs/ExportInvoice.php(42): Illuminate\\Queue\\CallQueuedHandler->call()\n#1 more frames…",
            'failed_at' => $overrides['failed_at'] ?? now()->subMinutes(30),
        ]);

        return ['uuid' => $uuid];
    }

    /**
     * A fake ArtisanCommandRunner that records calls and returns a preset
     * exit code WITHOUT deleting the row — pins the authoritative
     * row-verification contract.
     */
    private function fakeRunner(int $exitCode): object
    {
        return new class ($exitCode) extends ArtisanCommandRunner {
            /** @var list<array{0: string, 1: array}> */
            public array $calls = [];

            public function __construct(private readonly int $exitCode)
            {
            }

            public function __invoke(string $command, array $parameters = []): int
            {
                $this->calls[] = [$command, $parameters];

                return $this->exitCode;
            }
        };
    }

    private function mockAlerts(): int
    {
        $count = 0;
        $this->mock(OperationalAlertService::class, function ($mock) use (&$count) {
            $mock->shouldReceive('alert')->andReturnUsing(function () use (&$count) {
                $count++;
            });
        });

        return 0;
    }

    // ── 1. The browser page ─────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/ops/queue')->assertRedirect('/login');
    }

    public function test_regular_user_without_grant_gets_403(): void
    {
        $user = User::factory()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->get('/ops/queue')->assertStatus(403);
    }

    public function test_viewer_can_browse_failed_jobs(): void
    {
        $this->asViewer()->get('/ops/queue')->assertOk();
    }

    public function test_page_shows_parsed_job_name_and_first_exception_line(): void
    {
        $this->failedJob(['job' => 'ExportInvoice', 'exception' => "PDOException: SQLSTATE[HY000] connection refused\nsecond line"]);

        $response = $this->asMfaSuperAdmin()->get('/ops/queue');

        $response->assertOk()
            ->assertSee('App\\Jobs\\ExportInvoice', false)
            ->assertSee('PDOException: SQLSTATE[HY000] connection refused', false);
    }

    public function test_empty_state_when_no_failed_jobs(): void
    {
        $this->asMfaSuperAdmin()
            ->get('/ops/queue')
            ->assertOk()
            ->assertSee('No failed jobs');
    }

    public function test_pagination_caps_at_25_jobs(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->failedJob(['uuid' => 'paginate-'.$i, 'queue' => 'default']);
        }

        $first = $this->asMfaSuperAdmin()->get('/ops/queue');
        $first->assertOk();

        $jobs = $first->viewData('jobs');
        $this->assertCount(25, $jobs->items());
        $this->assertSame(30, $jobs->total());

        $second = $this->asMfaSuperAdmin()->get('/ops/queue?page=2');
        $this->assertCount(5, $second->viewData('jobs')->items());
    }

    public function test_queue_filter_narrows_the_list(): void
    {
        $this->failedJob(['uuid' => 'on-a', 'queue' => 'billing']);
        $this->failedJob(['uuid' => 'on-b', 'queue' => 'billing']);
        $this->failedJob(['uuid' => 'on-c', 'queue' => 'default']);

        $response = $this->asMfaSuperAdmin()->get('/ops/queue?queue=billing');

        $jobs = $response->viewData('jobs');
        $this->assertSame(2, $jobs->total());
        $response->assertSee('filtered', false);
    }

    public function test_queue_chips_carry_per_queue_counts(): void
    {
        $this->failedJob(['uuid' => 'chip-1', 'queue' => 'billing']);
        $this->failedJob(['uuid' => 'chip-2', 'queue' => 'billing']);
        $this->failedJob(['uuid' => 'chip-3', 'queue' => 'default']);

        $response = $this->asMfaSuperAdmin()->get('/ops/queue');

        $this->assertSame(3, $response->viewData('summary')['total']);
        $queues = collect($response->viewData('queues'));
        $this->assertSame(2, $queues->firstWhere('queue', 'billing')['count']);
        $response->assertSee('billing · 2', false);
    }

    public function test_missing_table_renders_notice_not_a_500(): void
    {
        Schema::drop('failed_jobs');

        $this->asMfaSuperAdmin()
            ->get('/ops/queue')
            ->assertOk()
            ->assertSee('not available', false);
    }

    public function test_viewer_sees_no_action_buttons_and_admin_sees_both(): void
    {
        $this->failedJob(['uuid' => 'btn-1']);

        // Viewer: read-only — no doors.
        $this->asViewer()
            ->get('/ops/queue')
            ->assertOk()
            ->assertDontSee('Retry…')
            ->assertDontSee('Forget…');

        // Super-admin: both doors, through the confirm pages.
        $this->asMfaSuperAdmin()
            ->get('/ops/queue')
            ->assertOk()
            ->assertSee('Retry…')
            ->assertSee('Forget…');
    }

    public function test_kill_switch_hides_buttons_but_keeps_the_list_readable(): void
    {
        config(['ops.actions.enabled' => false]);
        $this->failedJob(['uuid' => 'switch-1']);

        $this->asMfaSuperAdmin()
            ->get('/ops/queue')
            ->assertOk()
            ->assertSee('OPS_ACTIONS_ENABLED=false', false)
            ->assertDontSee('Retry…');
    }

    // ── 2. queue.retry — the four-layer model + the row contract ───────

    public function test_retry_confirm_page_shows_the_real_job(): void
    {
        $this->failedJob(['uuid' => 'confirm-1', 'job' => 'ExportInvoice']);

        $this->asMfaSuperAdmin()
            ->get('/ops/actions/queue.retry/confirm?job=confirm-1')
            ->assertOk()
            ->assertSee('App\\Jobs\\ExportInvoice', false)
            ->assertSee('RETRY')
            ->assertSee('Type', false)
            ->assertSee('payment gateway timed out', false);
    }

    public function test_retry_confirm_with_unknown_uuid_redirects_to_queue_page(): void
    {
        $this->asMfaSuperAdmin()
            ->get('/ops/actions/queue.retry/confirm?job=nope')
            ->assertRedirect(route('ops.queue.index'))
            ->assertSessionHasErrors('action');
    }

    public function test_retry_wrong_password_never_executes(): void
    {
        $this->failedJob(['uuid' => 'pw-1']);
        $fake = $this->fakeRunner(0);
        $this->app->instance(ArtisanCommandRunner::class, $fake);

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/queue.retry', [
                'job' => 'pw-1',
                'confirm' => 'RETRY',
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors('password');

        $this->assertSame([], $fake->calls, 'A wrong password must never reach the artisan call.');
        $this->assertSame(0, AdminAuditLog::where('action', 'ops.action.executed')->count());
        $this->assertSame(1, DB::table('failed_jobs')->where('uuid', 'pw-1')->count());
    }

    public function test_retry_wrong_phrase_never_executes(): void
    {
        $this->failedJob(['uuid' => 'ph-1']);
        $fake = $this->fakeRunner(0);
        $this->app->instance(ArtisanCommandRunner::class, $fake);

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/queue.retry', [
                'job' => 'ph-1',
                'confirm' => 'DELETE',
                'password' => 'password',
            ])
            ->assertSessionHasErrors('confirm');

        $this->assertSame([], $fake->calls);
        $this->assertSame(1, DB::table('failed_jobs')->where('uuid', 'ph-1')->count());
    }

    public function test_retry_executes_deletes_row_audits_alerts_and_records_queue_event(): void
    {
        $this->failedJob(['uuid' => 'happy-1', 'job' => 'ExportInvoice']);
        $this->mockAlerts();

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/queue.retry', [
                'job' => 'happy-1',
                'confirm' => 'RETRY',
                'password' => 'password',
            ])
            ->assertRedirect(route('ops.queue.index'))
            ->assertSessionHas('success');

        // The row is GONE — the authoritative completion signal.
        $this->assertSame(0, DB::table('failed_jobs')->where('uuid', 'happy-1')->count());

        // Audited with outcome success.
        $audit = AdminAuditLog::where('action', 'ops.action.executed')->first();
        $this->assertNotNull($audit);
        $this->assertSame('queue.retry', $audit->payload['action']);
        $this->assertSame('success', $audit->payload['outcome']);

        // The deliberate intervention is visible in the timeline.
        $event = OpsEvent::where('category', 'QUEUE')->first();
        $this->assertNotNull($event, 'A retry must record a QUEUE event.');
        $this->assertSame('info', $event->severity);
        $this->assertStringContainsString('retried', $event->title);
        $this->assertStringContainsString('happy-1', (string) $event->message);
    }

    public function test_retry_failure_when_exit_code_nonzero_and_row_survives(): void
    {
        $this->failedJob(['uuid' => 'fail-1']);
        $fake = $this->fakeRunner(1);
        $this->app->instance(ArtisanCommandRunner::class, $fake);
        $this->mockAlerts();

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/queue.retry', [
                'job' => 'fail-1',
                'confirm' => 'RETRY',
                'password' => 'password',
            ])
            ->assertSessionHas('error');

        $this->assertSame([['queue:retry', ['id' => ['fail-1']]]], $fake->calls);
        $this->assertSame(1, DB::table('failed_jobs')->where('uuid', 'fail-1')->count(), 'The row must survive a failed retry.');

        $audit = AdminAuditLog::where('action', 'ops.action.executed')->first();
        $this->assertSame('failure', $audit->payload['outcome']);
        $this->assertSame(0, OpsEvent::where('category', 'QUEUE')->count(), 'A failed retry records no QUEUE event.');
    }

    public function test_retry_failure_when_exit_zero_but_row_survives(): void
    {
        // THE contract test: artisan exit code 0 is NOT trusted — the row
        // must be gone. (Covers unserializable payloads and every case
        // where the command reports success without completing.)
        $this->failedJob(['uuid' => 'silent-1']);
        $fake = $this->fakeRunner(0);
        $this->app->instance(ArtisanCommandRunner::class, $fake);

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/queue.retry', [
                'job' => 'silent-1',
                'confirm' => 'RETRY',
                'password' => 'password',
            ])
            ->assertSessionHas('error');

        $this->assertSame(1, DB::table('failed_jobs')->where('uuid', 'silent-1')->count());
        $this->assertSame('failure', AdminAuditLog::where('action', 'ops.action.executed')->first()->payload['outcome']);
    }

    public function test_retry_unknown_uuid_fails_cleanly_without_artisan(): void
    {
        $fake = $this->fakeRunner(0);
        $this->app->instance(ArtisanCommandRunner::class, $fake);

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/queue.retry', [
                'job' => 'does-not-exist',
                'confirm' => 'RETRY',
                'password' => 'password',
            ])
            ->assertSessionHas('error');

        $this->assertSame([], $fake->calls, 'An unknown uuid must fail before any artisan call.');
    }

    public function test_retry_the_word_all_is_treated_as_a_uuid_not_a_bulk_command(): void
    {
        // queue:retry accepts 'all' as a special id — the action surface
        // must never let that through as a bulk retry.
        $this->failedJob(['uuid' => 'innocent-1']);
        $fake = $this->fakeRunner(0);
        $this->app->instance(ArtisanCommandRunner::class, $fake);

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/queue.retry', [
                'job' => 'all',
                'confirm' => 'RETRY',
                'password' => 'password',
            ])
            ->assertSessionHas('error');

        $this->assertSame([], $fake->calls, "'all' must be resolved as a (nonexistent) uuid, never forwarded to queue:retry.");
        $this->assertSame(1, DB::table('failed_jobs')->where('uuid', 'innocent-1')->count());
    }

    public function test_retry_kill_switch_fail_closes(): void
    {
        config(['ops.actions.enabled' => false]);
        $this->failedJob(['uuid' => 'kill-1']);

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/queue.retry', [
                'job' => 'kill-1',
                'confirm' => 'RETRY',
                'password' => 'password',
            ])
            ->assertStatus(404);

        $this->assertSame(1, DB::table('failed_jobs')->where('uuid', 'kill-1')->count());
    }

    public function test_retry_requires_the_job_parameter(): void
    {
        $this->asMfaSuperAdmin()
            ->post('/ops/actions/queue.retry', [
                'confirm' => 'RETRY',
                'password' => 'password',
            ])
            ->assertSessionHasErrors('job');
    }

    // ── 3. queue.forget ─────────────────────────────────────────────────

    public function test_forget_confirm_page_states_the_permanence(): void
    {
        $this->failedJob(['uuid' => 'forg-c', 'job' => 'ExportInvoice']);

        $this->asMfaSuperAdmin()
            ->get('/ops/actions/queue.forget/confirm?job=forg-c')
            ->assertOk()
            ->assertSee('ONLY copy', false)
            ->assertSee('FORGET')
            ->assertSee('permanent', false)
            ->assertSee('no undo', false);
    }

    public function test_forget_wrong_password_never_executes(): void
    {
        $this->failedJob(['uuid' => 'forg-pw']);
        $fake = $this->fakeRunner(0);
        $this->app->instance(ArtisanCommandRunner::class, $fake);

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/queue.forget', [
                'job' => 'forg-pw',
                'confirm' => 'FORGET',
                'password' => 'nope',
            ])
            ->assertSessionHasErrors('password');

        $this->assertSame([], $fake->calls);
        $this->assertSame(1, DB::table('failed_jobs')->where('uuid', 'forg-pw')->count());
    }

    public function test_forget_executes_deletes_row_permanently_with_audit_and_event(): void
    {
        $this->failedJob(['uuid' => 'forg-h', 'job' => 'ExportInvoice']);
        $this->mockAlerts();

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/queue.forget', [
                'job' => 'forg-h',
                'confirm' => 'FORGET',
                'password' => 'password',
            ])
            ->assertRedirect(route('ops.queue.index'))
            ->assertSessionHas('success');

        $this->assertSame(0, DB::table('failed_jobs')->where('uuid', 'forg-h')->count(), 'The row, payload and exception must be permanently gone.');

        $audit = AdminAuditLog::where('action', 'ops.action.executed')->first();
        $this->assertSame('queue.forget', $audit->payload['action']);
        $this->assertSame('success', $audit->payload['outcome']);
        $this->assertStringContainsString('forg-h', (string) $audit->payload['message'], 'The uuid must ride in the audit message.');

        $event = OpsEvent::where('category', 'QUEUE')->first();
        $this->assertNotNull($event);
        $this->assertStringContainsString('deleted', $event->title);
    }

    public function test_forget_failure_when_row_survives(): void
    {
        $this->failedJob(['uuid' => 'forg-f']);
        $fake = $this->fakeRunner(1);
        $this->app->instance(ArtisanCommandRunner::class, $fake);

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/queue.forget', [
                'job' => 'forg-f',
                'confirm' => 'FORGET',
                'password' => 'password',
            ])
            ->assertSessionHas('error');

        $this->assertSame([['queue:forget', ['id' => 'forg-f']]], $fake->calls);
        $this->assertSame(1, DB::table('failed_jobs')->where('uuid', 'forg-f')->count());
    }

    public function test_forget_unknown_uuid_fails_cleanly(): void
    {
        $fake = $this->fakeRunner(0);
        $this->app->instance(ArtisanCommandRunner::class, $fake);

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/queue.forget', [
                'job' => 'ghost',
                'confirm' => 'FORGET',
                'password' => 'password',
            ])
            ->assertSessionHas('error');

        $this->assertSame([], $fake->calls);
    }

    public function test_forget_kill_switch_fail_closes(): void
    {
        config(['ops.actions.enabled' => false]);
        $this->failedJob(['uuid' => 'forg-k']);

        $this->asMfaSuperAdmin()
            ->post('/ops/actions/queue.forget', [
                'job' => 'forg-k',
                'confirm' => 'FORGET',
                'password' => 'password',
            ])
            ->assertStatus(404);

        $this->assertSame(1, DB::table('failed_jobs')->where('uuid', 'forg-k')->count());
    }

    // ── 4. The hub, the nav, the diagnostic and the parser ──────────────

    public function test_actions_hub_shows_queue_cards_and_failed_count(): void
    {
        $this->failedJob(['uuid' => 'hub-1']);
        $this->failedJob(['uuid' => 'hub-2']);

        $this->asMfaSuperAdmin()
            ->get('/ops/actions')
            ->assertOk()
            ->assertSee('Retry a failed queue job', false)
            ->assertSee('Delete a failed queue job', false)
            ->assertSee('2 failed job(s)', false)
            ->assertSee('Open the failed-jobs list', false);
    }

    public function test_actions_hub_renders_when_failed_jobs_table_is_missing(): void
    {
        Schema::drop('failed_jobs');

        $this->asMfaSuperAdmin()
            ->get('/ops/actions')
            ->assertOk()
            ->assertSee('not available', false);
    }

    public function test_queue_diagnostic_points_at_the_queue_page_never_a_terminal(): void
    {
        $this->failedJob(['uuid' => 'diag-1']);

        $run = app(DiagnosticEngine::class)->run('queue.failed-jobs', null);
        $this->assertNotNull($run);

        $this->assertStringNotContainsString('artisan', (string) $run->interpretation, 'The diagnostic must no longer recommend an artisan command.');
        $this->assertStringNotContainsString('terminal', (string) $run->interpretation);
        $this->assertStringContainsString('/ops/queue', (string) $run->interpretation, 'The guidance must point at the control-plane page.');
    }

    public function test_queue_diagnostic_covers_the_historical_pile_variant_too(): void
    {
        // Old failure, none in the last 24 h — the second guidance branch.
        $this->failedJob(['uuid' => 'diag-old', 'failed_at' => now()->subDays(5)]);

        $run = app(DiagnosticEngine::class)->run('queue.failed-jobs', null);
        $this->assertNotNull($run);
        $this->assertStringContainsString('/ops/queue', (string) $run->interpretation);
        $this->assertStringNotContainsString('artisan', (string) $run->interpretation);
    }

    public function test_nav_carries_the_queue_link(): void
    {
        $this->asViewer()->get('/ops/queue')->assertOk()->assertSee('Queue', false);
        $this->asViewer()->get('/ops')->assertOk()->assertSee('>Queue<', false);
    }

    public function test_job_name_parser_falls_back_gracefully(): void
    {
        $this->assertSame('App\\Jobs\\ExportInvoice', OpsActionService::jobName(json_encode(['displayName' => 'App\\Jobs\\ExportInvoice'])));
        $this->assertSame('App\\Jobs\\ViaCommandName', OpsActionService::jobName(json_encode(['data' => ['commandName' => 'App\\Jobs\\ViaCommandName']])));
        $this->assertSame('Unknown job', OpsActionService::jobName('not json at all'));
        $this->assertSame('Unknown job', OpsActionService::jobName(json_encode(['nothing' => 'useful'])));
    }
}
