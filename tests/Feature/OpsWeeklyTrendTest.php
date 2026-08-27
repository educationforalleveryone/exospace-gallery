<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use App\Ops\Models\OpsIncident;
use App\Ops\Models\OpsReviewSnapshot;
use App\Ops\Services\OpsWeeklyReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 9, Feature A — the weekly review's long memory.
 *
 * Two mechanisms, tested separately on purpose:
 *
 *   1. WEEK-OVER-WEEK DELTAS — computed LIVE from window pairs
 *      (current 7 d + the 7 d before it), so they stay accurate even
 *      when a Monday send was missed. Pinned per section: inline
 *      suffixes on lines that own their number, one dedicated line for
 *      title-held metrics (sweep findings, incident opened/resolved),
 *      NO delta for state metrics (active incidents, backups), and NO
 *      delta at all when both windows are zero (all-zero comparisons
 *      are silence, not information).
 *
 *   2. SNAPSHOTS — persisted by every send() invocation (NEVER by the
 *      preview: /ops/digest composes on every page load and must stay
 *      side-effect-free). The 8-week strip on /ops/digest reads the
 *      LATEST row per week_start; retention rides ops:prune-events.
 */
class OpsWeeklyTrendTest extends TestCase
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

        Cache::flush();

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

    private function asTier(string $level)
    {
        $user = User::factory()->withMfa()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        \App\Ops\Models\OpsAccessGrant::create([
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

    private function snapshot(array $overrides = []): OpsReviewSnapshot
    {
        $weekStart = now()->subDays(7);

        return OpsReviewSnapshot::create(array_merge([
            'week_start' => $weekStart->toDateString(),
            'week_end' => now()->toDateString(),
            'trigger' => 'scheduled',
            'metrics' => ['errors' => ['total' => 10], 'incidents' => ['opened' => 1, 'mttr_minutes' => 120]],
            'created_at' => now(),
        ], $overrides));
    }

    // ── 1. Live week-over-week deltas ───────────────────────────────────

    public function test_error_lines_carry_week_over_week_deltas(): void
    {
        // Current window: 3 QUEUE, 1 REDIS. Previous window: 1 QUEUE, 2 REDIS.
        $this->event(['category' => 'QUEUE']);
        $this->event(['category' => 'QUEUE']);
        $this->event(['category' => 'QUEUE']);
        $this->event(['category' => 'REDIS']);
        $this->event(['category' => 'QUEUE', 'first_seen_at' => now()->subDays(9)]);
        $this->event(['category' => 'REDIS', 'first_seen_at' => now()->subDays(9)]);
        $this->event(['category' => 'REDIS', 'first_seen_at' => now()->subDays(10)]);

        $lines = $this->section($this->service()->compose(), 'errors')['lines'];

        $this->assertContains('QUEUE: 3 event(s) — ▲ +2 vs last week', $lines);
        $this->assertContains('REDIS: 1 event(s) — ▼ −1 vs last week', $lines);
    }

    public function test_a_gone_dark_category_renders_no_zero_line(): void
    {
        // The histogram answers "what kind of week was it" — a category
        // with zero current events is ABSENT, not listed as 0. Its delta
        // story was last week's message; absence is this week's.
        $this->event(['category' => 'QUEUE']);
        $this->event(['category' => 'REDIS', 'first_seen_at' => now()->subDays(9)]);
        $this->event(['category' => 'REDIS', 'first_seen_at' => now()->subDays(10)]);

        $lines = $this->section($this->service()->compose(), 'errors')['lines'];

        $this->assertContains('QUEUE: 1 event(s) — ▲ +1 vs last week', $lines);
        $this->assertFalse(collect($lines)->contains(fn ($l) => str_starts_with($l, 'REDIS:')), 'A gone-dark category must not render a zero line.');
    }

    public function test_an_empty_current_window_states_the_drop_against_last_week(): void
    {
        $this->event(['category' => 'QUEUE', 'first_seen_at' => now()->subDays(9)]);
        $this->event(['category' => 'REDIS', 'first_seen_at' => now()->subDays(10)]);

        $lines = $this->section($this->service()->compose(), 'errors')['lines'];

        $this->assertSame('no new error events', $this->section($this->service()->compose(), 'errors')['title']);
        $this->assertContains('Nothing entered the event store in the last 7 days — ▼ −2 vs last week.', $lines);
    }

    public function test_incidents_get_a_dedicated_vs_line_for_opened_and_resolved(): void
    {
        // Current window: 2 opened, 1 resolved.
        $this->incident(['status' => 'resolved', 'first_event_at' => now()->subHours(10), 'resolved_at' => now()->subHours(2), 'last_event_at' => now()->subHours(2)]);
        $this->incident(['status' => 'open']);
        // Previous window: 1 opened (and resolved), plus 2 more resolved
        // last week that had OPENED the week before (first_event_at older
        // than the 14-day boundary) — resolved counts, opened does not.
        $this->incident(['status' => 'resolved', 'first_event_at' => now()->subDays(9), 'resolved_at' => now()->subDays(8), 'last_event_at' => now()->subDays(8)]);
        $this->incident(['status' => 'resolved', 'first_event_at' => now()->subDays(20), 'resolved_at' => now()->subDays(9), 'last_event_at' => now()->subDays(9)]);
        $this->incident(['status' => 'resolved', 'first_event_at' => now()->subDays(21), 'resolved_at' => now()->subDays(10), 'last_event_at' => now()->subDays(10)]);

        $lines = $this->section($this->service()->compose(), 'incidents')['lines'];

        $this->assertContains('vs last week: opened ▲ +1, resolved ▼ −2', $lines);
    }

    public function test_mttr_line_carries_the_hours_delta_when_last_week_has_a_mean(): void
    {
        // Current MTTR: 2 h. Previous MTTR: 10 h → ▼ −8.0 h.
        $this->incident(['status' => 'resolved', 'first_event_at' => now()->subHours(4), 'resolved_at' => now()->subHours(2), 'last_event_at' => now()->subHours(2)]);
        $this->incident(['status' => 'resolved',
            'first_event_at' => now()->subDays(9),
            'resolved_at' => now()->subDays(9)->addHours(10),
            'last_event_at' => now()->subDays(9)->addHours(10)]);

        $mttrLine = collect($this->section($this->service()->compose(), 'incidents')['lines'])
            ->first(fn ($line) => str_starts_with($line, 'MTTR'));

        $this->assertNotNull($mttrLine);
        $this->assertStringContainsString('MTTR 2.0 h (mean, 1 resolved) — ▼ −8.0 h vs last week.', $mttrLine);
    }

    public function test_mta_line_has_no_delta_when_last_week_has_no_acknowledgements(): void
    {
        // Current MTTA: 2 h (acknowledged). Previous window: resolved but
        // never acknowledged → no comparable mean → NO invented prose.
        $this->incident(['status' => 'resolved',
            'first_event_at' => now()->subHours(4),
            'acknowledged_at' => now()->subHours(2),
            'resolved_at' => now()->subHours(1),
            'last_event_at' => now()->subHours(1)]);
        $this->incident(['status' => 'resolved',
            'first_event_at' => now()->subDays(9),
            'acknowledged_at' => null,
            'resolved_at' => now()->subDays(9)->addHours(6),
            'last_event_at' => now()->subDays(9)->addHours(6)]);

        $mttaLine = collect($this->section($this->service()->compose(), 'incidents')['lines'])
            ->first(fn ($line) => str_starts_with($line, 'MTTA'));

        $this->assertNotNull($mttaLine);
        $this->assertStringContainsString('MTTA 2.0 h (mean, 1 acknowledged)', $mttaLine);
        $this->assertStringNotContainsString('vs last week', $mttaLine, 'No comparable mean → no delta, and no invented "no comparison" prose.');
    }

    public function test_deployment_and_failure_lines_carry_deltas(): void
    {
        // Current: 3 deployment events, 1 failed. Previous: 1, none failed.
        $this->event(['category' => 'DEPLOYMENT', 'severity' => 'info']);
        $this->event(['category' => 'DEPLOYMENT', 'severity' => 'error']);
        $this->event(['category' => 'BUILD', 'severity' => 'info']);
        $this->event(['category' => 'DEPLOYMENT', 'severity' => 'info', 'first_seen_at' => now()->subDays(9)]);

        $section = $this->section($this->service()->compose(), 'deployments');

        $this->assertSame('3 deployment(s), 1 failed', $section['title']);
        $this->assertContains('3 deployment event(s) recorded — ▲ +2 vs last week', $section['lines']);
        $this->assertContains('1 with error severity — see the Events page filtered to DEPLOYMENT — ▲ +1 vs last week', $section['lines']);
    }

    public function test_sweep_findings_get_one_dedicated_delta_line(): void
    {
        $this->event(['source' => 'sweep', 'title' => 'Disk usage above 85%']);
        $this->event(['source' => 'sweep', 'title' => 'Disk usage above 85%']);
        $this->event(['source' => 'sweep', 'title' => 'Disk usage above 85%', 'first_seen_at' => now()->subDays(9)]);

        $section = $this->section($this->service()->compose(), 'sweep');

        $this->assertSame('2 finding(s), 2 open', $section['title']);
        $this->assertContains('vs last week: ▲ +1', $section['lines'], 'The findings total lives in the title → one dedicated delta line.');
    }

    public function test_activity_lines_carry_deltas(): void
    {
        // Current week: 1 run + 1 audited action. Previous week: quiet.
        \App\Ops\Models\OpsDiagnosticRun::create([
            'diagnostic_id' => 'database.connectivity',
            'status' => 'healthy',
            'summary' => 'ok',
            'duration_ms' => 5,
            'created_at' => now(),
        ]);
        \App\Models\AdminAuditLog::record('ops.action.executed', User::factory()->create(), ['x' => 1]);

        $lines = $this->section($this->service()->compose(), 'activity')['lines'];

        $this->assertTrue(collect($lines)->contains(fn ($l) => str_contains($l, '1 diagnostic run(s)') && str_contains($l, '▲ +1 vs last week')));
        $this->assertTrue(collect($lines)->contains(fn ($l) => str_contains($l, '1 audited ops action(s) — ▲ +1 vs last week')));
    }

    public function test_state_metrics_never_get_deltas(): void
    {
        // Active incidents + backups are readings of NOW, not flows.
        $this->incident(['status' => 'open']);

        $review = $this->service()->compose();
        $incidents = $this->section($review, 'incidents');
        $backups = $this->section($review, 'backups');

        // The "still active" number rides the first line; the vs-line must
        // speak ONLY about opened/resolved.
        $vsLine = collect($incidents['lines'])->first(fn ($l) => str_starts_with($l, 'vs last week'));
        $this->assertNotNull($vsLine);
        $this->assertStringNotContainsString('active', $vsLine, 'Active incidents are a state — no delta.');
        $this->assertTrue(collect($backups['lines'])->every(fn ($l) => ! str_contains($l, 'vs last week')), 'Backups are current state — no delta.');
    }

    public function test_both_windows_zero_means_no_delta_text_anywhere(): void
    {
        $review = $this->service()->compose();

        foreach ($review['sections'] as $section) {
            foreach ($section['lines'] as $line) {
                $this->assertStringNotContainsString('vs last week', $line, "All-zero comparisons are silence: '{$section['key']}' leaked a delta.");
                $this->assertStringNotContainsString('▲', $line);
                $this->assertStringNotContainsString('▼', $line);
            }
        }
    }

    // ── 2. Metrics + snapshots ───────────────────────────────────────────

    public function test_compose_exposes_the_window_pair_and_section_metrics(): void
    {
        $this->event(['category' => 'QUEUE']);
        $this->event(['category' => 'QUEUE']);
        $this->incident(['status' => 'resolved', 'first_event_at' => now()->subHours(4), 'resolved_at' => now()->subHours(1), 'last_event_at' => now()->subHours(1)]);

        $review = $this->service()->compose();

        $this->assertEqualsWithDelta(now()->subDays(7)->timestamp, $review['window']['start']->timestamp, 5, 'Current window starts 7 days back.');
        $this->assertEqualsWithDelta(now()->subDays(14)->timestamp, $review['window']['previous_start']->timestamp, 5, 'Previous window starts 14 days back.');

        $this->assertSame(2, $review['metrics']['errors']['total']);
        $this->assertSame(['QUEUE' => 2], $review['metrics']['errors']['categories']);
        $this->assertSame(1, $review['metrics']['incidents']['resolved']);
        $this->assertNotNull($review['metrics']['incidents']['mttr_minutes']);
        $this->assertIsFloat((float) $review['metrics']['incidents']['mttr_minutes']);
        $this->assertArrayHasKey('total', $review['metrics']['deployments']);
        $this->assertArrayHasKey('findings', $review['metrics']['sweep']);
        $this->assertArrayHasKey('status', $review['metrics']['backups']);
        $this->assertArrayHasKey('runs', $review['metrics']['activity']);
    }

    public function test_send_persists_one_snapshot_row_with_the_weeks_metrics(): void
    {
        $this->event(['category' => 'QUEUE']);
        $this->event(['category' => 'QUEUE']);

        $result = $this->service()->send('scheduled');

        $this->assertTrue($result['snapshot']);
        $this->assertSame(1, OpsReviewSnapshot::count());

        $row = OpsReviewSnapshot::first();
        $this->assertSame(now()->subDays(7)->toDateString(), $row->week_start->toDateString());
        $this->assertSame(now()->toDateString(), $row->week_end->toDateString());
        $this->assertSame('scheduled', $row->trigger);
        $this->assertSame(2, $row->metrics['errors']['total']);
        $this->assertSame(['QUEUE' => 2], $row->metrics['errors']['categories']);
    }

    public function test_previewing_composes_nothing_into_the_snapshot_table(): void
    {
        $this->event(['category' => 'QUEUE']);

        $this->service()->compose();
        $this->service()->render($this->service()->compose());

        // The digest page preview path — compose + render only.
        $this->asTier('viewer')->get('/ops/digest')->assertOk();

        $this->assertSame(0, OpsReviewSnapshot::count(), 'The preview must stay side-effect-free — snapshots are written by deliveries only.');
    }

    public function test_a_manual_send_records_a_manual_row(): void
    {
        $this->service()->send('manual');

        $this->assertSame(1, OpsReviewSnapshot::count());
        $this->assertSame('manual', OpsReviewSnapshot::first()->trigger);
    }

    public function test_repeat_sends_dedupe_to_the_latest_row_per_week(): void
    {
        $this->snapshot(['metrics' => ['errors' => ['total' => 5]]]);
        $this->snapshot(['metrics' => ['errors' => ['total' => 9]]]);

        $weeks = $this->service()->recentSnapshots(8);

        $this->assertCount(1, $weeks, 'Two sends in one week = one strip entry.');
        $this->assertSame(9, $weeks[0]->metrics['errors']['total'], 'The LATEST delivery is that week\'s truth.');
    }

    public function test_recent_snapshots_order_oldest_to_newest_and_cap_at_eight(): void
    {
        foreach (range(1, 10) as $i) {
            $this->snapshot([
                'week_start' => now()->subDays(7 * $i)->toDateString(),
                'week_end' => now()->subDays(7 * ($i - 1))->toDateString(),
                'metrics' => ['errors' => ['total' => $i]],
                'created_at' => now()->subDays(7 * $i),
            ]);
        }

        $weeks = $this->service()->recentSnapshots(8);

        $this->assertCount(8, $weeks, 'The strip shows at most 8 weeks.');
        $this->assertSame(8, $weeks[0]->metrics['errors']['total'], 'Oldest retained week first (week 8 back).');
        $this->assertSame(1, $weeks[7]->metrics['errors']['total'], 'Newest week last (week 1 back).');
    }

    public function test_recent_snapshots_survive_a_missing_table(): void
    {
        Schema::drop('ops_review_snapshots');

        $this->assertSame([], $this->service()->recentSnapshots(8), 'An unreadable snapshot store renders an empty strip, never a broken page.');
    }

    public function test_a_failed_snapshot_write_never_fails_the_send(): void
    {
        Schema::drop('ops_review_snapshots');

        $result = $this->service()->send('scheduled');

        $this->assertTrue($result['sent'], 'The Slack delivery must not care about the snapshot store.');
        $this->assertFalse($result['snapshot'], 'The failure is reported honestly.');
    }

    // ── 3. The trend strip surface ──────────────────────────────────────

    public function test_digest_page_renders_the_eight_week_strip(): void
    {
        foreach (range(1, 3) as $i) {
            $this->snapshot([
                'week_start' => now()->subDays(7 * $i)->toDateString(),
                'week_end' => now()->subDays(7 * ($i - 1))->toDateString(),
                'metrics' => ['errors' => ['total' => 10 * $i], 'incidents' => ['opened' => $i, 'mttr_minutes' => 60]],
                'created_at' => now()->subDays(7 * $i),
            ]);
        }

        $response = $this->asTier('viewer')->get('/ops/digest');

        $response->assertOk();
        $response->assertSee('Errors by week — from review snapshots', false);
        $response->assertSee('<svg', false);
        $response->assertSee('3 weeks recorded', false);
        $response->assertSee('each bar = one week', false);
    }

    public function test_digest_page_states_the_cold_start_honestly(): void
    {
        $this->asTier('viewer')->get('/ops/digest')
            ->assertOk()
            ->assertSee('No weekly snapshots recorded yet', false);

        $this->snapshot();

        $this->asTier('viewer')->get('/ops/digest')
            ->assertOk()
            ->assertSee('One week recorded so far — the trend appears from the second Monday', false);
    }

    public function test_prune_command_deletes_snapshots_past_retention(): void
    {
        $this->snapshot(['created_at' => now()->subDays(400)]);
        $this->snapshot();

        $this->artisan('ops:prune-events')->assertSuccessful();

        $this->assertSame(1, OpsReviewSnapshot::count());
        $this->assertSame(now()->toDateString(), OpsReviewSnapshot::first()->created_at->toDateString());
    }
}
