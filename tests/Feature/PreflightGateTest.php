<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreflightGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_cr1_preflight_command_exists_and_returns_zero_in_testing(): void
    {
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
        $this->assertArrayHasKey('exospace:preflight', Artisan::all(),
            'CR-1: exospace:preflight command must be registered.');
    }
}
