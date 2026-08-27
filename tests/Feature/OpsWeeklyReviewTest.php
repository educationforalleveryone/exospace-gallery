<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Ops\Models\OpsAccessGrant;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsDiagnosticRun;
use App\Ops\Models\OpsEvent;
use App\Ops\Models\OpsIncident;
use App\Ops\Services\OpsStatusTilesService;
use App\Ops\Services\OpsWeeklyReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 8, Feature C — the weekly review digest.
 *
 * These tests pin:
 *
 *   1. compose(): all six sections present on an empty platform (honest
 *      empty states); every section derives from the control plane's
 *      own tables; error volume by category; incident throughput with
 *      real MTTA/MTTR math; deployment failures; sweep finding history
 *      with the still-open slice; backups framed as CURRENT state.
 *   2. Fail-soft per section: a throwing tiles service degrades exactly
 *      its own section.
 *   3. send(): scheduled posts once + deduplicates within the info TTL;
 *      manual bypasses; both stamp; records NO ops_events rows.
 *   4. The command: sends + exits 0; kill switch = clean no-op.
 *   5. Routes: the /ops/digest preview (daily + weekly blocks) is
 *      viewer-visible; the weekly manual send is super-admin-only,
 *      throttled, audited as ops.weekly_review.sent.
 */
class OpsWeeklyReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();

        config([
            'services.operational_alerts.webhook_url' => 'https://slack.test/hook',
            'services.operational_alerts.critical_webhook_url' => null,
            'ops.weekly_review.enabled' => true,
            // Determinism: neutralize whatever the dev box's .env carries.
            'ops.sentry.api_token' => null,
            'ops.sentry.org' => null,
        ]);

        Http::fake([
            'slack.test/*' => Http::response(['ok' => true]),
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function service(): OpsWeeklyReviewService
    {
        return app(OpsWeeklyReviewService::class);
    }

    private function section(array $review, string $key): array
    {
        foreach ($review['sections'] as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
        }

        $this->fail("Weekly review section '{$key}' not present.");
    }

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

    private function asTier(string $level)
    {
        $user = User::factory()->withMfa()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        OpsAccessGrant::create([
            'user_id' => $user->id,
            'level' => $level,
            'granted_by' => User::factory()->create(['is_super_admin' => true])->id,
            'granted_at' => now(),
        ]);

        return $this->actingAs($user)->withSession([
            'mfa_verified' => true,
            'mfa_verified_at' => now()->timestamp,
        ]);
    }

    private function app(array $overrides = []): OpsApplication
    {
        return OpsApplication::create(array_merge([
            'slug' => 'app-'.uniqid(),
            'name' => 'App '.uniqid(),
            'provider' => 'coolify',
            'kind' => 'application',
            'environment' => 'production',
            'status' => 'running:healthy',
            'health' => 'running',
        ], $overrides));
    }

    private function event(array $overrides = []): OpsEvent
    {
        return OpsEvent::create(array_merge([
            'fingerprint' => sha1(uniqid('', true)),
            'ops_application_id' => $this->app()->id,
            'source' => 'system',
            'category' => 'APPLICATION',
            'severity' => 'error',
            'title' => 'Event '.uniqid(),
            'status' => 'open',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ], $overrides));
    }

    private function incident(array $overrides = []): OpsIncident
    {
        return OpsIncident::create(array_merge([
            'ops_application_id' => $this->app()->id,
            'title' => 'Incident '.uniqid(),
            'severity' => 'error',
            'status' => 'open',
            'correlation_key' => 'key-'.uniqid(),
            'event_count' => 1,
            'first_event_at' => now(),
            'last_event_at' => now(),
        ], $overrides));
    }

    private function slackMessages(): array
    {
        // Http::recorded(), NOT assertSent: the silent paths send ZERO
        // requests and assertSent would fail on the absence itself.
        $messages = [];
        foreach (Http::recorded() as [$request, $response]) {
            if (str_contains((string) $request->url(), 'slack.test')) {
                $messages[] = (string) ($request->data()['text'] ?? '');
            }
        }

        return $messages;
    }

    // ── 1. compose(): structure + empty platform ────────────────────────

    public function test_compose_renders_every_section_on_an_empty_platform(): void
    {
        $review = $this->service()->compose();

        foreach (['errors', 'incidents', 'deployments', 'sweep', 'backups', 'activity'] as $key) {
            $section = $this->section($review, $key);
            $this->assertNotSame('unavailable', $section['status'], "Section '{$key}' must compose on an empty platform.");
            $this->assertNotEmpty($section['lines'], "Section '{$key}' must say something honest, even on an empty platform.");
        }

        $this->assertEmpty($review['omitted'], 'No section is omitted on configuration grounds — all six derive from local tables.');
    }

    public function test_error_volume_section_counts_by_category_with_occurrences(): void
    {
        $this->event(['category' => 'DATABASE', 'occurrence_count' => 5]);
        $this->event(['category' => 'DATABASE', 'occurrence_count' => 1]);
        $this->event(['category' => 'REDIS', 'occurrence_count' => 3]);
        // First seen OUTSIDE the 7-day window — must not count.
        $this->event(['category' => 'QUEUE', 'first_seen_at' => now()->subDays(9)]);

        $section = $this->section($this->service()->compose(), 'errors');

        $this->assertSame('3 new event(s) across 2 categories', $section['title']);
        // Iteration 9: every flow line now carries its week-over-week
        // delta. The fixture's previous window holds only the QUEUE event
        // (9 days old), so both current categories rose from zero.
        $this->assertContains('DATABASE: 2 event(s) (6 occurrences) — ▲ +2 vs last week', $section['lines']);
        $this->assertContains('REDIS: 1 event(s) (3 occurrences) — ▲ +1 vs last week', $section['lines']);
    }

    public function test_incident_section_computes_mtta_and_mttr(): void
    {
        // Opened AND resolved inside the window: first event 10 h ago,
        // acknowledged 8 h ago (MTTA 2 h), resolved 6 h ago (MTTR 4 h).
        $this->incident([
            'status' => 'resolved',
            'first_event_at' => now()->subHours(10),
            'acknowledged_at' => now()->subHours(8),
            'resolved_at' => now()->subHours(6),
            'last_event_at' => now()->subHours(6),
        ]);

        // Resolved but never acknowledged — MTTR-only population.
        $this->incident([
            'status' => 'resolved',
            'first_event_at' => now()->subHours(20),
            'acknowledged_at' => null,
            'resolved_at' => now()->subHours(18),
            'last_event_at' => now()->subHours(18),
        ]);

        // Resolved OUTSIDE the window — must not enter the metrics.
        $this->incident([
            'status' => 'resolved',
            'first_event_at' => now()->subDays(9),
            'acknowledged_at' => now()->subDays(9)->addHours(1),
            'resolved_at' => now()->subDays(8),
            'last_event_at' => now()->subDays(8),
        ]);

        // Still open — counted in the rollup, not the metrics.
        $this->incident(['status' => 'open']);

        $section = $this->section($this->service()->compose(), 'incidents');

        // All three in-window incidents OPENED this week (two went on to
        // resolve) — "opened" is throughput, not "still open".
        $this->assertSame('3 opened, 2 resolved', $section['title']);

        $mttrLine = collect($section['lines'])->first(fn ($line) => str_starts_with($line, 'MTTR'));
        $this->assertNotNull($mttrLine);
        // Mean MTTR = (4 h + 2 h) / 2 = 3.0 h.
        $this->assertStringContainsString('MTTR 3.0 h (mean, 2 resolved)', $mttrLine);

        $mttaLine = collect($section['lines'])->first(fn ($line) => str_starts_with($line, 'MTTA'));
        $this->assertNotNull($mttaLine);
        // Only the acknowledged one counts: 2 h.
        $this->assertStringContainsString('MTTA 2.0 h (mean, 1 acknowledged)', $mttaLine);
    }

    public function test_incident_section_prompts_acknowledgement_when_no_timestamps_exist(): void
    {
        $this->incident([
            'status' => 'resolved',
            'acknowledged_at' => null,
            'first_event_at' => now()->subHours(5),
            'resolved_at' => now()->subHours(1),
            'last_event_at' => now()->subHours(1),
        ]);

        $section = $this->section($this->service()->compose(), 'incidents');

        $this->assertTrue(
            collect($section['lines'])->contains(fn ($line) => str_contains($line, 'acknowledge incidents')),
            'A resolved-but-never-acknowledged week must prompt the operator to acknowledge (that is how MTTA gets data).',
        );
    }

    public function test_deployment_section_breaks_out_failures(): void
    {
        $this->event(['category' => 'DEPLOYMENT', 'severity' => 'info', 'title' => 'Deploy ok 1']);
        $this->event(['category' => 'DEPLOYMENT', 'severity' => 'info', 'title' => 'Deploy ok 2']);
        $this->event(['category' => 'BUILD', 'severity' => 'error', 'title' => 'Build failed']);
        $this->event(['category' => 'APPLICATION', 'severity' => 'error', 'title' => 'Not a deployment']);

        $section = $this->section($this->service()->compose(), 'deployments');

        $this->assertSame('3 deployment(s), 1 failed', $section['title']);
        $this->assertSame('attention', $section['status']);
    }

    public function test_sweep_section_counts_findings_and_flags_the_open_slice(): void
    {
        $this->event(['source' => 'sweep', 'title' => 'Automated sweep: Database connectivity', 'status' => 'resolved']);
        $this->event(['source' => 'sweep', 'title' => 'Automated sweep: Database connectivity', 'status' => 'open', 'fingerprint' => sha1('dup-sweep')]);
        $this->event(['source' => 'system', 'title' => 'Automated sweep: not really']); // source filter must hold

        $section = $this->section($this->service()->compose(), 'sweep');

        $this->assertSame('2 finding(s), 1 open', $section['title']);
        $this->assertSame('attention', $section['status']);
        $this->assertTrue(collect($section['lines'])->contains(fn ($line) => str_contains($line, 'still open')));
    }

    public function test_sweep_section_reports_a_clean_week_as_ok(): void
    {
        $this->event(['source' => 'sweep', 'title' => 'Automated sweep: Redis', 'status' => 'resolved']);

        $section = $this->section($this->service()->compose(), 'sweep');

        $this->assertSame('ok', $section['status']);
        $this->assertTrue(collect($section['lines'])->contains('All findings resolved.'));
    }

    public function test_backups_section_is_framed_as_current_state_not_history(): void
    {
        $section = $this->section($this->service()->compose(), 'backups');

        $this->assertStringContainsString('not a 7-day history', $section['title'], 'The title itself must carry the honesty framing — the control plane stores no 7-day backup history.');
    }

    public function test_activity_section_reports_the_weeks_runs_and_actions(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        OpsDiagnosticRun::create([
            'diagnostic_id' => 'database.connectivity',
            'ops_application_id' => null,
            'status' => 'healthy',
            'summary' => 'ok',
            'findings' => [],
            'actor_id' => $admin->id,
            'duration_ms' => 20,
            // timestamps=false on this model — created_at is set by hand.
            'created_at' => now(),
        ]);

        AdminAuditLog::record('ops.sentry.mapping', $admin, ['new' => 'x']);

        $section = $this->section($this->service()->compose(), 'activity');

        $this->assertSame('1 run(s), 1 action(s)', $section['title']);
        $this->assertTrue(collect($section['lines'])->contains(fn ($line) => str_contains($line, 'most by')));
    }

    // ── 2. Fail-soft per section ────────────────────────────────────────

    public function test_a_throwing_tiles_service_degrades_exactly_the_backups_section(): void
    {
        $this->mock(OpsStatusTilesService::class)
            ->shouldReceive('backupStatus')
            ->andThrow(new \RuntimeException('disk explosion'));

        $review = $this->service()->compose();

        $backups = $this->section($review, 'backups');
        $this->assertSame('unavailable', $backups['status']);
        $this->assertStringContainsString('disk explosion', $backups['lines'][0]);

        // Every other section still composes.
        foreach (['errors', 'incidents', 'deployments', 'sweep', 'activity'] as $key) {
            $this->assertNotSame('unavailable', $this->section($review, $key)['status']);
        }
    }

    // ── 3. send(): dedup, manual bypass, stamp, no events ───────────────

    public function test_render_shape_is_slack_ready(): void
    {
        $text = $this->service()->render($this->service()->compose());

        $this->assertStringContainsString('ERRORS BY CATEGORY (7 D):', $text);
        $this->assertStringContainsString('INCIDENT THROUGHPUT (7 D):', $text);
        $this->assertStringContainsString('OPERATOR ACTIVITY (7 D):', $text);
        $this->assertStringContainsString('Full detail: ', $text);
        $this->assertStringNotContainsString('Array', $text, 'A leaked array-to-string would render "Array" into Slack.');
    }

    public function test_scheduled_send_deduplicates_within_the_info_ttl(): void
    {
        $this->service()->send('scheduled');
        $this->service()->send('scheduled');

        $this->assertCount(1, $this->slackMessages(), 'A double-fired schedule must not double-post (dedup key ops.weekly.review).');
    }

    public function test_manual_send_bypasses_the_dedup(): void
    {
        $this->service()->send('scheduled');
        $this->service()->send('manual');

        $this->assertCount(2, $this->slackMessages(), 'A manual test send deliberately bypasses the dedup.');
    }

    public function test_send_stamps_last_sent_without_recording_events(): void
    {
        $before = OpsEvent::count();

        $this->service()->send('scheduled');

        $this->assertSame($before, OpsEvent::count(), 'The review reports on events — it must not become one.');

        $lastSent = $this->service()->lastSent();
        $this->assertNotNull($lastSent);
        $this->assertSame('scheduled', $lastSent['trigger']);
    }

    // ── 4. The command ──────────────────────────────────────────────────

    public function test_command_sends_and_exits_zero(): void
    {
        $this->artisan('ops:send-weekly-review')
            ->expectsOutputToContain('Weekly review sent')
            ->assertSuccessful();
    }

    public function test_command_kill_switch_is_a_clean_no_op(): void
    {
        config(['ops.weekly_review.enabled' => false]);

        $this->artisan('ops:send-weekly-review')
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();

        $this->assertSame([], array_filter($this->slackMessages()));
    }

    public function test_the_schedule_registers_the_review_mondays_at_0830(): void
    {
        $events = collect(\Illuminate\Support\Facades\Schedule::events());

        $review = $events->first(fn ($event) => str_contains((string) $event->command, 'ops:send-weekly-review'));

        $this->assertNotNull($review, 'The weekly review must be registered on the schedule.');
        // weeklyOn(1, '08:30') compiles to cron: minute 30, hour 8, Mondays.
        $this->assertSame('30 8 * * 1', (string) $review->expression);
    }

    // ── 5. Routes + page ────────────────────────────────────────────────

    public function test_digest_page_renders_both_blocks_for_all_read_tiers(): void
    {
        foreach (['viewer', 'operator'] as $tier) {
            $this->asTier($tier)->get(route('ops.digest.index'))
                ->assertOk()
                ->assertSee('Morning Digest', false)
                ->assertSee('Weekly Review', false)
                ->assertSee('ERRORS BY CATEGORY', false)
                ->assertDontSee('Send weekly review now', false, 'The weekly manual-send button must not render for non-super-admins.');
        }
    }

    public function test_weekly_manual_send_is_super_admin_only_and_audited(): void
    {
        $this->asTier('viewer')->post(route('ops.digest.weekly.send'))->assertForbidden();
        $this->asTier('operator')->post(route('ops.digest.weekly.send'))->assertForbidden();

        $this->asMfaSuperAdmin()->post(route('ops.digest.weekly.send'))
            ->assertRedirect(route('ops.digest.index'));

        $audit = AdminAuditLog::query()->where('action', 'ops.weekly_review.sent')->latest('id')->first();
        $this->assertNotNull($audit, 'The weekly manual send must be audited.');
        $this->assertSame('manual', $audit->payload['trigger'] ?? null);
    }

    public function test_digest_page_shows_the_weekly_disabled_banner_when_switched_off(): void
    {
        config(['ops.weekly_review.enabled' => false]);

        $this->asMfaSuperAdmin()->get(route('ops.digest.index'))
            ->assertOk()
            ->assertSee('OPS_WEEKLY_REVIEW_ENABLED=false', false)
            ->assertSee('nothing is suspended', false);
    }
}
