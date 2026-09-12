<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\JobHeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MasterControlBackupTileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
    }

    private function actingAsMfaSuperAdmin()
    {
        $admin = User::factory()->withMfa()->create([
            'is_super_admin'    => true,
            'email_verified_at' => now(),
        ]);

        return $this->actingAs($admin)->withSession([
            'mfa_verified'    => true,
            'mfa_verified_at' => now()->timestamp,
        ]);
    }

    public function test_tile_hidden_on_fresh_install_with_no_stamps_and_no_acks(): void
    {
        $response = $this->actingAsMfaSuperAdmin()->get(route('super.index'));
        $response->assertStatus(200);
        $response->assertDontSee('Backup health', false);
    }

    public function test_tile_renders_emerald_when_all_three_backup_types_fresh(): void
    {
        $hb = app(JobHeartbeatService::class);
        $hb->stamp('exospace:backup:db');
        $hb->stamp('exospace:backup:files');
        $hb->stamp('exospace:backup:clean');

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.index'));
        $response->assertStatus(200);
        $response->assertSee('Backup health', false);
        $response->assertSee('all fresh', false);
        // Per-type cells render all three labels.
        $response->assertSee('Db', false);
        $response->assertSee('Files', false);
        $response->assertSee('Clean', false);
    }

    public function test_tile_renders_amber_when_one_backup_type_stale(): void
    {
        $hb = app(JobHeartbeatService::class);
        $hb->stamp('exospace:backup:db');
        $hb->stamp('exospace:backup:files');
        Cache::put(
            'heartbeat:job:exospace:backup:clean',
            now()->subHours(48)->toIso8601String(),
            now()->addHours(48),
        );

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.index'));
        $response->assertStatus(200);
        $response->assertSee('Backup health', false);
        $response->assertSee('one stale', false);
    }

    public function test_tile_renders_red_when_one_backup_type_missing_after_ack(): void
    {
        $hb = app(JobHeartbeatService::class);
        $hb->stamp('exospace:backup:db');
        $hb->stamp('exospace:backup:files');
        $hb->ackMissing('exospace:backup:clean');

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.index'));
        $response->assertStatus(200);
        $response->assertSee('Backup health', false);
        $response->assertSee('one missing', false);
    }
}
