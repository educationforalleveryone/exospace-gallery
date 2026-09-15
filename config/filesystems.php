<?php

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        // Both local disks carry durable user data (uploads, invoices).
        // throw=true surfaces IO failures (disk full, permission denied) as
        // exceptions instead of silent false returns from put()/store(),
        // so callers can never report success for bytes that never landed.
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => true,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL'), '/').'/storage',
            'visibility' => 'public',
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        'cdn' => [
            'driver' => 's3',
            'key' => env('CDN_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('CDN_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('CDN_DEFAULT_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
            'bucket' => env('CDN_BUCKET', env('AWS_BUCKET')),
            'url' => env('CDN_URL', env('AWS_URL')),
            'endpoint' => env('CDN_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('CDN_USE_PATH_STYLE_ENDPOINT', env('AWS_USE_PATH_STYLE_ENDPOINT', false)),
            'throw' => true,
            'report' => true,
            'visibility' => 'public',
        ],

        'r2' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => 'auto',
            'bucket' => env('R2_BUCKET'),
            'endpoint' => env('R2_ENDPOINT'), // e.g. https://<account_id>.r2.cloudflarestorage.com
            'use_path_style_endpoint' => true, // required by R2's S3-compat API
            'throw' => true,
            'report' => true,
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
