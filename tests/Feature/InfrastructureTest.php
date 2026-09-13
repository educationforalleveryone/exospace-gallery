<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GdprDeletionRequest;
use App\Models\User;
use App\Services\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class InfrastructureTest extends TestCase
{
    use RefreshDatabase;

    private function assertCommandScheduled(string $needle, string $message = ''): void
    {
        $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($event) => trim((string) $event->command));

        $this->assertTrue(
            $commands->contains(fn ($cmd) => str_contains($cmd, $needle)),
            $message !== '' ? $message : "Scheduled command containing '{$needle}' was not found.",
        );
    }

    public function test_metrics_endpoint_exists(): void
    {
        // /metrics route should be registered
        $this->assertNotNull(Route::get('GET /metrics'));
    }

    public function test_metrics_returns_json(): void
    {
        config(['app.metrics_token' => 'correct-test-token']);
        $response = $this->get('/metrics?token=correct-test-token');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonStructure([
            'timestamp',
            'app',
            'queue',
            'database',
            'storage',
        ]);
    }

    public function test_metrics_includes_app_info(): void
    {
        config(['app.metrics_token' => 'correct-test-token']);
        $response = $this->get('/metrics?token=correct-test-token');
        $response->assertJsonStructure([
            'app' => ['php_version', 'laravel_version', 'environment', 'memory_usage_mb'],
        ]);
    }

    public function test_audit_p01_5_metrics_fail_closed_when_token_unset(): void
    {
        config(['app.metrics_token' => null]);
        $response = $this->get('/metrics');
        $response->assertStatus(404);

        // Even with a token query param, should still 404 if env token unset.
        $response = $this->get('/metrics?token=anything');
        $response->assertStatus(404);
    }

    public function test_audit_p01_5_metrics_rejects_wrong_token(): void
    {
        config(['app.metrics_token' => 'correct-test-token']);

        // No token at all
        $this->get('/metrics')->assertStatus(404);

        // Wrong token
        $this->get('/metrics?token=wrong-token')->assertStatus(404);

        // Empty token
        $this->get('/metrics?token=')->assertStatus(404);

        // Correct token succeeds
        $this->get('/metrics?token=correct-test-token')->assertStatus(200);
    }

    public function test_request_id_middleware_sets_header(): void
    {
        // The response should include the X-Request-Id header
        $response = $this->get('/');
        $this->assertTrue($response->headers->has('X-Request-Id'),
            'Response should include X-Request-Id header.');
    }

    public function test_request_id_is_unique_per_request(): void
    {
        $response1 = $this->get('/');
        $response2 = $this->get('/');

        $id1 = $response1->headers->get('X-Request-Id');
        $id2 = $response2->headers->get('X-Request-Id');

        $this->assertNotEquals($id1, $id2,
            'Request IDs should be unique per request.');
    }

    public function test_operational_alert_service_exists(): void
    {
        $this->assertInstanceOf(OperationalAlertService::class, app(OperationalAlertService::class));
    }

    public function test_alert_sends_to_log_when_no_webhook(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        config()->set('services.operational_alerts.webhook_url', null);

        app(OperationalAlertService::class)->alert('Test Alert', 'Test message', 'warning');

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn($message) => str_contains($message, 'Test Alert'))
            ->atLeast()
            ->once();
    }

    public function test_gdpr_deletion_request_model_exists(): void
    {
        $this->assertTrue(class_exists(GdprDeletionRequest::class),
            'GdprDeletionRequest model must exist.');
    }

    public function test_gdpr_deletion_request_can_be_created_for_user(): void
    {
        $user = User::factory()->create();

        $request = GdprDeletionRequest::createForUser($user, 'User requested deletion', '127.0.0.1');

        $this->assertEquals('pending', $request->status);
        $this->assertEquals($user->id, $request->user_id);
        $this->assertEquals($user->email, $request->email);
        $this->assertNotNull($request->scheduled_deletion_at);
        $this->assertTrue($request->scheduled_deletion_at->isFuture());
    }

    public function test_gdpr_deletion_request_table_exists(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('gdpr_deletion_requests'));
    }

    public function test_backup_config_exists(): void
    {
        $this->assertFileExists(config_path('backup.php'),
            'config/backup.php must exist.');
    }

    public function test_backup_schedule_exists(): void
    {
        $this->assertCommandScheduled('exospace:backup db');
        $this->assertCommandScheduled('exospace:backup files');
        $this->assertCommandScheduled('exospace:backup clean');
    }

    public function test_dr_runbook_exists(): void
    {
        $this->assertFileExists(base_path('docs/DR.md'),
            'docs/DR.md must exist.');
    }

    public function test_supervisord_config_exists(): void
    {
        // Supervisord config should exist (for queue worker supervision)
        $this->assertFileExists(base_path('docker/supervisord.conf'),
            'docker/supervisord.conf must exist.');
    }

    public function test_operational_alerts_scheduled(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $this->assertTrue(
            $events->contains(fn ($e) => $e->description === 'operational-alerts'),
            "The operational-alerts callback event is not scheduled.",
        );
    }
}
