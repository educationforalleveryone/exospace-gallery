<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use App\Ops\Services\OpsSweepStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 7 — the sweep-cadence measurement surface.
 *
 * The cadence MECHANISM shipped in Iteration 6 untuned by design
 * ("measure, then set cadences"). OpsSweepStatusService is the measure
 * half — these tests pin:
 *
 *   1. Every configured sweep check appears with honest defaults
 *      (no cadence = every sweep; no stamp = never probed).
 *   2. Configured cadences surface, including the below-interval
 *      rejection (a cadence finer than the sweep itself is noise).
 *   3. The last-probe stamp: age from the cache, garbage-safe.
 *   4. Open-finding detection via the same title the sweep command
 *      writes; resolved findings stop flagging.
 *   5. Config mistakes (unknown ids, application-scoped ids) stay
 *      VISIBLE as ignored rows — a typo must not silently shrink
 *      the watch.
 *   6. The Diagnostics page panel renders for every read tier, with
 *      the disabled-sweep banner when the watch is off.
 */
class OpsSweepStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'services.operational_alerts.webhook_url' => null,
            'services.operational_alerts.critical_webhook_url' => null,
            'ops.sweeps.enabled' => true,
            'ops.sweeps.diagnostics' => [
                'database.connectivity', 'redis.connectivity', 'queue.health', 'server.disk', 'app.scheduler',
            ],
            'ops.sweeps.cadences' => [],
        ]);
    }

    private function service(): OpsSweepStatusService
    {
        return app(OpsSweepStatusService::class);
    }

    private function row(array $status, string $id): array
    {
        foreach ($status['checks'] as $check) {
            if ($check['id'] === $id) {
                return $check;
            }
        }

        $this->fail("Sweep-status row '{$id}' not present.");
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

    // ── Rows + defaults ─────────────────────────────────────────────────

    public function test_every_configured_check_appears_with_honest_defaults(): void
    {
        $status = $this->service()->status();

        $this->assertTrue($status['enabled']);
        $this->assertCount(5, $status['checks']);
        $this->assertSame([], $status['ignored']);

        foreach ($status['checks'] as $check) {
            $this->assertNull($check['cadence_minutes']);
            $this->assertStringContainsString('every sweep', $check['cadence_label']);
            $this->assertNull($check['last_probe_at']);
            $this->assertNull($check['last_probe_minutes']);
            $this->assertFalse($check['has_open_event']);
            $this->assertNotSame('', $check['label']);
        }
    }

    public function test_configured_cadences_surface_and_below_interval_entries_are_ignored(): void
    {
        config(['ops.sweeps.cadences' => [
            'server.disk' => 60,
            'database.connectivity' => 5, // below the 15-min sweep interval
            'bogus.check' => 60,          // not in the sweep set
        ]]);

        $status = $this->service()->status();

        $disk = $this->row($status, 'server.disk');
        $this->assertSame(60, $disk['cadence_minutes']);
        $this->assertSame('every 60 min while healthy', $disk['cadence_label']);

        // A cadence finer than the sweep itself cannot be honored —
        // rendered as the every-sweep default, exactly what the sweep
        // command would do with it.
        $database = $this->row($status, 'database.connectivity');
        $this->assertNull($database['cadence_minutes']);
    }

    // ── The last-probe stamp ────────────────────────────────────────────

    public function test_last_probe_age_comes_from_the_cache_stamp(): void
    {
        Cache::put('ops:sweep:last:server.disk', now()->subMinutes(42), now()->addDay());

        $disk = $this->row($this->service()->status(), 'server.disk');

        $this->assertNotNull($disk['last_probe_at']);
        $this->assertSame(42, $disk['last_probe_minutes']);
    }

    public function test_a_garbage_stamp_reads_as_never_probed(): void
    {
        Cache::put('ops:sweep:last:server.disk', 'not-a-carbon', now()->addDay());

        $disk = $this->row($this->service()->status(), 'server.disk');

        $this->assertNull($disk['last_probe_at']);
        $this->assertNull($disk['last_probe_minutes']);
    }

    // ── Open findings ───────────────────────────────────────────────────

    public function test_an_open_sweep_event_flags_the_check_until_resolved(): void
    {
        $application = OpsApplication::create([
            'slug' => 'self', 'name' => 'Self', 'provider' => 'coolify',
            'kind' => 'application', 'environment' => 'production',
            'status' => 'running:healthy', 'health' => 'running',
        ]);

        $event = OpsEvent::create([
            'fingerprint' => sha1(uniqid('', true)),
            'ops_application_id' => $application->id,
            'source' => 'sweep',
            'category' => 'INFRASTRUCTURE',
            'severity' => 'warning',
            'title' => 'Automated sweep: Disk usage', // the command's stable title
            'message' => 'degraded',
            'status' => 'open',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->assertTrue($this->row($this->service()->status(), 'server.disk')['has_open_event']);

        $event->status = 'resolved';
        $event->resolved_at = now();
        $event->save();

        $this->assertFalse($this->row($this->service()->status(), 'server.disk')['has_open_event']);
    }

    // ── Config mistakes stay visible ────────────────────────────────────

    public function test_unknown_and_application_scoped_ids_render_as_ignored(): void
    {
        config(['ops.sweeps.diagnostics' => [
            'database.connectivity', // fine
            'bogus.check',           // unknown id
            'app.recent-errors',     // application-scoped
        ]]);

        $status = $this->service()->status();

        $this->assertCount(1, $status['checks']);
        $this->assertCount(2, $status['ignored']);

        $ids = array_column($status['ignored'], 'id');
        $this->assertContains('bogus.check', $ids);
        $this->assertContains('app.recent-errors', $ids);

        foreach ($status['ignored'] as $ignored) {
            $this->assertNotSame('', (string) $ignored['reason']);
        }
    }

    // ── The Diagnostics page panel ──────────────────────────────────────

    public function test_the_panel_renders_on_the_diagnostics_page(): void
    {
        Cache::put('ops:sweep:last:server.disk', now()->subMinutes(30), now()->addDay());

        $this->asMfaSuperAdmin()
            ->get(route('ops.diagnostics.index'))
            ->assertOk()
            ->assertSee('Sweep cadences')
            ->assertSee('Last probed')
            ->assertSee('Disk usage')
            ->assertSee('every sweep')
            ->assertSee('30 min ago');
    }

    public function test_the_disabled_sweep_shows_its_banner(): void
    {
        config(['ops.sweeps.enabled' => false]);

        $this->asMfaSuperAdmin()
            ->get(route('ops.diagnostics.index'))
            ->assertOk()
            ->assertSee('OPS_SWEEP_ENABLED=false');
    }
}
