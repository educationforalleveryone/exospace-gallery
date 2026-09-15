<?php

declare(strict_types=1);

use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

$backupName = env('APP_NAME', 'Exospace').' Backup';

return [

    'backup' => [

        'name' => $backupName,

        'source' => [

            'files' => [
                'include' => [
                    base_path('storage/app/public'),
                    // Private disk: invoices and other non-public documents (financial records).
                    storage_path('app/private'),
                ],
                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                    // The 'local' filesystem disk root is storage/app/private and is also a
                    // backup destination — without this exclusion every files backup would
                    // re-archive all previously stored backup zips (recursive growth).
                    storage_path('app/private/'.$backupName),
                    // Ephemeral QA run artifacts (JUnit XML), not recovery data.
                    storage_path('app/private/control-center'),
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

            'disks' => array_filter(array_map('trim', explode(',', (string) env('BACKUP_DISKS', 'local')))),
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
            'name' => env('APP_NAME', 'Exospace').' Backup',
            'disks' => array_filter(array_map('trim', explode(',', (string) env('BACKUP_DISKS', 'local')))),
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
            'delete_oldest_backups_when_using_more_megabytes_than' => (int) env('BACKUP_MAX_STORAGE_MB', 5000),
        ],
    ],
];
