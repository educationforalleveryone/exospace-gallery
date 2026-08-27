<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ops\Services\OpsHealthScoreService;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 4 — the health score FORMULA.
 *
 * The brief demanded "no meaningless numbers": every score must be
 * reproducible from documented weights, every component must explain
 * itself, and the number may never contradict the status verdict
 * (verdict caps). These tests pin all three properties to the formula
 * itself, independent of any live data source.
 */
class OpsHealthScoreTest extends TestCase
{
    /**
     * A perfectly healthy platform input — every test scenario derives
     * from this by mutation, so expected values stay derivable by hand.
     */
    private function baseline(): array
    {
        return [
            'self_status' => 'healthy',
            'self_reasons' => [],
            'applications' => ['running' => 3, 'degraded' => 0, 'stopped' => 0, 'unknown' => 0],
            'untriaged_events' => ['critical' => 0, 'error' => 0, 'warning' => 0],
            'active_incidents' => ['critical' => 0, 'error' => 0, 'warning' => 0],
            'backup_disks' => ['ok' => 2, 'stale' => 0, 'missing' => 0, 'unreadable' => 0],
            'failed_webhooks' => 0,
        ];
    }

    private function service(): OpsHealthScoreService
    {
        return app(OpsHealthScoreService::class);
    }

    public function test_weights_sum_to_exactly_100(): void
    {
        $this->assertSame(100, array_sum(OpsHealthScoreService::WEIGHTS));
    }

    public function test_clean_platform_scores_100_healthy(): void
    {
        $result = $this->service()->compute($this->baseline());

        $this->assertSame(100, $result['score']);
        $this->assertSame('healthy', $result['band']);
        $this->assertSame([], $result['applied_caps']);

        foreach ($result['components'] as $component) {
            $this->assertSame(100, $component['score'], "Component '{$component['name']}' should be 100 on a clean platform");
        }
    }

    public function test_score_is_always_bounded_0_100_even_with_hostile_input(): void
    {
        $hostile = array_merge($this->baseline(), [
            'self_status' => 'critical',
            'applications' => ['running' => 0, 'degraded' => 0, 'stopped' => 99, 'unknown' => 0],
            'untriaged_events' => ['critical' => 999, 'error' => 999, 'warning' => 999],
            'active_incidents' => ['critical' => 999, 'error' => 999, 'warning' => 999],
            'backup_disks' => ['ok' => 0, 'stale' => 50, 'missing' => 50, 'unreadable' => 0],
            'failed_webhooks' => 9999,
        ]);

        $result = $this->service()->compute($hostile);

        $this->assertSame(0, $result['score']);
        $this->assertSame('critical', $result['band']);
    }

    // ── Verdict caps: the score never contradicts the status label ──────

    public function test_host_critical_caps_score_at_60_critical_band(): void
    {
        $input = array_merge($this->baseline(), ['self_status' => 'critical', 'self_reasons' => ['Database unreachable']]);

        $result = $this->service()->compute($input);

        // Blend alone would be 70 (100 − 30) — the cap forces the critical band.
        $this->assertSame(60, $result['score']);
        $this->assertSame('critical', $result['band']);
        $this->assertNotEmpty($result['applied_caps']);
        $this->assertSame(0, $result['components']['host']['score']);
    }

    public function test_stopped_application_caps_score_at_65_critical_band(): void
    {
        // 3 running + 1 stopped: blend alone = 94 (healthy band) — a stopped
        // app is a CRITICAL verdict on the dashboard; the cap enforces it.
        $input = array_merge($this->baseline(), [
            'applications' => ['running' => 3, 'degraded' => 0, 'stopped' => 1, 'unknown' => 0],
        ]);

        $result = $this->service()->compute($input);

        $this->assertSame(65, $result['score']);
        $this->assertSame('critical', $result['band']);
        $this->assertStringContainsString('stopped', $result['applied_caps'][1] ?? $result['applied_caps'][0]);
    }

    public function test_stale_backup_disk_caps_score_at_65_critical_band(): void
    {
        // Backups gone stale: the protection component drops AND the
        // verdict cap applies — a platform without fresh backups is never
        // "healthy".
        $input = array_merge($this->baseline(), [
            'backup_disks' => ['ok' => 1, 'stale' => 1, 'missing' => 0, 'unreadable' => 0],
        ]);

        $result = $this->service()->compute($input);

        // Backups (100+0)/2 = 50 × 0.7 + webhooks 100 × 0.3 = 65.
        $this->assertSame(65, $result['components']['protection']['score']);
        $this->assertSame(65, $result['score']); // capped from blend 97
        $this->assertSame('critical', $result['band']);
    }

    public function test_missing_backup_disk_scores_protection_zero(): void
    {
        $input = array_merge($this->baseline(), [
            'backup_disks' => ['ok' => 0, 'stale' => 0, 'missing' => 1, 'unreadable' => 0],
        ]);

        $result = $this->service()->compute($input);

        // backups (0×100)/1 → 0; webhooks 100 → 0.7×0 + 0.3×100 = 30.
        $this->assertSame(30, $result['components']['protection']['score']);
        $this->assertSame(65, $result['score']); // capped
        $this->assertStringContainsString('NO backup archive', implode(' ', $result['components']['protection']['reasons']));
    }

