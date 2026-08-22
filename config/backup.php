<?php

declare(strict_types=1);

/**
 * A-1 FIX (Iter-006): Backup configuration for spatie/laravel-backup.
 *
 * Backups are stored on the local disk initially, then the operator should
 * configure an S3-compatible destination (DigitalOcean Spaces) for off-site
 * storage. See docs/DR.md for the full backup/restore runbook.
 *
 * Schedule (in routes/console.php):
 *   - Daily at 1am: backup:run --only-db (database only)
 *   - Weekly on Sunday at 1:30am: backup:run --only-files (user uploads)
 *
 * The backup monitors disk space and will warn if free space is low.
 */

use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

return [

    'backup' => [

        'name' => env('APP_NAME', 'Exospace') . ' Backup',

        'source' => [

            'files' => [
                'include' => [
                    base_path('storage/app/public'),
                ],
                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                ],
                'follow_links' => false,
                'ignore_unreadable_directories' => true,
                'relative_path' => null,
            ],

            'databases' => [
                'mysql',
            ],
        ],

        'database_dump_compressor' => null,

        'database_dump_file_timestamp_format' => null,

        'database_dump_file_extension' => '',

        'destination' => [

            'compression_method' => ZipArchive::CM_DEFAULT,

            'compression_level' => 9,

            'filename_prefix' => '',

            'disks' => [
                'local', // Change to 's3' or 'do-spaces' for off-site storage
            ],
        ],

        'temporary_directory' => storage_path('app/backup-temp'),

        'password' => env('BACKUP_PASSWORD'),

        'encryption' => 'default',

        'notifications' => [

            'notifications' => [
                \Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification::class => ['mail'],
                \Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification::class => ['mail'],
                \Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification::class => ['mail'],
                \Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification::class => [],
                \Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification::class => [],
                \Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification::class => [],
            ],

            // ITERATION-6 (AUDIT-P1-6.4): The above notifications go to mail
            // only. For a premium SaaS, backup failures should ALSO fire an
            // operational alert to Slack (via OperationalAlertService) so
            // they're visible in the same channel as other critical alerts
            // (queue backup, disk full, scheduler down).
            //
            // We can't add 'slack' directly to the Spatie notification channels
            // above because that would use Laravel's notification Slack driver
            // (which requires a separate LOG_SLACK_WEBHOOK_URL config) instead
            // of the OperationalAlertService (which uses OPERATIONAL_ALERT_WEBHOOK).
            //
            // Instead, the OperationalAlertService::checkBackupHealth() method
            // (added in this iteration) checks the backup destination disk
            // every 5 minutes as part of the existing checkAndAlert() schedule.
            // If no backup exists OR the newest backup is older than 26 hours,
            // it fires a critical alert to Slack. This is simpler than wiring
            // a custom Spatie notification listener and gives us the same
            // operational outcome: backup failures appear in Slack.

            'notifiable' => \Spatie\Backup\Notifications\Notifiable::class,

            'mail' => [
                'to' => env('BACKUP_NOTIFICATION_EMAIL', 'admin@exospace.gallery'),

                'from' => [
                    'address' => env('MAIL_FROM_ADDRESS', 'noreply@exospace.gallery'),
                    'name' => env('MAIL_FROM_NAME', 'Exospace Gallery'),
                ],
            ],
        ],
    ],

    'monitor_backups' => [
        [
            'name' => env('APP_NAME', 'Exospace') . ' Backup',
            'disks' => ['local'],
            'health_checks' => [
                MaximumAgeInDays::class => 1,
                MaximumStorageInMegabytes::class => 5000,
            ],
        ],
    ],

    'cleanup' => [
        'strategy' => \Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy::class,

        'default_strategy' => [
            'keep_all_backups_for_days' => 7,
            'keep_daily_backups_for_days' => 16,
            'keep_weekly_backups_for_weeks' => 8,
            'keep_monthly_backups_for_months' => 4,
            'keep_yearly_backups_for_years' => 2,
            'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
        ],
    ],
];