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
            'name' => env('APP_NAME', 'Exospace'),
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