    public function test_degraded_host_caps_at_85(): void
    {
        $input = array_merge($this->baseline(), ['self_status' => 'degraded', 'self_reasons' => ['Cache probe failed']]);

        $result = $this->service()->compute($input);

        // Blend = 85 exactly (50×0.30 + 70) — degraded band, cap aligned.
        $this->assertSame(85, $result['score']);
        $this->assertSame('degraded', $result['band']);
        $this->assertSame(50, $result['components']['host']['score']);
    }

    public function test_single_untriaged_critical_event_caps_at_85(): void
    {
        $input = array_merge($this->baseline(), [
            'untriaged_events' => ['critical' => 1, 'error' => 0, 'warning' => 0],
        ]);

        $result = $this->service()->compute($input);

        $this->assertSame(85, $result['score']);
        $this->assertSame('degraded', $result['band']);
        $this->assertSame(75, $result['components']['untriaged']['score']); // 100 − 25×1
    }

    public function test_active_incident_caps_at_85(): void
    {
        $input = array_merge($this->baseline(), [
            'active_incidents' => ['critical' => 0, 'error' => 1, 'warning' => 0],
        ]);

        $result = $this->service()->compute($input);

        $this->assertSame(85, $result['score']);
        $this->assertSame('degraded', $result['band']);
        $this->assertSame(85, $result['components']['incidents']['score']); // 100 − 15×1
    }

    public function test_warning_only_platform_stays_in_the_blend(): void
    {
        // Warnings never degrade the platform STATUS — so no cap may fire;
        // the blend alone decides (and 10 warnings legitimately hurt it).
        $input = array_merge($this->baseline(), [
            'untriaged_events' => ['critical' => 0, 'error' => 0, 'warning' => 10],
        ]);

        $result = $this->service()->compute($input);

        $this->assertSame([], $result['applied_caps']);
        $this->assertSame(70, $result['components']['untriaged']['score']); // 100 − 3×10
        $this->assertSame(94, $result['score']); // 100 − 20×0.30
        $this->assertSame('healthy', $result['band']);
    }

    // ── Component math ───────────────────────────────────────────────────

    public function test_applications_component_is_the_health_average(): void
    {
        // 2 running + 1 degraded + 1 unknown → (200 + 50 + 50) / 4 = 75.
        $input = array_merge($this->baseline(), [
            'applications' => ['running' => 2, 'degraded' => 1, 'stopped' => 0, 'unknown' => 1],
        ]);

        $result = $this->service()->compute($input);

        $this->assertSame(75, $result['components']['applications']['score']);
        $this->assertSame(85, $result['score']); // degraded-app cap
    }

    public function test_no_applications_synced_is_neutral_50_with_reason(): void
    {
        $input = array_merge($this->baseline(), [
            'applications' => ['running' => 0, 'degraded' => 0, 'stopped' => 0, 'unknown' => 0],
        ]);

        $result = $this->service()->compute($input);

        $this->assertSame(50, $result['components']['applications']['score']);
        $this->assertStringContainsString('No applications synced', $result['components']['applications']['reasons'][0]);
        $this->assertSame([], $result['applied_caps']); // unknown is not a verdict
    }

    public function test_untriaged_penalties_scale_by_severity(): void
    {
        $oneError = $this->service()->compute(array_merge($this->baseline(), [
            'untriaged_events' => ['critical' => 0, 'error' => 1, 'warning' => 0],
        ]));
        $this->assertSame(90, $oneError['components']['untriaged']['score']);

        $twoCritical = $this->service()->compute(array_merge($this->baseline(), [
            'untriaged_events' => ['critical' => 2, 'error' => 0, 'warning' => 0],
        ]));
        $this->assertSame(50, $twoCritical['components']['untriaged']['score']);
    }

    public function test_failed_webhook_tiers_in_protection_component(): void
    {
        $clean = $this->service()->compute($this->baseline());
        $this->assertSame(100, $clean['components']['protection']['score']);

        // 1–5 failed: webhook part 50 → 0.7×100 + 0.3×50 = 85.
        $few = $this->service()->compute(array_merge($this->baseline(), ['failed_webhooks' => 3]));
        $this->assertSame(85, $few['components']['protection']['score']);
        $this->assertSame([], $few['applied_caps']); // failed webhooks are a component signal, not a verdict cap

        // >5 failed: webhook part 0 → 0.7×100 + 0.3×0 = 70.
        $many = $this->service()->compute(array_merge($this->baseline(), ['failed_webhooks' => 7]));
        $this->assertSame(70, $many['components']['protection']['score']);
        $this->assertStringContainsString('7 failed webhooks', implode(' ', $many['components']['protection']['reasons']));
    }

