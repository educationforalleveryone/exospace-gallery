<?php

declare(strict_types=1);

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

    public function test_audit_p19_1_do_spaces_disk_is_defined(): void
    {
        $disks = config('filesystems.disks');

        $this->assertArrayHasKey('r2', $disks, 'r2 disk should be defined in filesystems config.');
        $this->assertEquals('s3', $disks['r2']['driver'], 'r2 should use the s3 driver.');
        $this->assertNull($disks['r2']['key'], 'r2 key should default to null (no credentials set).');
        $this->assertNull($disks['r2']['secret'], 'r2 secret should default to null.');
        $this->assertNull($disks['r2']['bucket'], 'r2 bucket should default to null.');
    }

    public function test_audit_p19_1_backup_disks_defaults_to_local(): void
    {
        putenv('BACKUP_DISKS');
        unset($_ENV['BACKUP_DISKS'], $_SERVER['BACKUP_DISKS']);
        $this->app['config']->set('backup', require config_path('backup.php'));

        $disks = config('backup.backup.destination.disks');

        $this->assertIsArray($disks);
        $this->assertEquals(['local'], $disks, 'Default BACKUP_DISKS should be local-only.');
    }

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

        $service = app(OperationalAlertService::class);

        try {
            $service->checkBackupHealth();
            $noException = true;
        } catch (Throwable) {
            $noException = false;
        }
        $this->assertTrue($noException, 'checkBackupHealth must not throw when one disk is unreadable');
        Log::shouldNotHaveReceived('critical');
        $service->checkBackupHealth();

        // Cleanup.
        $disk->delete($backupName . '/healthy-backup.zip');
    }

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
