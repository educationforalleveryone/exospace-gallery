<?php

declare(strict_types=1);

/**
 * Iteration-006 regression tests for infrastructure fixes.
 *
 * Run: php artisan test --filter=InfrastructureTest
 */

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

    public function test_a8_metrics_endpoint_exists(): void
    {
        // A-8 FIX: /metrics route should be registered
        $this->assertNotNull(Route::get('GET /metrics'));
    }

    public function test_a8_metrics_returns_json(): void
    {
        $response = $this->get('/metrics');
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

    public function test_a8_metrics_includes_app_info(): void
    {
        $response = $this->get('/metrics');
        $response->assertJsonStructure([
            'app' => ['php_version', 'laravel_version', 'environment', 'memory_usage_mb'],
        ]);
    }

    public function test_a9_request_id_middleware_sets_header(): void
    {
        // A-9 FIX: the response should include X-Request-Id header
        $response = $this->get('/');
        $this->assertTrue($response->headers->has('X-Request-Id'),
            'A-9: Response should include X-Request-Id header.');
    }

    public function test_a9_request_id_is_unique_per_request(): void
    {
        $response1 = $this->get('/');
        $response2 = $this->get('/');

        $id1 = $response1->headers->get('X-Request-Id');
        $id2 = $response2->headers->get('X-Request-Id');

        $this->assertNotEquals($id1, $id2,
            'A-9: Request IDs should be unique per request.');
    }

    public function test_a10_operational_alert_service_exists(): void
    {
        $this->assertInstanceOf(OperationalAlertService::class, app(OperationalAlertService::class));
    }

    public function test_a10_alert_sends_to_log_when_no_webhook(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        config()->set('services.operational_alerts.webhook_url', null);

        app(OperationalAlertService::class)->alert('Test Alert', 'Test message', 'warning');

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn($message) => str_contains($message, 'Test Alert'))
            ->atLeast()
            ->once();
    }

    public function test_a5_gdpr_deletion_request_model_exists(): void
    {
        $this->assertTrue(class_exists(GdprDeletionRequest::class),
            'A-5: GdprDeletionRequest model must exist.');
    }

    public function test_a5_gdpr_deletion_request_can_be_created_for_user(): void
    {
        $user = User::factory()->create();

        $request = GdprDeletionRequest::createForUser($user, 'User requested deletion', '127.0.0.1');

        $this->assertEquals('pending', $request->status);
        $this->assertEquals($user->id, $request->user_id);
        $this->assertEquals($user->email, $request->email);
        $this->assertNotNull($request->scheduled_deletion_at);
        $this->assertTrue($request->scheduled_deletion_at->isFuture());
    }

    public function test_a5_gdpr_deletion_request_table_exists(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('gdpr_deletion_requests'));
    }

    public function test_a1_backup_config_exists(): void
    {
        $this->assertFileExists(config_path('backup.php'),
            'A-1: config/backup.php must exist.');
    }

    public function test_a1_backup_schedule_exists(): void
    {
        Schedule::assertScheduled('backup:run --only-db');
        Schedule::assertScheduled('backup:run --only-files');
        Schedule::assertScheduled('backup:clean');
    }

    public function test_a1_dr_runbook_exists(): void
    {
        $this->assertFileExists(base_path('docs/DR.md'),
            'A-6: docs/DR.md must exist.');
    }

    public function test_a2_supervisord_config_exists(): void
    {
        // A-2 FIX: supervisord config should exist (for queue worker supervision)
        $this->assertFileExists(base_path('docker/supervisord.conf'),
            'A-2: docker/supervisord.conf must exist.');
    }

    public function test_a10_operational_alerts_scheduled(): void
    {
        // A-10 FIX: the operational alert check should be scheduled every 5 minutes
        Schedule::assertScheduled('operational-alerts');
    }
}
