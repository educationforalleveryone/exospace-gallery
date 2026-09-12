<?php

declare(strict_types=1);

namespace App\Ops\Support;

class ErrorClassifier
{
    private const RULES = [
        // ── MIGRATION (schema problems — highest operational specificity) ──
        [
            'needles' => ['42S02', 'Base table or view not found', "doesn't exist"],
            'category' => 'MIGRATION', 'severity' => 'critical',
            'title' => 'Database table missing — migrations may not have run',
            'causes' => [
                'A migration did not run or failed partway during a deployment',
                'The running code is newer than the deployed database schema',
                'A migration was rolled back or never committed',
            ],
            'diagnostics' => ['database.migration-status', 'deployment.recent'],
        ],
        [
            'needles' => ['42S22', 'Unknown column'],
            'category' => 'MIGRATION', 'severity' => 'critical',
            'title' => 'Database column missing — schema/code mismatch',
            'causes' => [
                'Code deployed ahead of its migration (schema mismatch)',
                'A migration failed silently during a previous deployment',
            ],
            'diagnostics' => ['database.migration-status', 'deployment.recent'],
        ],
        [
            'needles' => ['Migration', 'migrating', 'Migrator'],
            'with' => ['SQLSTATE', 'syntax error', 'failed', 'Duplicate column', 'Duplicate key name'],
            'category' => 'MIGRATION', 'severity' => 'critical',
            'title' => 'Database migration failed',
            'causes' => [
                'The migration SQL conflicts with the current schema (duplicate column/index or syntax issue)',
                'The migration ran partially and left the schema half-applied',
            ],
            'diagnostics' => ['database.migration-status', 'deployment.recent'],
        ],

        [
            'needles' => ['SQLSTATE[HY000] [2002]', 'Connection refused', 'SQLSTATE[HY000] [2002 ]'],
            'category' => 'DATABASE', 'severity' => 'critical',
            'title' => 'Database connection failure',
            'causes' => [
                'The database server is unreachable or down',
                'Connection pool / max_connections exhausted',
                'Network problem between the application and the database',
                'Incorrect DB_HOST/DB_PORT configuration',
            ],
            'diagnostics' => ['database.connectivity', 'database.health'],
        ],
        [
            'needles' => ['SQLSTATE[HY000] [1045]', 'Access denied for user'],
            'category' => 'DATABASE', 'severity' => 'critical',
            'title' => 'Database authentication failure',
            'causes' => [
                'Wrong DB_USERNAME/DB_PASSWORD (credentials rotated?)',
                'The database user lost grants on the database',
            ],
            'diagnostics' => ['database.connectivity'],
        ],
        [
            'needles' => ['SQLSTATE[08004]', 'SQLSTATE[HY000] [1040]', 'too many connections'],
            'category' => 'DATABASE', 'severity' => 'critical',
            'title' => 'Database connection limit reached',
            'causes' => [
                'Connection pool exhaustion — too many workers or long-running queries',
                'Leaked connections (transactions or sessions never closed)',
                'max_connections too low for current worker count',
            ],
            'diagnostics' => ['database.connection-health'],
        ],
        [
            'needles' => ['SQLSTATE[40001]', 'Deadlock'],
            'category' => 'DATABASE', 'severity' => 'warning',
            'title' => 'Database deadlock detected',
            'causes' => [
                'Two transactions mutating the same rows in different orders',
                'Long transactions increasing lock windows',
            ],
            'diagnostics' => ['database.health'],
        ],
        [
            'needles' => ['SQLSTATE[HY000]: General error: 1205', 'Lock wait timeout exceeded'],
            'category' => 'DATABASE', 'severity' => 'warning',
            'title' => 'Database lock wait timeout',
            'causes' => [
                'A long-running transaction holds locks others need',
                'A stuck job/worker holding a transaction open',
            ],
            'diagnostics' => ['database.connection-health'],
        ],
        [
            'needles' => ['SQLSTATE[HY000] [1049]', 'Unknown database'],
            'category' => 'DATABASE', 'severity' => 'critical',
            'title' => 'Database does not exist',
            'causes' => [
                'Wrong DB_DATABASE name',
                'The database was dropped or never created on this host',
            ],
            'diagnostics' => ['database.connectivity'],
        ],
        [
            'needles' => ['SQLSTATE', 'QueryException', 'PDO'],
            'category' => 'DATABASE', 'severity' => 'error',
            'title' => 'Database query error',
            'causes' => [
                'Malformed or failing query (constraint violation, syntax, data shape)',
                'Schema/data drift between environments',
            ],
            'diagnostics' => ['database.health'],
        ],

        [
            'needles' => ['REDIS_CONNECTION', 'Connection to Redis', 'redis://', 'tcp://', 'predis'],
            'category' => 'REDIS', 'severity' => 'critical',
            'title' => 'Redis connection failure',
            'causes' => [
                'Redis server unreachable or restarting',
                'Wrong REDIS_HOST/REDIS_PASSWORD (credentials rotated?)',
                'Redis memory limit hit (maxmemory) causing rejections',
            ],
            'diagnostics' => ['redis.connectivity'],
        ],
        [
            'needles' => ['READONLY', 'read only replica', 'MISCONF Redis'],
            'category' => 'REDIS', 'severity' => 'error',
            'title' => 'Redis is refusing writes',
            'causes' => [
                'Redis persistence error (MISCONF — check RDB save errors)',
                'Connected to a read-only replica',
            ],
            'diagnostics' => ['redis.connectivity'],
        ],

        [
            'needles' => ['MaxAttemptsExceededException', 'failed_jobs', 'has been attempted too many times'],
            'category' => 'QUEUE', 'severity' => 'error',
            'title' => 'Queue job failed after retries',
            'causes' => [
                'The job itself is failing (bad payload, external dependency down)',
                'The payload shape changed between enqueue and processing (deploy drift)',
            ],
            'diagnostics' => ['queue.failed-jobs', 'queue.health'],
        ],
        [
            'needles' => ['queue worker', 'queue:work', 'worker died', 'jobs table', 'backing off for'],
            'category' => 'QUEUE', 'severity' => 'warning',
            'title' => 'Queue worker problem',
            'causes' => [
                'Worker crashed or restarted mid-job',
                'Queue backlog growing faster than workers can process',
            ],
            'diagnostics' => ['queue.health'],
        ],

        [
            'needles' => ['could not be resolved to an installable set', 'composer install', 'composer'],
            'category' => 'BUILD', 'severity' => 'critical',
            'title' => 'Composer dependency resolution failed',
            'causes' => [
                'composer.lock out of sync with composer.json',
                'PHP platform requirements not met by the build image',
            ],
            'diagnostics' => ['deployment.recent'],
        ],
        [
            'needles' => ['npm ERR!', 'ELIFECYCLE', 'npm install', 'vite build', 'Rollup failed'],
            'category' => 'BUILD', 'severity' => 'critical',
            'title' => 'Front-end build failed',
            'causes' => [
                'npm dependency or peer-dependency conflict',
                'Vite/Rollup build error (import path, syntax, missing asset)',
                'Node version mismatch in the build image',
            ],
            'diagnostics' => ['deployment.recent'],
        ],
        [
            'needles' => ['failed to solve', 'docker build', 'exit code: 1'],
            'category' => 'BUILD', 'severity' => 'critical',
            'title' => 'Container image build failed',
            'causes' => [
                'A Dockerfile/Nixpacks build step failed (apt, composer, npm, php)',
                'Missing build-time environment configuration',
            ],
            'diagnostics' => ['deployment.recent'],
        ],
        [
            'needles' => ['deployment failed', 'deployment_failed', 'deploy failed'],
            'category' => 'DEPLOYMENT', 'severity' => 'critical',
            'title' => 'Deployment failed',
            'causes' => [
                'Build or startup step failed during the deployment',
                'Health check did not pass after the new container started',
            ],
            'diagnostics' => ['deployment.recent', 'container.health'],
        ],
        [
            'needles' => ['restart loop', 'restarting', 'crash loop', 'OOMKilled', 'exit code 137'],
            'category' => 'CONTAINER', 'severity' => 'critical',
            'title' => 'Container is restarting repeatedly',
            'causes' => [
                'Application process exiting on startup (config error, failed migration)',
                'Container killed for memory (OOM) and restarted by the platform',
            ],
            'diagnostics' => ['container.health', 'container.recent-logs'],
        ],

        [
            'needles' => ['cURL error 6', 'Could not resolve host'],
            'category' => 'NETWORK', 'severity' => 'error',
            'title' => 'DNS resolution failure',
            'causes' => [
                'DNS misconfiguration or resolver outage',
                'A hostname in the configuration is wrong',
            ],
            'diagnostics' => ['app.recent-errors'],
        ],
        [
            'needles' => ['cURL error 28', 'Connection timed out', 'timed out'],
            'category' => 'NETWORK', 'severity' => 'warning',
            'title' => 'Outbound request timed out',
            'causes' => [
                'External service slow or unreachable',
                'Timeout set too low for the workload',
            ],
            'diagnostics' => ['app.recent-errors'],
        ],
        [
            'needles' => ['cURL error 7', 'Connection refused', 'ConnectException'],
            'category' => 'EXTERNAL_SERVICE', 'severity' => 'error',
            'title' => 'External service connection refused',
            'causes' => [
                'The third-party endpoint is down or misconfigured',
                'Firewall/network blocking the outbound call',
            ],
            'diagnostics' => ['app.recent-errors'],
        ],
        [
            'needles' => ['cURL error 60', 'SSL certificate', 'certificate verify failed'],
            'category' => 'EXTERNAL_SERVICE', 'severity' => 'warning',
            'title' => 'TLS certificate verification failed',
            'causes' => [
                'Expired or misconfigured certificate on the remote host',
                'Missing CA bundle in the container',
            ],
            'diagnostics' => ['app.recent-errors'],
        ],
        [
            'needles' => ['resend', 'MAIL_', 'mailer', 'Swift_TransportException'],
            'category' => 'EXTERNAL_SERVICE', 'severity' => 'warning',
            'title' => 'Mail delivery problem',
            'causes' => [
                'Mail provider API rejecting the request (auth, domain, rate limit)',
                'Mail provider unreachable',
            ],
            'diagnostics' => ['app.recent-errors'],
        ],
        [
            'needles' => ['2checkout', 'webhook signature', 'Invalid signature', 'IPN'],
            'category' => 'WEBHOOK', 'severity' => 'error',
            'title' => 'Webhook processing problem',
            'causes' => [
                'Signature verification failing (secret rotated on one side?)',
                'Handler crashing while applying the event',
            ],
            'diagnostics' => ['app.recent-errors'],
        ],

        [
            'needles' => ['No space left on device', 'disk full'],
            'category' => 'STORAGE', 'severity' => 'critical',
            'title' => 'Disk full',
            'causes' => [
                'Logs, uploads, or backups filled the volume',
                'Persistent storage volume undersized',
            ],
            'diagnostics' => ['server.disk'],
        ],
        [
            'needles' => ['Permission denied', 'failed to open stream', 'Unable to write'],
            'category' => 'STORAGE', 'severity' => 'error',
            'title' => 'Filesystem write problem',
            'causes' => [
                'Wrong ownership/permissions on the storage path',
                'Storage path missing (volume not mounted)',
            ],
            'diagnostics' => ['app.filesystem'],
        ],

        [
            'needles' => ['AuthenticationException', 'Unauthenticated'],
            'category' => 'AUTHENTICATION', 'severity' => 'info',
            'title' => 'Unauthenticated request',
            'causes' => [
                'Expired or missing session/token (often normal traffic: bots, logged-out users)',
            ],
            'diagnostics' => ['app.recent-errors'],
        ],
        [
            'needles' => ['AuthorizationException', 'This action is unauthorized'],
            'category' => 'AUTHORIZATION', 'severity' => 'warning',
            'title' => 'Authorization check failed',
            'causes' => [
                'User lacking permissions for the action (often normal)',
                'A policy regression after a code change',
            ],
            'diagnostics' => ['app.recent-errors'],
        ],
        [
            'needles' => ['Too Many Attempts', 'throttle'],
            'category' => 'AUTHORIZATION', 'severity' => 'info',
            'title' => 'Rate limit hit',
            'causes' => [
                'Abusive traffic or a runaway client retrying',
            ],
            'diagnostics' => ['app.recent-errors'],
        ],

        // ── PHP / APPLICATION (generic — keep last) ─────────────────────
        [
            'needles' => ['Allowed memory size', 'memory_get_usage'],
            'category' => 'PHP', 'severity' => 'critical',
            'title' => 'PHP memory limit exhausted',
            'causes' => [
                'A job or request processing more data than PHP_MEMORY_LIMIT allows',
                'Memory leak accumulating across long-running worker uptime',
            ],
            'diagnostics' => ['queue.health', 'container.health'],
        ],
        [
            'needles' => ['Maximum execution time', 'max_execution_time'],
            'category' => 'PHP', 'severity' => 'error',
            'title' => 'PHP execution time exceeded',
            'causes' => [
                'Slow operation (large export, image processing, external calls)',
            ],
            'diagnostics' => ['app.recent-errors'],
        ],
        [
            'needles' => ['ClassNotFoundError', 'Class "', 'not found'],
            'category' => 'APPLICATION', 'severity' => 'error',
            'title' => 'PHP class not found',
            'causes' => [
                'Autoload/optimization stale after a deploy (composer dump-autoload needed)',
                'Code referencing a class that no longer exists',
            ],
            'diagnostics' => ['app.recent-errors'],
        ],
        [
            'needles' => ['Call to a member function', 'null given', 'Trying to access array offset'],
            'category' => 'APPLICATION', 'severity' => 'error',
            'title' => 'Unexpected null in application logic',
            'causes' => [
                'A record or API response shape changed and left a null where code expects data',
                'Missing guard for an optional relationship',
            ],
            'diagnostics' => ['app.recent-errors'],
        ],
        [
            'needles' => ['Target class [', 'does not exist'],
            'category' => 'LARAVEL', 'severity' => 'error',
            'title' => 'Laravel container binding error',
            'causes' => [
                'Interface without a bound implementation (provider not registered?)',
                'Typo in a class reference or missing service provider',
            ],
            'diagnostics' => ['app.recent-errors'],
        ],
        [
            'needles' => ['NotFoundHttpException', 'Route [', 'not defined'],
            'category' => 'APPLICATION', 'severity' => 'info',
            'title' => 'Route not found',
            'causes' => [
                'Traffic to a removed/renamed route (often bots or stale links)',
            ],
            'diagnostics' => ['app.recent-errors'],
        ],
    ];

