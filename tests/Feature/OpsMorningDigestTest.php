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
use App\Ops\Models\OpsCredential;
use App\Ops\Services\OpsCredentialInventoryService;
use App\Ops\Services\OpsMorningDigestService;
use App\Ops\Services\OpsStatusTilesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 7 — the unified morning digest.
 *
 * These tests pin the briefing end to end:
 *
 *   1. compose(): all sections present on an empty platform (honest
 *      empty states, not missing sections); seeded facts surface in
 *      the right section; the incident double-count rule holds.
 *   2. Fail-soft per section: a throwing data source degrades ONLY
 *      its section — the rest of the briefing still composes.
 *   3. Sentry: omitted when unconfigured; reported when configured.
 *   4. send(): scheduled posts once + deduplicates within the info
 *      TTL; manual deliberately BYPASSES the dedup; both stamp.
 *   5. The command: sends + exits 0; kill switch = clean no-op;
 *      never fatal.
 *   6. Routes: preview viewer-visible; manual send super-admin-only,
 *      throttled, audited (ops.digest.sent); viewer sees no button.
 */
class OpsMorningDigestTest extends TestCase
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
            'ops.digest.enabled' => true,
            // Determinism: the dev box's .env may carry real Sentry /
            // ingest settings — neutralize them so the digest tests see
            // a clean, unconfigured platform unless a test opts in.
            'ops.sentry.api_token' => null,
            'ops.sentry.org' => null,
        ]);

        Http::fake([
            'slack.test/*' => Http::response(['ok' => true]),
            // Fixed 24 h stats payload for the ONE test that configures
            // Sentry. NOTE: Http stub callbacks match in REGISTRATION
            // ORDER (first match wins) — re-faking the same pattern inside
            // a test body would NOT override this, so the payload lives
            // here and the test only flips the config on.
            'sentry.test/*' => Http::response([
                'data' => [
                    [now()->timestamp - 7200, ['count' => 3]],
                    [now()->timestamp - 3600, ['count' => 9]],
                    [now()->timestamp, ['count' => 2]],
                ],
            ]),
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function service(): OpsMorningDigestService
    {
        return app(OpsMorningDigestService::class);
    }

    private function section(array $digest, string $key): array
    {
        foreach ($digest['sections'] as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
        }

        $this->fail("Digest section '{$key}' not present.");
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

    private function event(OpsApplication $application, string $severity, array $overrides = []): OpsEvent
    {
        return OpsEvent::create(array_merge([
            'fingerprint' => sha1(uniqid('', true)),
            'ops_application_id' => $application->id,
            'source' => 'system',
            'category' => 'APPLICATION',
            'severity' => $severity,
            'title' => ucfirst($severity).' event '.uniqid(),
            'status' => 'open',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ], $overrides));
    }

    private function slackMessages(): array
    {
        $messages = [];
        Http::assertSent(function ($request) use (&$messages) {
            if (str_contains((string) $request->url(), 'slack.test')) {
                $messages[] = (string) ($request->data()['text'] ?? '');
            }

            return true;
        });

        return $messages;
    }

    // ── compose(): structure + empty platform ───────────────────────────

    public function test_compose_renders_every_section_on_an_empty_platform(): void
    {
        $digest = $this->service()->compose();

        $keys = array_column($digest['sections'], 'key');

        foreach (['health', 'incidents', 'errors', 'applications', 'sweep', 'backups', 'webhooks', 'credentials', 'activity'] as $expected) {
            $this->assertContains($expected, $keys, "Section '{$expected}' must always compose — an empty platform is an honest state, not a missing section.");
        }

        // Sentry stays OMITTED while unconfigured (not 'unavailable' —
        // the operator never asked for it).
        $this->assertNotContains('sentry', $keys);
        $this->assertContains('sentry', array_column($digest['omitted'], 'key'));

        foreach ($digest['sections'] as $section) {
            $this->assertNotSame('', (string) $section['title']);
            $this->assertContains($section['status'], ['ok', 'attention', 'critical', 'unavailable']);
            $this->assertNotEmpty($section['lines']);
        }
    }

    public function test_compose_reports_seeded_incidents_and_untriaged_errors_without_double_counting(): void
    {
        $application = $this->app(['name' => 'Billing Service']);

        $incident = OpsIncident::create([
            'ops_application_id' => $application->id,
            'title' => 'Payment webhook backlog',
            'severity' => 'error',
            'status' => 'open',
            'correlation_key' => 'key-'.uniqid(),
            'event_count' => 2,
            'first_event_at' => now()->subHours(3),
            'last_event_at' => now(),
        ]);

        // This error belongs to the incident → counted as part of it,
        // never twice (the health-score rule, applied to the digest).
        $this->event($application, 'error', [
            'title' => 'Correlated error inside the incident',
            'ops_incident_id' => $incident->id,
        ]);

        // This one is untriaged.
        $otherApp = $this->app(['name' => 'Gallery Frontend']);
        $this->event($otherApp, 'critical', ['title' => 'Frontend 500 spike']);

        $digest = $this->service()->compose();

        $incidents = $this->section($digest, 'incidents');
        $this->assertSame('attention', $incidents['status']); // error-level, not critical
        $this->assertStringContainsString('1 active', $incidents['title']);
        $this->assertStringContainsString('Payment webhook backlog', implode(' / ', $incidents['lines']));

        $errors = $this->section($digest, 'errors');
        $this->assertSame('critical', $errors['status']); // the untriaged one is critical
        $this->assertStringContainsString('1 untriaged (1 critical)', $errors['title']);
        $lines = implode(' / ', $errors['lines']);
        $this->assertStringContainsString('Frontend 500 spike', $lines);
        $this->assertStringNotContainsString('Correlated error inside the incident', $lines);
    }

    public function test_compose_lists_stopped_application_as_worst_offender(): void
    {
        $this->app(['name' => 'Healthy Service', 'health' => 'running']);
        $this->app(['name' => 'Stopped Service', 'health' => 'stopped', 'status' => 'stopped']);

        $digest = $this->service()->compose();

        $applications = $this->section($digest, 'applications');
        $this->assertSame('critical', $applications['status']);
        $this->assertStringContainsString('1 stopped', $applications['title']);
        $this->assertStringContainsString('Stopped Service', implode(' / ', $applications['lines']));
    }

    public function test_compose_flags_open_sweep_findings(): void
    {
        // Quiet state first: default sweep set, no open events.
        $digest = $this->service()->compose();
        $sweep = $this->section($digest, 'sweep');
        $this->assertSame('ok', $sweep['status']);
        $this->assertStringContainsString('healthy', $sweep['title']);

        // An open finding for one of the swept checks.
        $application = $this->app();
        OpsEvent::create([
            'fingerprint' => sha1(uniqid('', true)),
            'ops_application_id' => $application->id,
            'source' => 'sweep',
            'category' => 'DATABASE',
            'severity' => 'warning',
            'title' => 'Automated sweep: Database connectivity',
            'message' => 'degraded',
            'status' => 'open',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $digest = $this->service()->compose();
        $sweep = $this->section($digest, 'sweep');
        $this->assertSame('critical', $sweep['status']);
        $this->assertStringContainsString('1 open sweep finding', $sweep['title']);
        $this->assertStringContainsString('Database connectivity', implode(' / ', $sweep['lines']));
    }

    public function test_compose_flags_disabled_sweep(): void
    {
        config(['ops.sweeps.enabled' => false]);

        $sweep = $this->section($this->service()->compose(), 'sweep');

        $this->assertSame('attention', $sweep['status']);
        $this->assertStringContainsString('disabled', $sweep['title']);
    }

    public function test_compose_reports_sentry_trend_when_configured(): void
    {
        config([
            'ops.sentry.api_token' => 'test-token',
            'ops.sentry.org' => 'acme',
            'ops.sentry.base_url' => 'https://sentry.test',
        ]);

        $digest = $this->service()->compose();
        $sentry = $this->section($digest, 'sentry');

        $this->assertSame('ok', $sentry['status']);
        $this->assertStringContainsString('14 Sentry events in 24 h', $sentry['title']);
        $this->assertSame([], $digest['omitted']); // nothing omitted now
    }

    public function test_compose_counts_overdue_credentials(): void
    {
        // APP_KEY is always present (the encrypter needs it) and the
        // dev .env may expose more surfaces — seed a fresh rotation for
        // EVERY catalog entry so the only actionable surface is the
        // one this test deliberately makes overdue.
        foreach (OpsCredentialInventoryService::CATALOG as $entry) {
            OpsCredential::firstOrCreate(
                ['key' => $entry['key']],
                ['last_rotated_at' => now()],
            );
        }

        // slack-webhooks is "configured" via the alert webhook URL, and
        // the ledger says it was last rotated 200 days ago (cadence 180).
        OpsCredential::where('key', 'slack-webhooks')->update([
            'last_rotated_at' => now()->subDays(200),
        ]);

        $credentials = $this->section($this->service()->compose(), 'credentials');

        $this->assertSame('attention', $credentials['status']);
        $this->assertStringContainsString('1 credential surface(s) need rotation', $credentials['title']);
        $this->assertStringContainsString('09:00 reminder', implode(' / ', $credentials['lines']));
    }

    public function test_compose_reports_operator_activity(): void
    {
        // Quiet day first.
        $activity = $this->section($this->service()->compose(), 'activity');
        $this->assertSame('ok', $activity['status']);
        $this->assertStringContainsString('no operator activity', $activity['title']);

        // A busy day: two operators run diagnostics, one audited action.
        $application = $this->app();
        $operatorA = User::factory()->create(['name' => 'Ada Operator']);
        $operatorB = User::factory()->create(['name' => 'Grace Operator']);

        foreach ([1, 2, 3] as $i) {
            OpsDiagnosticRun::create([
                'diagnostic_id' => 'database.connectivity',
                'ops_application_id' => $application->id,
                'actor_id' => $operatorA->id,
                'source' => 'manual',
                'status' => 'healthy',
                'summary' => 'fine',
                'findings' => [],
                'interpretation' => '',
                'next_steps' => [],
                'duration_ms' => 10,
                'created_at' => now()->subHours($i),
            ]);
        }

        OpsDiagnosticRun::create([
            'diagnostic_id' => 'redis.connectivity',
            'ops_application_id' => $application->id,
            'actor_id' => $operatorB->id,
            'source' => 'manual',
            'status' => 'healthy',
            'summary' => 'fine',
            'findings' => [],
            'interpretation' => '',
            'next_steps' => [],
            'duration_ms' => 10,
            'created_at' => now(),
        ]);

        AdminAuditLog::create([
            'actor_id' => $operatorA->id,
            'action' => 'ops.access.granted',
            'target_type' => 'App\Models\User',
            'target_id' => $operatorB->id,
            'payload' => null,
            'ip' => '127.0.0.1',
            'created_at' => now(),
        ]);

        $activity = $this->section($this->service()->compose(), 'activity');
        $lines = implode(' / ', $activity['lines']);

        $this->assertStringContainsString('operator activity', $activity['title']);
        $this->assertStringContainsString('3 diagnostic run(s) by Ada Operator', $lines);
        $this->assertStringContainsString('1 diagnostic run(s) by Grace Operator', $lines);
        $this->assertStringContainsString('1 audited ops action(s): ops.access.granted', $lines);
    }

    // ── Fail-soft ───────────────────────────────────────────────────────

    public function test_a_throwing_data_source_degrades_only_its_section(): void
    {
        $this->app->instance(OpsStatusTilesService::class, new class extends OpsStatusTilesService
        {
            public function backupStatus(): array
            {
                throw new \RuntimeException('backup probe exploded');
            }

            public function webhookStatus(): array
            {
                throw new \RuntimeException('webhook probe exploded');
            }
        });

        $digest = $this->service()->compose();

        $backups = $this->section($digest, 'backups');
        $this->assertSame('unavailable', $backups['status']);
        $this->assertStringContainsString('backup probe exploded', implode(' / ', $backups['lines']));

        $webhooks = $this->section($digest, 'webhooks');
        $this->assertSame('unavailable', $webhooks['status']);

        // The rest of the briefing still composed.
        $this->assertContains('health', array_column($digest['sections'], 'key'));
        $this->assertContains('activity', array_column($digest['sections'], 'key'));
    }

    // ── render() ────────────────────────────────────────────────────────

    public function test_render_contains_sections_and_footer(): void
    {
        $application = $this->app(['name' => 'Render Service']);
        OpsIncident::create([
            'ops_application_id' => $application->id,
            'title' => 'Rendered incident title',
            'severity' => 'warning',
            'status' => 'open',
            'correlation_key' => 'key-'.uniqid(),
            'event_count' => 1,
            'first_event_at' => now(),
            'last_event_at' => now(),
        ]);

        config(['app.url' => 'https://ops.example.test']);
        $text = $this->service()->render($this->service()->compose());

        $this->assertStringContainsString('PLATFORM:', $text);
        $this->assertStringContainsString('INCIDENTS:', $text);
        $this->assertStringContainsString('OPERATOR ACTIVITY:', $text);
        $this->assertStringContainsString('Rendered incident title', $text);
        $this->assertStringContainsString('Full detail: https://ops.example.test/ops', $text);

        // The scheduled envelope quotes the message verbatim — no
        // section key may leak a raw array.
        $this->assertStringNotContainsString('Array', $text);
    }

    // ── send() ──────────────────────────────────────────────────────────

    public function test_scheduled_send_posts_to_slack_and_stamps(): void
    {
        $result = $this->service()->send('scheduled');

        $this->assertTrue($result['sent']);
        $this->assertGreaterThan(5, $result['sections']);

        $messages = array_values(array_filter($this->slackMessages(), fn ($m) => $m !== ''));
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('PLATFORM:', $messages[0]);

        $lastSent = $this->service()->lastSent();
        $this->assertNotNull($lastSent);
        $this->assertSame('scheduled', $lastSent['trigger']);
    }

    public function test_scheduled_send_deduplicates_within_the_info_ttl(): void
    {
        $this->service()->send('scheduled');
        $this->service()->send('scheduled');

        $messages = array_values(array_filter($this->slackMessages(), fn ($m) => $m !== ''));
        $this->assertCount(1, $messages, 'A double-fired schedule must not double-post (dedup key ops.morning.digest).');
    }

    public function test_manual_send_bypasses_the_dedup(): void
    {
        $this->service()->send('scheduled');
        $this->service()->send('manual'); // the test send — must arrive

        $messages = array_values(array_filter($this->slackMessages(), fn ($m) => $m !== ''));
        $this->assertCount(2, $messages, 'A manual test send deliberately bypasses the daily dedup — a silent test send looks exactly like a broken webhook.');

        $this->assertSame('manual', $this->service()->lastSent()['trigger']);
    }

    // ── The command ─────────────────────────────────────────────────────

    public function test_command_sends_and_exits_zero(): void
    {
        $this->artisan('ops:send-morning-digest')
            ->expectsOutputToContain('Morning digest sent')
            ->assertSuccessful();

        $messages = array_values(array_filter($this->slackMessages(), fn ($m) => $m !== ''));
        $this->assertCount(1, $messages);
    }

    public function test_command_kill_switch_is_a_clean_no_op(): void
    {
        config(['ops.digest.enabled' => false]);

        $this->artisan('ops:send-morning-digest')
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_command_is_never_fatal(): void
    {
        $this->app->instance(OpsMorningDigestService::class, new class extends OpsMorningDigestService
        {
            public function __construct()
            {
                // bypass parent constructor — only send() is exercised
            }

            public function send(string $trigger = 'scheduled'): array
            {
                throw new \RuntimeException('catastrophic digest failure');
            }
        });

        $this->artisan('ops:send-morning-digest')
            ->expectsOutputToContain('Morning digest failed')
            ->assertSuccessful();
    }

    // ── Routes + UI ─────────────────────────────────────────────────────

    public function test_digest_preview_is_viewer_visible(): void
    {
        foreach (['viewer', 'operator'] as $level) {
            $this->asTier($level)
                ->get(route('ops.digest.index'))
                ->assertOk()
                ->assertSee('Morning Digest')
                ->assertSee('The exact Slack message');
        }

        $this->asMfaSuperAdmin()
            ->get(route('ops.digest.index'))
            ->assertOk()
            ->assertSee('Send now');
    }

    public function test_digest_preview_denied_without_a_grant(): void
    {
        $plain = User::factory()->withMfa()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($plain)
            ->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->get(route('ops.digest.index'))
            ->assertForbidden();
    }

    public function test_viewer_sees_no_send_button(): void
    {
        $this->asTier('viewer')
            ->get(route('ops.digest.index'))
            ->assertOk()
            ->assertDontSee('Send now');
    }

    public function test_manual_send_is_super_admin_only_and_audited(): void
    {
        // Viewer and operator: 403 at the ROUTE level.
        foreach (['viewer', 'operator'] as $level) {
            $this->asTier($level)
                ->post(route('ops.digest.send'))
                ->assertForbidden();
        }

        // Super-admin: redirects back, posts to Slack, writes the audit row.
        $this->asMfaSuperAdmin()
            ->post(route('ops.digest.send'))
            ->assertRedirect(route('ops.digest.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'ops.digest.sent',
        ]);

        $payload = AdminAuditLog::where('action', 'ops.digest.sent')->first()->payload;
        $this->assertSame('manual', $payload['trigger'] ?? null);

        $messages = array_values(array_filter($this->slackMessages(), fn ($m) => $m !== ''));
        $this->assertCount(1, $messages);
    }

    public function test_preview_shows_disabled_banner_when_switched_off(): void
    {
        config(['ops.digest.enabled' => false]);

        $this->asMfaSuperAdmin()
            ->get(route('ops.digest.index'))
            ->assertOk()
            ->assertSee('OPS_MORNING_DIGEST_ENABLED=false');
    }
}
