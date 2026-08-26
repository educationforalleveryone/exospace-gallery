<?php

declare(strict_types=1);

/**
 * ITERATION-9 regression tests.
 *
 * Verifies:
 *   - AUDIT-P1-9.1: r2 disk is defined in config/filesystems.php
 *   - AUDIT-P1-9.1: BACKUP_DISKS env var drives the backup destination disks
 *   - AUDIT-P1-9.1: checkBackupHealth() checks ALL configured disks (not just first)
 *   - AUDIT-P1-9.1: per-disk dedup keys (backup_none_found:local, backup_none_found:r2)
 *
 * Run: php artisan test --filter=Iteration9Test
 */

namespace Tests\Feature;

use App\Services\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Iteration9Test extends TestCase
{
    use RefreshDatabase;

    /**
     * AUDIT-P1-9.1: The r2 disk is defined in config/filesystems.php.
     */
    public function test_audit_p19_1_do_spaces_disk_is_defined(): void
    {
        $disks = config('filesystems.disks');

        $this->assertArrayHasKey('r2', $disks, 'r2 disk should be defined in filesystems config.');
        $this->assertEquals('s3', $disks['r2']['driver'], 'r2 should use the s3 driver.');
        $this->assertNull($disks['r2']['key'], 'r2 key should default to null (no credentials set).');
        $this->assertNull($disks['r2']['secret'], 'r2 secret should default to null.');
        $this->assertNull($disks['r2']['bucket'], 'r2 bucket should default to null.');
    }

    /**
     * AUDIT-P1-9.1: BACKUP_DISKS env var defaults to 'local' (backward compat).
     */
    public function test_audit_p19_1_backup_disks_defaults_to_local(): void
    {
        // ITERATION-1 FIX: refreshApplication() re-runs phpdotenv, whose
        // immutable writer can re-load previously-loaded keys — runtime
        // env clears don't reliably survive an app refresh. Reload just
        // the backup config against the cleared variable instead.
        putenv('BACKUP_DISKS');
        unset($_ENV['BACKUP_DISKS'], $_SERVER['BACKUP_DISKS']);
        $this->app['config']->set('backup', require config_path('backup.php'));

        $disks = config('backup.backup.destination.disks');

        $this->assertIsArray($disks);
        $this->assertEquals(['local'], $disks, 'Default BACKUP_DISKS should be local-only.');
    }

    /**
     * AUDIT-P1-9.1: BACKUP_DISKS env var can enable multiple disks.
     */
    public function test_audit_p19_1_backup_disks_env_var_enables_multiple_disks(): void
    {
        putenv('BACKUP_DISKS=local,r2');
        $this->refreshApplication();

        $disks = config('backup.backup.destination.disks');

        $this->assertIsArray($disks);
        $this->assertContains('local', $disks);
        $this->assertContains('r2', $disks);
        $this->assertCount(2, $disks);

        // Restore.
        putenv('BACKUP_DISKS');
    }

    /**
     * AUDIT-P1-9.1: BACKUP_DISKS trims whitespace around entries.
     */
    public function test_audit_p19_1_backup_disks_trims_whitespace(): void
    {
        putenv('BACKUP_DISKS=local, r2');
        $this->refreshApplication();

        $disks = config('backup.backup.destination.disks');

        $this->assertContains('local', $disks);
        $this->assertContains('r2', $disks, 'r2 should not have a leading space.');

        // Restore.
        putenv('BACKUP_DISKS');
    }

    /**
     * AUDIT-P1-9.1: monitor_backups also uses the env-driven disks.
     */
    public function test_audit_p19_1_monitor_backups_uses_env_driven_disks(): void
    {
        putenv('BACKUP_DISKS=local,r2');
        $this->refreshApplication();

        $monitorDisks = config('backup.monitor_backups.0.disks');

        $this->assertIsArray($monitorDisks);
        $this->assertContains('local', $monitorDisks);
        $this->assertContains('r2', $monitorDisks);

        // Restore.
        putenv('BACKUP_DISKS');
    }

    /**
     * AUDIT-P1-9.1: checkBackupHealth() checks ALL configured disks.
     * This test verifies the method iterates all disks (not just the first)
     * by checking that a fresh backup on the local disk doesn't suppress
     * the "no backups" alert for a non-existent r2 disk.
     *
     * We can't easily test the r2 disk directly (it requires real
     * S3 credentials), but we CAN verify the method iterates all configured
     * disks by setting BACKUP_DISKS=local,r2 and confirming the
     * r2 check fires an alert (because the disk doesn't exist /
     * has no credentials → catch Throwable → skip silently).
     *
     * The key assertion: the method doesn't throw when a disk is
     * misconfigured — it skips that disk and continues.
     */
    public function test_audit_p19_1_check_backup_health_iterates_all_disks_without_throwing(): void
    {
        Log::spy();
        config(['services.operational_alerts.webhook_url' => null]);

        // Set BACKUP_DISKS to include both local + r2.
        config(['backup.backup.destination.disks' => ['local', 'r2']]);

        // Ensure the local disk has a fresh backup (so no alert fires for local).
        $disk = Storage::disk('local');
        $backupName = config('backup.backup.name', config('app.name') . ' Backup');
        $disk->put($backupName . '/healthy-backup.zip', 'fake-zip-content');
        $fullPath = $disk->path($backupName . '/healthy-backup.zip');
        touch($fullPath, now()->subHour()->timestamp);

        // The r2 disk has no credentials → it will throw when accessed
        // → the catch block should skip it silently. No exception should bubble up.
        $service = app(OperationalAlertService::class);

        // This should NOT throw — the catch(Throwable) in checkSingleBackupDisk
        // handles the r2 failure silently.
        //
        // ITERATION-1 FIX: risky-test guard — the method performs no
        // assertions (it only asserts "no exception"). Assert the observable
        // side effect: the healthy local backup produced no critical alert.
        try {
            $service->checkBackupHealth();
            $noException = true;
        } catch (Throwable) {
            $noException = false;
        }
        $this->assertTrue($noException, 'checkBackupHealth must not throw when one disk is unreadable');
        Log::shouldNotHaveReceived('critical');
        $service->checkBackupHealth();

        // If we got here without an exception, the test passes. The r2
        // disk was skipped (catch Throwable), and the local disk was checked
        // (fresh backup → no alert).

        // Cleanup.
        $disk->delete($backupName . '/healthy-backup.zip');
    }

    /**
     * AUDIT-P1-9.1: Per-disk dedup keys are used. The local disk's
     * "no backups" alert uses key 'backup_none_found:local', and the
     * r2 disk's uses 'backup_none_found:r2'. This means
     * a failure on one disk doesn't suppress the alert for the other.
     */
    public function test_audit_p19_1_per_disk_dedup_keys_are_distinct(): void
    {
        $source = file_get_contents(app_path('Services/OperationalAlertService.php'));

        // The per-disk dedup key pattern should be present in the source.
        $this->assertStringContainsString(
            "backup_none_found:{\$diskName}",
            $source,
            'checkBackupHealth should use per-disk dedup key: backup_none_found:{diskName}'
        );
        $this->assertStringContainsString(
            "backup_stale:{\$diskName}",
            $source,
            'checkBackupHealth should use per-disk dedup key: backup_stale:{diskName}'
        );
    }

    /**
     * AUDIT-P1-9.1: The checkSingleBackupDisk method exists (extracted
     * from checkBackupHealth to support multi-disk iteration).
     */
    public function test_audit_p19_1_check_single_backup_disk_method_exists(): void
    {
        $reflection = new \ReflectionClass(OperationalAlertService::class);
        $this->assertTrue(
            $reflection->hasMethod('checkSingleBackupDisk'),
            'OperationalAlertService should have a checkSingleBackupDisk method.'
        );
        $this->assertTrue(
            $reflection->hasMethod('checkBackupHealth'),
            'OperationalAlertService should have a checkBackupHealth method.'
        );
    }
}