    public function test_unreadable_backup_disk_counts_half(): void
    {
        // 1 ok + 1 unreadable → (100 + 50)/2 = 75 backup part; webhooks 100
        // → 0.7×75 + 0.3×100 = 82.5 → 83. No cap (unreadable ≠ stale/missing).
        $input = array_merge($this->baseline(), [
            'backup_disks' => ['ok' => 1, 'stale' => 0, 'missing' => 0, 'unreadable' => 1],
        ]);

        $result = $this->service()->compute($input);

        $this->assertSame(83, $result['components']['protection']['score']);
        $this->assertSame([], $result['applied_caps']);
    }

    // ── The invariants behind "no meaningless numbers" ──────────────────

    public function test_every_component_below_100_carries_reasons(): void
    {
        $scenarios = [
            ['self_status' => 'critical', 'self_reasons' => ['Database unreachable']],
            ['applications' => ['running' => 1, 'degraded' => 0, 'stopped' => 2, 'unknown' => 0]],
            ['untriaged_events' => ['critical' => 3, 'error' => 2, 'warning' => 1]],
            ['active_incidents' => ['critical' => 1, 'error' => 0, 'warning' => 0]],
            ['backup_disks' => ['ok' => 0, 'stale' => 1, 'missing' => 0, 'unreadable' => 0]],
            ['failed_webhooks' => 4],
        ];

        foreach ($scenarios as $mutation) {
            $result = $this->service()->compute(array_merge($this->baseline(), $mutation));

            foreach ($result['components'] as $component) {
                if ($component['score'] < 100) {
                    $this->assertNotEmpty(
                        $component['reasons'],
                        "Component '{$component['name']}' at {$component['score']}/100 must explain itself",
                    );
                }
            }
        }
    }

    public function test_reported_score_is_reproducible_from_components_and_caps(): void
    {
        $mutations = [
            ['self_status' => 'degraded', 'self_reasons' => ['Cache probe failed']],
            ['applications' => ['running' => 2, 'degraded' => 0, 'stopped' => 1, 'unknown' => 0]],
            ['untriaged_events' => ['critical' => 0, 'error' => 4, 'warning' => 2]],
        ];

        foreach ($mutations as $mutation) {
            $result = $this->service()->compute(array_merge($this->baseline(), $mutation));

            $blend = 0;
            foreach ($result['components'] as $component) {
                $blend += $component['score'] * $component['weight'];
            }
            $blend = (int) round($blend / 100);

            $expected = $blend;
            foreach ($result['applied_caps'] as $capLabel) {
                // Caps are labeled with their limit — extract and apply min().
                if (preg_match('/capped at (\d+)/', $capLabel, $m)) {
                    $expected = min($expected, (int) $m[1]);
                }
            }

            $this->assertSame($expected, $result['score'], 'Score must equal min(blend, caps) — formula drift detected');
        }
    }

    public function test_band_boundaries(): void
    {
        $at90 = $this->service()->compute(array_merge($this->baseline(), [
            'untriaged_events' => ['critical' => 0, 'error' => 0, 'warning' => 17],
        ]));
        // 100 − 3×17 = 49 → blend = 100 − 20×0.51 = 90 (rounds to 90).
        $this->assertSame('healthy', $at90['band']);

        $at89 = $this->service()->compute(array_merge($this->baseline(), [
            'untriaged_events' => ['critical' => 0, 'error' => 0, 'warning' => 18],
        ]));
        // 100 − 54 = 46 → blend = 100 − 20×0.54 = 89.
        $this->assertSame(89, $at89['score']);
        $this->assertSame('degraded', $at89['band']);
    }

    public function test_multiple_caps_take_the_strongest(): void
    {
        // DB down AND an app stopped: caps 60 and 65 — 60 wins.
        $input = array_merge($this->baseline(), [
            'self_status' => 'critical',
            'self_reasons' => ['Database unreachable'],
            'applications' => ['running' => 2, 'degraded' => 0, 'stopped' => 1, 'unknown' => 0],
        ]);

        $result = $this->service()->compute($input);

        $this->assertSame(60, $result['score']);
        $this->assertGreaterThanOrEqual(2, count($result['applied_caps']));
    }

    public function test_compounding_problems_push_below_the_caps(): void
    {
        // A single stopped app alone scores exactly the cap (65). Stack
        // more problems on top and the BLEND itself falls below the cap —
        // the score keeps its resolution within the verdict.
        $input = array_merge($this->baseline(), [
            'self_status' => 'degraded',
            'self_reasons' => ['Cache probe failed'],
            'applications' => ['running' => 2, 'degraded' => 0, 'stopped' => 2, 'unknown' => 0],
            'untriaged_events' => ['critical' => 2, 'error' => 6, 'warning' => 0],
            'active_incidents' => ['critical' => 1, 'error' => 0, 'warning' => 0],
            'backup_disks' => ['ok' => 0, 'stale' => 0, 'missing' => 1, 'unreadable' => 0],
        ]);

        $result = $this->service()->compute($input);

        // host 50×0.30 + apps 50×0.25 + untriaged 0×0.20 + incidents 70×0.15
        // + protection 30×0.10 = 15+12.5+0+10.5+3 = 41 — well under every cap.
        $this->assertSame(41, $result['score']);
        $this->assertSame('critical', $result['band']);
    }
}
