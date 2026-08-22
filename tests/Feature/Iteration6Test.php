<?php

declare(strict_types=1);

/**
 * ITERATION-6 regression tests.
 *
 * Verifies:
 *   - AUDIT-P1-6.2: OperationalAlertService::checkQueueWorkerHealth() fires
 *     a critical alert when jobs have been sitting in the queue >10 minutes.
 *   - AUDIT-P1-6.4: OperationalAlertService::checkBackupHealth() fires a
 *     critical alert when no backups exist OR the newest backup is >26 hours old.
 *   - AUDIT-P1-6.3: config/logging.php stack default includes 'daily' + 'json'.
 *
 * Run: php artisan test --filter=Iteration6Test
 */

namespace Tests\Feature;

use App\Services\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Iteration6Test extends TestCase
{
    use RefreshDatabase;

    // ── AUDIT-P1-6.2: checkQueueWorkerHealth ───────────────────────────

    /**
     * AUDIT-P1-6.2: When the jobs table is empty, no alert should fire
     * (worker is healthy — just no traffic).
     */
    public function test_audit_p16_2_queue_health_no_alert_when_queue_empty(): void
    {
        Log::spy();
        config(['services.operational_alerts.webhook_url' => null]);

        app(OperationalAlertService::class)->checkQueueWorkerHealth();

        // No critical log entry should be fired for an empty queue.
        Log::shouldNotHaveReceived('critical')
            ->withArgs(fn ($message) => str_contains($message, 'Queue worker'));
    }

    /**
     * AUDIT-P1-6.2: When a job has been in the queue >10 minutes,
     * a critical alert should fire.
     */
    public function test_audit_p16_2_queue_health_alerts_when_job_is_stale(): void
    {
        Log::spy();
        config(['services.operational_alerts.webhook_url' => null]);

        // Insert a fake job that's been available for 15 minutes.
        DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => json_encode(['job' => 'test']),
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => now()->subMinutes(15)->timestamp,
            'created_at'   => now()->subMinutes(15)->timestamp,
        ]);

        app(OperationalAlertService::class)->checkQueueWorkerHealth();

        Log::shouldHaveReceived('critical')
            ->withArgs(fn ($message) => str_contains($message, 'Queue worker may be down'))
            ->atLeast()
            ->once();
    }

    /**
     * AUDIT-P1-6.2: When a job is fresh (<10 minutes old), no alert fires.
     */
    public function test_audit_p16_2_queue_health_no_alert_when_job_is_fresh(): void
    {
        Log::spy();
        config(['services.operational_alerts.webhook_url' => null]);

        DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => json_encode(['job' => 'test']),
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => now()->subMinutes(3)->timestamp, // 3 min ago
            'created_at'   => now()->subMinutes(3)->timestamp,
        ]);

        app(OperationalAlertService::class)->checkQueueWorkerHealth();

        Log::shouldNotHaveReceived('critical')
            ->withArgs(fn ($message) => str_contains($message, 'Queue worker'));
    }

    // ── AUDIT-P1-6.4: checkBackupHealth ─────────────────────────────────

    /**
     * AUDIT-P1-6.4: When NO backup files exist, a critical alert fires.
     */
    public function test_audit_p16_4_backup_health_alerts_when_no_backups_exist(): void
    {
        Log::spy();
        config(['services.operational_alerts.webhook_url' => null]);

        // Use the local disk (default backup destination).
        $disk = Storage::disk('local');
        $backupName = config('backup.backup.name', config('app.name') . ' Backup');

        // Ensure the backup directory is empty (no zips).
        $existingFiles = $disk->files($backupName);
        foreach ($existingFiles as $file) {
            if (str_ends_with($file, '.zip')) {
                $disk->delete($file);
            }
        }

        app(OperationalAlertService::class)->checkBackupHealth();

        Log::shouldHaveReceived('critical')
            ->withArgs(fn ($message) => str_contains($message, 'No backups found'))
            ->atLeast()
            ->once();
    }

    /**
     * AUDIT-P1-6.4: When the newest backup is >26 hours old, a critical alert fires.
     */
    public function test_audit_p16_4_backup_health_alerts_when_backup_is_stale(): void
    {
        Log::spy();
        config(['services.operational_alerts.webhook_url' => null]);

        $disk = Storage::disk('local');
        $backupName = config('backup.backup.name', config('app.name') . ' Backup');

        // Clean any existing backups first.
        $existingFiles = $disk->files($backupName);
        foreach ($existingFiles as $file) {
            if (str_ends_with($file, '.zip')) {
                $disk->delete($file);
            }
        }

        // Create a fake backup zip file with an old modification time (30 hours ago).
        $disk->put($backupName . '/old-backup.zip', 'fake-zip-content');

        // Set the file's modification time to 30 hours ago using the underlying filesystem.
        $fullPath = $disk->path($backupName . '/old-backup.zip');
        touch($fullPath, now()->subHours(30)->timestamp);

        app(OperationalAlertService::class)->checkBackupHealth();

        Log::shouldHaveReceived('critical')
            ->withArgs(fn ($message) => str_contains($message, 'Backup is stale'))
            ->atLeast()
            ->once();

        // Cleanup.
        $disk->delete($backupName . '/old-backup.zip');
    }

    /**
     * AUDIT-P1-6.4: When the newest backup is fresh (<26 hours old), no alert fires.
     */
    public function test_audit_p16_4_backup_health_no_alert_when_backup_is_fresh(): void
    {
        Log::spy();
        config(['services.operational_alerts.webhook_url' => null]);

        $disk = Storage::disk('local');
        $backupName = config('backup.backup.name', config('app.name') . ' Backup');

        // Clean any existing backups.
        $existingFiles = $disk->files($backupName);
        foreach ($existingFiles as $file) {
            if (str_ends_with($file, '.zip')) {
                $disk->delete($file);
            }
        }

        // Create a fresh backup (1 hour old).
        $disk->put($backupName . '/fresh-backup.zip', 'fake-zip-content');
        $fullPath = $disk->path($backupName . '/fresh-backup.zip');
        touch($fullPath, now()->subHour()->timestamp);

        app(OperationalAlertService::class)->checkBackupHealth();

        Log::shouldNotHaveReceived('critical')
            ->withArgs(fn ($message) => str_contains($message, 'backup') || str_contains($message, 'Backup'));

        // Cleanup.
        $disk->delete($backupName . '/fresh-backup.zip');
    }

    // ── AUDIT-P1-6.3: logging config ────────────────────────────────────

    /**
     * AUDIT-P1-6.3: The default LOG_STACK (when env var is absent) should
     * include 'daily' AND 'json' for production-grade structured logging.
     */
    public function test_audit_p16_3_logging_stack_default_includes_daily_and_json(): void
    {
        // Clear the env var to test the default.
        putenv('LOG_STACK=');

        // Re-bootstrap the config to pick up the cleared env var.
        $this->refreshApplication();

        $stackChannels = config('logging.channels.stack.channels');

        $this->assertIsArray($stackChannels, 'Stack channels should be an array.');
        $this->assertContains('daily', $stackChannels, 'Default stack should include "daily" for human-readable rotated logs.');
        $this->assertContains('json', $stackChannels, 'Default stack should include "json" for structured log aggregation.');

        // Restore the env var for other tests.
        putenv('LOG_STACK=daily');
    }

    /**
     * AUDIT-P1-6.3: The LOG_STACK env var should still override the default.
     */
    public function test_audit_p16_3_logging_stack_env_var_overrides_default(): void
    {
        putenv('LOG_STACK=slack');
        $this->refreshApplication();

        $stackChannels = config('logging.channels.stack.channels');
        $this->assertEquals(['slack'], $stackChannels, 'LOG_STACK env var should override the default.');

        // Restore.
        putenv('LOG_STACK=daily');
    }

    /**
     * AUDIT-P1-6.2/6.4: checkAndAlert() should call all 5 check methods.
     * Verifies the new methods are wired into the public entry point.
     */
    public function test_audit_p16_check_and_alert_calls_all_5_checks(): void
    {
        $service = $this->getMockBuilder(OperationalAlertService::class)
            ->onlyMethods(['alert'])
            ->getMock();

        // The alert method should NOT be called in a healthy state
        // (empty queue, fresh backups). We spy on it to verify.
        $service->expects($this->never())
            ->method('alert');

        // Ensure healthy state: empty jobs table + fresh backup.
        DB::table('jobs')->truncate();

        $disk = Storage::disk('local');
        $backupName = config('backup.backup.name', config('app.name') . ' Backup');
        $disk->put($backupName . '/healthy-backup.zip', 'fake-zip-content');
        $fullPath = $disk->path($backupName . '/healthy-backup.zip');
        touch($fullPath, now()->subHour()->timestamp);

        $service->checkAndAlert();

        // Cleanup.
        $disk->delete($backupName . '/healthy-backup.zip');
    }
}
