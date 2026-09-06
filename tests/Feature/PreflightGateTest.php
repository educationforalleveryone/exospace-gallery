<?php

declare(strict_types=1);

/**
 * Iteration-001: Preflight gate regression test (audit CR-1).
 *
 * Verifies that docker-start.sh now hard-fails on preflight errors (was
 * swallowed by `||`). Since we can't test the bash script directly in PHPUnit,
 * this test verifies the PreflightCheck command's exit-code semantics, which
 * is the contract the bash script relies on.
 *
 * The full end-to-end test (container fails to start on bad config) is a
 * manual verification documented in Tests_Performed.md.
 *
 * Run: php artisan test --filter=PreflightGateTest
 */

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreflightGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_cr1_preflight_command_exists_and_returns_zero_in_testing(): void
    {
        // In the testing env, PreflightCheck should return 0 (all checks pass
        // or only warnings). This is the baseline — the bash script relies on
        // this exit code.
        //
        // ITERATION-1 FIX: preflight inspects the REAL runtime environment
        // (migrations applied, storage symlink, venue seed rows). In a test
        // process those artifacts don't exist yet — migrate, link and seed
        // them first so the gate measures its checks, not the harness.
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        if (! is_link(public_path('storage'))) {
            @symlink(storage_path('app/public'), public_path('storage'));
        }
        \App\Models\VenueTemplate::firstOrCreate(
            ['slug' => 'white-cube'],
            [
                'name' => 'White Cube',
                'description' => 'Clean minimal gallery space.',
                'default_settings' => ['wall_texture' => 'white', 'room_layout' => 'square'],
                'visual_config' => [],
                'is_active' => true,
            ],
        );

        $exitCode = Artisan::call('exospace:preflight');

        $this->assertEquals(0, $exitCode,
            'CR-1: PreflightCheck must return exit 0 in testing env (baseline). '.
            'If this fails, the bash hard-fail in docker-start.sh will block all deploys.');
    }

    public function test_cr1_preflight_command_can_be_invoked(): void
    {
        // Verify the command is registered and can be called
        // ITERATION-1 FIX: Artisan::all() is keyed by command NAME.
        $this->assertArrayHasKey('exospace:preflight', Artisan::all(),
            'CR-1: exospace:preflight command must be registered.');
    }
}
