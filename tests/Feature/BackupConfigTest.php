<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class BackupConfigTest extends TestCase
{
    /**
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    private function freshConfig(array $env = []): array
    {
        foreach ($env as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }

        try {
            return require config_path('backup.php');
        } finally {
            foreach ($env as $key => $value) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            }
        }
    }

    public function test_source_includes_public_media_and_private_documents(): void
    {
        $config = $this->freshConfig();

        $include = $config['backup']['source']['files']['include'];

        $this->assertContains(base_path('storage/app/public'), $include, 'user media must be backed up');
        $this->assertContains(storage_path('app/private'), $include, 'invoices/private documents must be backed up');
    }

    public function test_local_backup_destination_is_excluded_from_source(): void
    {
        $config = $this->freshConfig(['APP_NAME' => 'Exospace']);

        $backupName = 'Exospace Backup';
        $this->assertSame($backupName, $config['backup']['name']);

        $exclude = $config['backup']['source']['files']['exclude'];

        $this->assertContains(
            storage_path('app/private/'.$backupName),
            $exclude,
            'the local backup destination must be excluded — otherwise files backups archive the backup zips recursively',
        );
    }

    public function test_destination_disks_parse_from_comma_separated_env(): void
    {
        $config = $this->freshConfig(['BACKUP_DISKS' => 'local, r2']);

        $this->assertSame(['local', 'r2'], $config['backup']['destination']['disks']);
        $this->assertSame(['local', 'r2'], $config['monitor_backups'][0]['disks']);
    }

    public function test_destination_disks_default_to_local(): void
    {
        $config = $this->freshConfig();

        $this->assertSame(['local'], $config['backup']['destination']['disks']);
    }

    public function test_storage_cap_is_env_configurable_with_safe_default(): void
    {
        $config = $this->freshConfig();

        $this->assertSame(
            5000,
            $config['cleanup']['default_strategy']['delete_oldest_backups_when_using_more_megabytes_than'],
            'default per-disk storage cap',
        );

        $config = $this->freshConfig(['BACKUP_MAX_STORAGE_MB' => '20000']);

        $this->assertSame(
            20000,
            $config['cleanup']['default_strategy']['delete_oldest_backups_when_using_more_megabytes_than'],
        );
    }

    public function test_retention_never_lets_cleanup_run_out_of_recovery_points(): void
    {
        $config = $this->freshConfig();

        $strategy = $config['cleanup']['default_strategy'];

        $this->assertGreaterThanOrEqual(7, $strategy['keep_all_backups_for_days']);
        $this->assertGreaterThan($strategy['keep_all_backups_for_days'], $strategy['keep_daily_backups_for_days']);
        $this->assertGreaterThan(0, $strategy['keep_weekly_backups_for_weeks']);
        $this->assertGreaterThan(0, $strategy['keep_monthly_backups_for_months']);
        $this->assertGreaterThan(0, $strategy['delete_oldest_backups_when_using_more_megabytes_than']);
    }

    public function test_monitor_health_check_age_matches_db_backup_cadence(): void
    {
        $config = $this->freshConfig();

        $checks = $config['monitor_backups'][0]['health_checks'];

        $this->assertSame(1, $checks[\Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays::class]);
    }

    public function test_database_dump_sources_use_the_mysql_connection(): void
    {
        $config = $this->freshConfig();

        $this->assertContains('mysql', $config['backup']['source']['databases']);
    }
}
