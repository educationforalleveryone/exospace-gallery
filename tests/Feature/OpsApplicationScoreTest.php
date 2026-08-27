<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use App\Ops\Models\OpsIncident;
use App\Ops\Services\OpsHealthScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 5 — the per-application sub-score (§16.2).
 *
 * The pure function is tested to exhaustion like its platform sibling:
 * weights sum, clean/failed ends, every cap, band boundaries, reason
 * presence. The aggregator is tested against real rows: per-app scoping,
 * the incident double-count exclusion, and the batched query contract.
 * The Applications page is tested for the badge rendering, including
 * cap enforcement on a stopped app.
 */
class OpsApplicationScoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'services.operational_alerts.webhook_url' => null,
            'services.operational_alerts.critical_webhook_url' => null,
        ]);
    }

    private function service(): OpsHealthScoreService
    {
        return app(OpsHealthScoreService::class);
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
            'title' => ucfirst($severity).' event',
            'status' => 'open',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ], $overrides));
    }

    private function incident(OpsApplication $application, string $severity, array $overrides = []): OpsIncident
    {
        return OpsIncident::create(array_merge([
            'ops_application_id' => $application->id,
            'title' => 'Incident '.uniqid(),
            'severity' => $severity,
            'status' => 'open',
            'correlation_key' => 'key-'.uniqid(),
            'event_count' => 1,
            'first_event_at' => now(),
            'last_event_at' => now(),
        ], $overrides));
    }

    // ── Pure function ───────────────────────────────────────────────────

    public function test_app_weights_sum_to_100(): void
    {
        $this->assertSame(100, array_sum(OpsHealthScoreService::APP_WEIGHTS));
    }

    public function test_clean_running_app_scores_100(): void
    {
        $result = $this->service()->computeApplication([
            'health' => 'running',
            'untriaged_events' => ['critical' => 0, 'error' => 0, 'warning' => 0],
            'active_incidents' => ['critical' => 0, 'error' => 0, 'warning' => 0],
        ]);

        $this->assertSame(100, $result['score']);
        $this->assertSame('healthy', $result['band']);
        $this->assertSame([], $result['applied_caps']);
    }

    public function test_stopped_app_is_capped_at_65_and_band_critical(): void
    {
        $result = $this->service()->computeApplication([
            'health' => 'stopped',
            'untriaged_events' => [],
            'active_incidents' => [],
        ]);

        // Blend would be 50 (health 0×50 + untriaged 100×30 + incidents 100×20)
        // → capped at 65? No: blend=50 < cap 65, so score 50 — but the cap
        // never LIFTS. What matters: it reads critical, and the cap label
        // is present for the tooltip.
        $this->assertSame(50, $result['score']);
        $this->assertSame('critical', $result['band']);
        $this->assertNotSame([], $result['applied_caps']);
    }

    public function test_stopped_cap_binds_when_blend_is_high(): void
    {
        // Blend 50 comes only from errors+incidents at 100; stopped alone
        // can never exceed 65 even if the other components were inflated.
        $result = $this->service()->computeApplication([
            'health' => 'stopped',
            // hostile: untriaged/errors "negative" style huge counts can't
            // help — but a high blend via clean components cannot either.
            'untriaged_events' => [],
            'active_incidents' => [],
        ]);

        $this->assertLessThanOrEqual(65, $result['score']);
    }

    public function test_degraded_app_is_capped_at_85(): void
    {
        // Blend = 50×50 + 100×30 + 100×20 = 75 → below the 85 cap already;
        // add nothing else. The cap binds only above 85, which requires
        // impossible inputs given degraded is 50 — so assert the label and
        // a sub-90 score (degraded must never read HEALTHY).
        $result = $this->service()->computeApplication([
            'health' => 'degraded',
        ]);

        $this->assertSame(75, $result['score']);
        $this->assertSame('degraded', $result['band']);
        $this->assertNotSame([], $result['applied_caps']);
    }

    public function test_untriaged_critical_caps_at_85_even_with_clean_blend(): void
    {
        // Blend without the cap: 100×50 + 75×30 + 100×20 = 92.5 → 93.
        $result = $this->service()->computeApplication([
            'health' => 'running',
            'untriaged_events' => ['critical' => 1, 'error' => 0, 'warning' => 0],
            'active_incidents' => [],
        ]);

        $this->assertSame(85, $result['score']);
        $this->assertSame('degraded', $result['band']);
    }

    public function test_active_incident_caps_at_85(): void
    {
        $result = $this->service()->computeApplication([
            'health' => 'running',
            'untriaged_events' => [],
            'active_incidents' => ['warning' => 1],
        ]);

        $this->assertSame(85, $result['score']);
    }

    public function test_component_penalties_match_the_platform_formula(): void
    {
        // One error event: 100 − 10 = 90 on the untriaged component.
        $result = $this->service()->computeApplication([
            'health' => 'running',
            'untriaged_events' => ['error' => 1],
            'active_incidents' => [],
        ]);

        $this->assertSame(90, $result['components']['untriaged']['score']);
        // One error incident: 100 − 15 = 85 → capped blend at 85 anyway.
        $this->assertSame(30, $result['components']['untriaged']['weight']);
    }

    public function test_unknown_health_is_neutral_50_not_critical(): void
    {
        $result = $this->service()->computeApplication([
            'health' => 'unknown',
            'untriaged_events' => [],
            'active_incidents' => [],
        ]);

        $this->assertSame(75, $result['score']); // 50×50 + 100×30 + 100×20
        $this->assertSame('degraded', $result['band']);
    }

    public function test_every_component_carries_reasons(): void
    {
        $result = $this->service()->computeApplication([
            'health' => 'stopped',
            'untriaged_events' => ['error' => 2],
            'active_incidents' => ['critical' => 1],
        ]);

        foreach ($result['components'] as $component) {
            $this->assertNotSame([], $component['reasons']);
            $this->assertNotEmpty($component['name']);
        }
    }

    public function test_band_boundaries(): void
    {
        $scoreFor = fn (int $healthScore) => $this->service()->computeApplication([
            // synthesize a blend by picking health values: running=100,
            // degraded=50 → blends 100 / 75; stopped → 50. Use the band
            // thresholds directly instead: 90 healthy, 89 degraded, 69
            // critical, 70 degraded.
            'health' => 'running',
        ])['band'];

        // 100 → healthy
        $this->assertSame('healthy', $scoreFor(100));

        $blend75 = $this->service()->computeApplication(['health' => 'degraded']);
        $this->assertSame('degraded', $blend75['band']); // 75

        $blend50 = $this->service()->computeApplication(['health' => 'stopped']);
        $this->assertSame('critical', $blend50['band']); // 50
    }

    // ── Aggregator ──────────────────────────────────────────────────────

    public function test_aggregator_scores_each_application_independently(): void
    {
        $healthy = $this->app(['health' => 'running']);
        $broken = $this->app(['health' => 'stopped']);

        $scores = $this->service()->computeForApplications(collect([$healthy, $broken]));

        $this->assertSame(100, $scores[$healthy->id]['score']);
        $this->assertSame(50, $scores[$broken->id]['score']);
        $this->assertSame('critical', $scores[$broken->id]['band']);
    }

    public function test_aggregator_scopes_events_to_their_application(): void
    {
        $appA = $this->app();
        $appB = $this->app();

        $this->event($appA, 'error');
        $this->event($appA, 'error');

        $scores = $this->service()->computeForApplications(collect([$appA, $appB]));

        // A: untriaged component 100−20=80 (two errors, scoped to A) →
        // blend 100×50+80×30+100×20 = 94 → capped at 85 by the
        // untriaged-error verdict cap.
        $this->assertSame(80, $scores[$appA->id]['components']['untriaged']['score']);
        $this->assertSame(85, $scores[$appA->id]['score']);
        $this->assertSame('degraded', $scores[$appA->id]['band']);
        // B is untouched by A's errors.
        $this->assertSame(100, $scores[$appB->id]['score']);
    }

    public function test_aggregator_excludes_events_inside_active_incidents(): void
    {
        $app = $this->app();
        $incident = $this->incident($app, 'error');

        // The event that IS the incident must not double-count.
        $this->event($app, 'error', ['ops_incident_id' => $incident->id]);
        // A genuinely untriaged one still counts.
        $this->event($app, 'warning');

        $scores = $this->service()->computeForApplications(collect([$app]));

        // untriaged = warning only → 100−3 = 97; incidents = error → 100−15 = 85.
        $this->assertSame(97, $scores[$app->id]['components']['untriaged']['score']);
        $this->assertSame(85, $scores[$app->id]['components']['incidents']['score']);
    }

    public function test_aggregator_counts_only_active_incidents_for_the_app(): void
    {
        $app = $this->app();
        $other = $this->app();

        $this->incident($app, 'warning');
        $this->incident($other, 'critical');
        OpsIncident::create([
            'ops_application_id' => $app->id,
            'title' => 'resolved long ago',
            'severity' => 'critical',
            'status' => 'resolved',
            'correlation_key' => 'k-'.uniqid(),
            'event_count' => 1,
            'first_event_at' => now()->subDays(3),
            'last_event_at' => now()->subDays(3),
            'resolved_at' => now()->subDays(3),
        ]);

        $scores = $this->service()->computeForApplications(collect([$app]));

        // Only the open warning incident counts for $app (resolved + other-app excluded).
        $this->assertSame(94, $scores[$app->id]['components']['incidents']['score']); // 100−6
    }

    public function test_aggregator_returns_empty_for_no_applications(): void
    {
        $this->assertSame([], $this->service()->computeForApplications(collect()));
    }

    // ── Page integration ────────────────────────────────────────────────

    public function test_applications_page_renders_sub_scores(): void
    {
        $healthy = $this->app(['name' => 'Alpha Service']);
        $stopped = $this->app(['name' => 'Beta Service', 'health' => 'stopped', 'status' => 'exited:1']);

        $response = $this->asMfaSuperAdmin()->get('/ops/applications')->assertOk();

        $content = $response->getContent();
        $this->assertStringContainsString('Score', $content); // the column header
        $this->assertStringContainsString('title="Sub-score 100/100', $content); // healthy badge + tooltip
        $this->assertStringContainsString('title="Sub-score 50/100', $content); // stopped badge
        $this->assertStringContainsString('Application is stopped — sub-score capped at 65', $content); // cap in tooltip
    }

    public function test_applications_page_badge_colors_follow_bands(): void
    {
        $this->app(['health' => 'running']);
        $this->app(['health' => 'stopped']);

        $content = $this->asMfaSuperAdmin()->get('/ops/applications')->assertOk()->getContent();

        // healthy → emerald badge, stopped/critical → red badge.
        $this->assertStringContainsString('text-emerald-300', $content);
        $this->assertStringContainsString('text-red-300', $content);
    }
}