    public function classify(?string $exceptionClass, string $message, string $levelSeverity = 'error'): array
    {
        $haystack = strtolower(trim(($exceptionClass ?? '').' '.$message));

        foreach (self::RULES as $rule) {
            if (isset($rule['with'])) {
                foreach ((array) $rule['with'] as $withNeedle) {
                    if (! str_contains($haystack, strtolower($withNeedle))) {
                        continue 2;
                    }
                }
            }

            foreach ((array) $rule['needles'] as $needle) {
                if (str_contains($haystack, strtolower($needle))) {
                    return [
                        'category' => $rule['category'],
                        // More serious of pattern floor vs observed level.
                        'severity' => $this->maxSeverity($rule['severity'], $levelSeverity),
                        'title' => $rule['title'],
                        'likely_causes' => $rule['causes'],
                        'recommended_diagnostics' => $rule['diagnostics'],
                        'confidence' => 'pattern',
                        'matched' => $needle,
                    ];
                }
            }
        }

        return [
            'category' => 'UNKNOWN',
            'severity' => $this->maxSeverity('warning', $levelSeverity),
            'title' => $this->deriveFallbackTitle($exceptionClass, $message),
            'likely_causes' => [
                'Not yet classified — no matching pattern. Inspect the message, stack and recent changes.',
            ],
            'recommended_diagnostics' => ['app.recent-errors'],
            'confidence' => 'none',
            'matched' => null,
        ];
    }

    public static function recommendedDiagnosticIds(): array
    {
        $ids = [];
        foreach (self::RULES as $rule) {
            foreach ((array) ($rule['diagnostics'] ?? []) as $id) {
                $ids[] = (string) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    public static function levelToSeverity(int $monologLevel): string
    {
        return match (true) {
            $monologLevel >= 500 => 'critical',
            $monologLevel >= 400 => 'error',
            $monologLevel >= 300 => 'warning',
            default => 'info',
        };
    }

    private function maxSeverity(string $a, string $b): string
    {
        $rank = ['info' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4];

        return ($rank[$a] ?? 1) >= ($rank[$b] ?? 1) ? $a : $b;
    }

    private function deriveFallbackTitle(?string $exceptionClass, string $message): string
    {
        if ($exceptionClass !== null && $exceptionClass !== '') {
            $short = substr(strrchr('\\'.ltrim($exceptionClass, '\\'), '\\'), 1);

            return mb_substr(($short ?: $exceptionClass).': '.trim($message), 0, 200);
        }

        return mb_substr(trim($message) !== '' ? trim($message) : 'Unclassified event', 0, 200);
    }
}
