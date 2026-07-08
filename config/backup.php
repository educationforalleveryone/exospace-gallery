<?php

declare(strict_types=1);

/**
 * A-1 FIX (Iter-006): Backup configuration for spatie/laravel-backup.
 *
 * Requires: composer require spatie/laravel-backup
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
            ],

            'databases' => [
                'mysql',
            ],
        ],

        'destination' => [

            'filename_prefix' => '',

            'disks' => [
                'local', // Change to 's3' or 'do-spaces' for off-site storage
            ],
        ],

        'temporary_directory' => storage_path('app/backup-temp'),

        'password' => env('BACKUP_PASSWORD'),

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
            ],
        ],
    ],

    'cleanup' => [
        'property' => [
            'keep_all_backups_for_days' => 7,
            'keep_daily_backups_for_days' => 16,
            'keep_weekly_backups_for_days' => 8,
            'keep_monthly_backups_for_months' => 4,
            'keep_yearly_backups_for_years' => 2,
            'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
        ],
    ],

    'monitor' => [
        'monitorBackups' => [
            [
                'disk' => 'local',
                'newest_backups_age_in_days' => 1,
                'storage_minimum_megabytes' => 50,
            ],
        ],
    ],
];
