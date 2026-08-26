<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\WebProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            // ITERATION-6 (AUDIT-P1-6.3): Default changed from 'single' to
            // 'daily,json' for production-grade logging:
            //   - daily: 14-day rotated Laravel logs (human-readable, for
            //     quick `tail -f` during incidents)
            //   - json: structured JSON logs at storage/logs/exospace.json
            //     (for Loki/Datadog/CloudWatch ingestion)
            //
            // Operators can override via LOG_STACK env var (e.g. set to
            // 'daily' for dev, 'daily,json,slack' for production with Slack
            // critical alerts). The env var is comma-separated.
            //
            // Note: the user's production .env already has LOG_STACK=daily,
            // so this change only affects the DEFAULT when the env var is
            // absent (fresh installs, tests). To get structured JSON logs
            // in production, the operator should set LOG_STACK=daily,json
            // in Coolify's env vars.
            // ITERATION-1 FIX: an empty LOG_STACK value (or one that parses
            // to no channels) previously produced [''] — a stack with a
            // nonexistent '' channel that silently logged nothing. Fall
            // back to the default when the env var yields no channels.
            'channels' => (function () {
                $channels = array_values(array_filter(array_map('trim', explode(',', (string) env('LOG_STACK', 'daily,json')))));
                return $channels !== [] ? $channels : ['daily', 'json'];
            })(),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'Laravel Log'),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        // P3-2: Structured JSON logging channel for log aggregation
        // (Datadog, Loki, CloudWatch, ELK). Each log entry is a single
        // JSON object with timestamp, level, message, context, and
        // web request metadata (ip, url, http_method). To use, set
        // LOG_STACK=json in .env, or add 'json' to the stack channels.
        'json' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => JsonFormatter::class,
            'formatter_with' => [
                'includeStacktraces' => true,
                'batchMode' => JsonFormatter::BATCH_MODE_NEWLINES,
            ],
            'handlers' => [
                [
                    'class' => StreamHandler::class,
                    'constructor' => [
                        'stream' => storage_path('logs/exospace.json'),
                    ],
                    'formatter' => JsonFormatter::class,
                ],
            ],
            'processors' => [
                PsrLogMessageProcessor::class,
                WebProcessor::class,
            ],
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
