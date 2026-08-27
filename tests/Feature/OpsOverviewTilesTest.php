<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProcessedWebhook;
use App\Models\User;
use App\Ops\Services\OpsStatusTilesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 4 — the overview's quantified surfaces.
 *
 * Covers the backup tile (fresh/stale/missing per disk), the webhook
 * ledger tile (failed counts + replay link), and the health score badge
 * + breakdown card on the overview — the "no meaningless numbers" rule
 * made visible: every number renders WITH its explanation.
 */
class OpsOverviewTilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Isolate backup reads: one fake disk, known folder.
        config([
            'backup.backup.destination.disks' => ['backups'],
            'backup.backup.name' => 'test-backups',
        ]);
        Storage::fake('backups');

        // Sentry bridge unconfigured — its tile state is covered by
        // OpsSentrySummaryTest; here it just must not break the page.
        config([
            'ops.sentry.api_token' => null,
            'ops.sentry.org' => null,
            'ops.sentry.projects' => [],
            'ops.sentry.base_url' => 'https://sentry.test',
        ]);
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

    private function putBackup(string $name, ?int $mtime = null): void
    {
        Storage::disk('backups')->put("test-backups/{$name}", str_repeat('z', 2048));

        if ($mtime !== null) {
            touch(Storage::disk('backups')->path("test-backups/{$name}"), $mtime);
        }
    }

    // ── Backup tile facts ────────────────────────────────────────────────

    public function test_fresh_backup_is_ok(): void
    {
        $this->putBackup('backup-fresh.zip', time() - 3600);

        $status = app(OpsStatusTilesService::class)->backupStatus();

        $this->assertSame('healthy', $status['status']);
        $this->assertCount(1, $status['disks']);
        $this->assertSame('ok', $status['disks'][0]['status']);
        $this->assertSame('backup-fresh.zip', $status['disks'][0]['newest_name']);
        $this->assertGreaterThan(0, $status['disks'][0]['newest_size']);
        $this->assertEqualsWithDelta(1.0, $status['disks'][0]['newest_age_hours'], 0.1);
    }

    public function test_stale_backup_is_critical(): void
    {
        // Newest archive 30 h old — past the 26 h threshold the alerting
        // service already uses.
        $this->putBackup('backup-old.zip', time() - 30 * 3600);

        $status = app(OpsStatusTilesService::class)->backupStatus();

        $this->assertSame('critical', $status['status']);
        $this->assertSame('stale', $status['disks'][0]['status']);
        $this->assertStringContainsString('30.0 hours old', implode(' ', $status['reasons']));
    }

    public function test_missing_backups_are_critical(): void
    {
        $status = app(OpsStatusTilesService::class)->backupStatus();

        $this->assertSame('critical', $status['status']);
        $this->assertSame('missing', $status['disks'][0]['status']);
        $this->assertNull($status['disks'][0]['newest_name']);
    }

    public function test_newest_of_multiple_archives_wins(): void
    {
        $this->putBackup('backup-old.zip', time() - 30 * 3600);
        $this->putBackup('backup-new.zip', time() - 1800);

        $status = app(OpsStatusTilesService::class)->backupStatus();

        $this->assertSame('ok', $status['disks'][0]['status'], 'The fresh archive answers the freshness question');
        $this->assertSame('backup-new.zip', $status['disks'][0]['newest_name']);
        $this->assertSame(2, $status['disks'][0]['file_count']);
    }

    public function test_backup_status_never_throws_on_unreadable_disk(): void
    {
        // A disk whose driver explodes must degrade, not crash.
        config(['backup.backup.destination.disks' => ['exploding']]);

        Storage::shouldReceive('disk')->with('exploding')->andThrow(new \RuntimeException('disk driver missing'));

        $status = app(OpsStatusTilesService::class)->backupStatus();

        $this->assertSame('degraded', $status['status']);
        $this->assertSame('unreadable', $status['disks'][0]['status']);
    }

    // ── Webhook tile facts ───────────────────────────────────────────────

    public function test_clean_ledger_is_healthy(): void
    {
        ProcessedWebhook::create([
            'message_id' => 'ok-1',
            'message_type' => 'ORDER_CREATED',
            'payload' => [],
            'status' => 'processed',
            'processed_at' => now(),
            'updated_at' => now(),
        ]);

        $status = app(OpsStatusTilesService::class)->webhookStatus();

        $this->assertSame('healthy', $status['status']);
        $this->assertSame(0, $status['failed_count']);
        $this->assertSame(1, $status['processed_24h']);
    }

    public function test_failed_webhooks_degrade_and_count(): void
    {
        foreach ([1, 2, 3] as $i) {
            ProcessedWebhook::create([
                'message_id' => 'fail-'.$i,
                'message_type' => 'FRAUD_STATUS_CHANGED',
                'payload' => [],
                'status' => 'failed',
                'updated_at' => now()->subHours($i),
            ]);
        }

        $status = app(OpsStatusTilesService::class)->webhookStatus();

        $this->assertSame('degraded', $status['status']);
        $this->assertSame(3, $status['failed_count']);
        $this->assertEqualsWithDelta(3.0, $status['oldest_failed_age_hours'], 0.2);
    }

    public function test_many_failed_webhooks_are_critical(): void
    {
        for ($i = 0; $i < 7; $i++) {
            ProcessedWebhook::create([
                'message_id' => 'fail-many-'.$i,
                'message_type' => 'ORDER_CREATED',
                'payload' => [],
                'status' => 'failed',
                'updated_at' => now(),
            ]);
        }

        $status = app(OpsStatusTilesService::class)->webhookStatus();

        $this->assertSame('critical', $status['status']);
        $this->assertSame(7, $status['failed_count']);
    }

    // ── The overview page renders it all ─────────────────────────────────

    public function test_overview_renders_score_badge_and_breakdown_with_reasons(): void
    {
        $this->putBackup('backup-fresh.zip', time() - 3600);

        $response = $this->asMfaSuperAdmin()->get('/ops');

        $response->assertStatus(200)
            ->assertSee('Health Score — Why the Number', false)
            ->assertSee('Host subsystems')
            ->assertSee('Applications')
            ->assertSee('Untriaged errors')
            ->assertSee('Active incidents')
            ->assertSee('Data protection')
            ->assertSee('/100');

        // The badge shows the same number as the breakdown.
        $this->assertMatchesRegularExpression('/\d+<\/span><span[^>]*>\/100<\/span>/', $response->getContent());
    }

    public function test_overview_renders_backup_tile_in_all_three_states(): void
    {
        // Fresh.
        $this->putBackup('backup-fresh.zip', time() - 3600);
        $this->asMfaSuperAdmin()->get('/ops')
            ->assertSee('Backups')
            ->assertSee('backup-fresh.zip')
            ->assertSee('Master Control');

        // Stale.
        Storage::disk('backups')->delete('test-backups/backup-fresh.zip');
        $this->putBackup('backup-stale.zip', time() - 30 * 3600);
        $this->asMfaSuperAdmin()->get('/ops')
            ->assertSee('Stale', false);

        // Missing.
        Storage::disk('backups')->delete('test-backups/backup-stale.zip');
        $this->asMfaSuperAdmin()->get('/ops')
            ->assertSee('Missing', false)
            ->assertSee('no archives found', false);
    }

    public function test_overview_renders_webhook_tile_with_replay_link_only_when_failures_exist(): void
    {
        // Clean ledger: no replay link (nothing to replay).
        $this->asMfaSuperAdmin()->get('/ops')
            ->assertSee('Billing Webhooks')
            ->assertSee('ledger clean', false);

        ProcessedWebhook::create([
            'message_id' => 'fail-tile',
            'message_type' => 'REFUND_ISSUED',
            'payload' => [],
            'status' => 'failed',
            'updated_at' => now()->subHours(2),
        ]);

        $this->asMfaSuperAdmin()->get('/ops')
            ->assertSee('Replay from the Actions hub', false)
            ->assertSee(route('ops.actions.index'), false);
    }

    public function test_overview_score_reflects_a_missing_backup_situation(): void
    {
        // No backups at all: the tile says MISSING and the score must sit
        // at the backup verdict cap (65) or below — never a rosy number
        // over a platform that has no backups. (The host subsystem check
        // reads the same empty disks, so its cap may push even lower —
        // both caps are legitimate; rosiness is what's forbidden.)
        $response = $this->asMfaSuperAdmin()->get('/ops');

        $content = $response->getContent();

        $this->assertStringContainsString('Verdict caps applied', $content);
        $this->assertStringContainsString('stale or missing', $content);

        $this->assertSame(1, preg_match('/text-3xl font-bold [^"]*">(\d+)<span/', $content, $m));
        $this->assertLessThanOrEqual(65, (int) $m[1], 'A platform with no backups must never score above the verdict cap');
    }
}
