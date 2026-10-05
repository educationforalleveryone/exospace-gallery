<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PreflightGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_command_exists_and_returns_zero_in_testing(): void
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
            'PreflightCheck must return exit 0 in testing env (baseline). '.
            'If this fails, the bash hard-fail in docker-start.sh will block all deploys.');
    }

    public function test_preflight_command_can_be_invoked(): void
    {
        $this->assertArrayHasKey('exospace:preflight', Artisan::all(),
            'exospace:preflight command must be registered.');
    }

    public function test_preflight_flags_weakened_session_cookies_as_production_critical(): void
    {
        config([
            'app.env' => 'production',
            'session.secure' => false,
            'session.http_only' => false,
            'session.same_site' => 'none',
        ]);

        Artisan::call('exospace:preflight');
        $output = Artisan::output();

        $this->assertStringContainsString('SESSION_SECURE_COOKIE=false', $output,
            'A non-HTTPS-only session cookie is a session-hijack gate.');
        $this->assertStringContainsString('SESSION_HTTP_ONLY=false', $output,
            'A JavaScript-readable session cookie is a session-hijack gate.');
        $this->assertStringContainsString('SESSION_SAME_SITE=none', $output,
            'SameSite=none defeats CSRF protection for the session cookie.');
    }

    public function test_preflight_accepts_hardened_session_defaults_in_production(): void
    {
        config([
            'app.env' => 'production',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'session.driver' => 'redis',
        ]);

        Artisan::call('exospace:preflight');
        $output = Artisan::output();

        $this->assertStringContainsString('Session cookie is HTTPS-only (secure).', $output);
        $this->assertStringContainsString('Session cookie is HttpOnly (not readable by JavaScript).', $output);
        $this->assertStringContainsString('Session cookie SameSite policy: lax.', $output);
        $this->assertStringNotContainsString('SESSION_DRIVER=file', $output);
    }

    public function test_preflight_advises_against_file_sessions_in_production(): void
    {
        config(['app.env' => 'production', 'session.driver' => 'file']);

        Artisan::call('exospace:preflight');
        $output = Artisan::output();

        $this->assertStringContainsString('SESSION_DRIVER=file', $output,
            'File sessions are per-container — production scale-out logs users out.');
    }

    public function test_preflight_advises_queue_worker_over_sync_in_production(): void
    {
        config(['app.env' => 'production', 'queue.default' => 'sync']);

        Artisan::call('exospace:preflight');
        $output = Artisan::output();

        $this->assertStringContainsString('QUEUE_CONNECTION=sync', $output,
            'Sync queues run payment webhooks and mail inside the HTTP request.');
    }
}
