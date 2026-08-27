<?php

declare(strict_types=1);

namespace App\Ops\Diagnostics\Runners;

use App\Ops\Diagnostics\DiagnosticResult;
use App\Ops\Diagnostics\RunsDiagnostics;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Throwable;

/**
 * OpsCenter — DatabaseDiagnostics (Iteration 3).
 *
 * database.connectivity | database.health | database.connection-health |
 * database.migration-status
 *
 * The brief's flagship distinction lives here: the operator must be able to
 * tell "DATABASE IS DOWN" apart from "DATABASE IS HEALTHY BUT THE APP CANNOT
 * CONNECT" apart from "DATABASE IS CONNECTED BUT A QUERY FAILED" apart from
 * "MIGRATION FAILED". Each diagnostic answers exactly one of those questions
 * and says so in plain language.
 *
 * Read-only guarantees:
 *   - only SELECT / SHOW statements;
 *   - the raw-PDO reachability probe opens a SEPARATE short-lived connection
 *     (never persists credentials, reports only the failure MODE — refused
 *     vs denied vs unknown database vs saturated) and is only used where the
 *     configured driver is mysql;
 *   - migration status is computed from the migrator's file list vs the
 *     migrations table — it never RUNS anything.
 */
class DatabaseDiagnostics implements RunsDiagnostics
{
    public function runDiagnostic(string $id, ?OpsApplication $application): DiagnosticResult
    {
        return match ($id) {
            'database.connectivity' => $this->connectivity(),
            'database.health' => $this->health(),
            'database.connection-health' => $this->connectionHealth(),
            'database.migration-status' => $this->migrationStatus(),
            default => DiagnosticResult::inconclusive(
                'Unknown database diagnostic',
                'This diagnostic id is not implemented by the database runner.',
            ),
        };
    }

    // ── database.connectivity ───────────────────────────────────────────

    /**
     * THE question: is the database reachable, and if not, WHY.
     */
    private function connectivity(): DiagnosticResult
    {
        $findings = [];

        // 1) A fresh, separate connection (driver-aware) so we can
        //    distinguish server-down from bad credentials from the app's
        //    own (possibly pooled/cached) connection state.
        $probe = $this->probeFreshConnection();
        $findings[] = $probe['finding'];

        // 2) The framework's live connection.
        $latencyMs = null;
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $latencyMs = (int) round((microtime(true) - $start) * 1000);
            $findings[] = [
                'label' => 'Query round-trip (application connection)',
                'status' => $latencyMs > 250 ? 'warn' : 'pass',
                'detail' => "SELECT 1 completed in {$latencyMs} ms.".($latencyMs > 250 ? ' Slower than expected — could indicate network or load pressure.' : ''),
            ];
        } catch (Throwable $e) {
            $findings[] = [
                'label' => 'Query round-trip (application connection)',
                'status' => 'fail',
                'detail' => 'The application connection cannot execute a query: '.mb_substr($e->getMessage(), 0, 300),
            ];
        }

        // null = probe not applicable (non-MySQL driver) — the framework
        // query below is then the authoritative connectivity answer.
        $reachable = $probe['reachable'] !== false;

        if ($reachable && $latencyMs !== null) {
            return DiagnosticResult::fromFindings(
                'Database reachable — '.$latencyMs.' ms round-trip',
                $findings,
                'The database server is up, accepts the application\'s credentials, and answers queries. If the application is still showing database errors, the problem is on the query/schema side (see Database health and Migration status), not connectivity.',
                ['database.health', 'database.migration-status'],
            );
        }

        // Unreachable — classify the failure MODE for the operator.
        $mode = $probe['mode'] ?? 'unknown';

        $interpretation = match ($mode) {
            'refused' => 'Nothing is listening at the configured database host and port (or a firewall is dropping the connection). This reads as DATABASE IS DOWN from the application\'s point of view. Check the database resource\'s status on the Applications page (Coolify status for the managed database) and the database container in Coolify.',
            'denied' => 'The database server is REACHABLE but rejected the credentials. This is the "healthy database, wrong credentials" case: a rotated password, a revoked user, or wrong DB_USERNAME/DB_PASSWORD in the application environment. Rotating credentials must be followed by updating the app\'s Coolify environment variables.',
            'unknown-database' => 'The server is reachable and the credentials are accepted, but the configured database name does not exist on this host. Verify DB_DATABASE matches the database created in Coolify.',
            'saturated' => 'The server is refusing new connections because its connection limit is exhausted. This is the "too many connections" case: typically too many workers, or leaked connections from long-running processes. See the connection-pool diagnostic.',
            'timeout' => 'The connection attempt timed out. The host may be down, overloaded, or unreachable over the network — indistinguishable from here without the platform view (check the database resource status on the Applications page).',
            default => 'The connection failed in an unexpected way. The raw driver message is included in the findings; the Coolify database resource status on the Applications page shows whether the managed database itself is up.',
        };

        return DiagnosticResult::fromFindings(
            'Database connection failing'.($mode !== 'unknown' ? ' — '.$this->modeLabel($mode) : ''),
            $findings,
            $interpretation,
            ['container.health', 'database.connection-health'],
        );
    }

    /**
     * Open a throwaway connection using the configured credentials — reports
     * only the failure mode, never the credentials or DSN.
     *
     * @return array{reachable: bool|null, mode: string|null, finding: array{label: string, status: string, detail: string}}
     */
    private function probeFreshConnection(): array
    {
        $config = config('database.connections.'.config('database.default'));
        $driver = (string) ($config['driver'] ?? 'mysql');

        if ($driver !== 'mysql') {
            // SQLite/other drivers: the framework probe below is the honest
            // connectivity answer; a raw socket probe is meaningless.
            return [
                'reachable' => null,
                'mode' => null,
                'finding' => [
                    'label' => 'Fresh connection probe',
                    'status' => 'skip',
                    'detail' => 'Socket-level probe applies to MySQL only (configured driver: '.$driver.'); the application connection check below is authoritative.',
                ],
            ];
        }

        $host = (string) ($config['host'] ?? '');
        $port = (int) ($config['port'] ?? 3306);
        $database = (string) ($config['database'] ?? '');
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');

        try {
            $start = microtime(true);
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_TIMEOUT => 5,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    // Statement class irrelevant — we only run SELECT 1.
                ],
            );
            $pdo->query('SELECT 1');
            $ms = (int) round((microtime(true) - $start) * 1000);
            $pdo = null;

            return [
                'reachable' => true,
                'mode' => null,
                'finding' => [
                    'label' => 'Fresh connection probe (new connection, 5s timeout)',
                    'status' => 'pass',
                    'detail' => "Connected and executed SELECT 1 in {$ms} ms — server reachable, credentials accepted.",
                ],
            ];
        } catch (Throwable $e) {
            $mode = $this->classifyPdoFailure($e);

            return [
                'reachable' => false,
                'mode' => $mode,
                'finding' => [
                    'label' => 'Fresh connection probe (new connection, 5s timeout)',
                    'status' => 'fail',
                    'detail' => $this->modeLabel($mode).'. '.mb_substr($e->getMessage(), 0, 240),
                ],
            ];
        }
    }

    /**
     * Map a PDO connection failure to an operational failure mode.
     */
    private function classifyPdoFailure(Throwable $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, '[2002]') || str_contains($message, 'Connection refused') => 'refused',
            str_contains($message, '[1045]') || str_contains($message, 'Access denied') => 'denied',
            str_contains($message, '[1049]') || str_contains($message, 'Unknown database') => 'unknown-database',
            str_contains($message, '[1040]') || str_contains($message, 'too many connections') => 'saturated',
            str_contains($message, 'timed out') || str_contains($message, 'timeout') => 'timeout',
            default => 'unknown',
        };
    }

    private function modeLabel(string $mode): string
    {
        return match ($mode) {
            'refused' => 'server unreachable (connection refused)',
            'denied' => 'credentials rejected',
            'unknown-database' => 'database does not exist',
            'saturated' => 'connection limit reached',
            'timeout' => 'connection timed out',
            default => 'connection failed',
        };
    }

    // ── database.health ─────────────────────────────────────────────────

    /**
     * Connectivity + schema sanity + recent database-side errors.
     */
    private function health(): DiagnosticResult
    {
        $findings = [];

        // Connectivity (reuse the probe).
        $probe = $this->probeFreshConnection();
        $findings[] = $probe['finding'];

        // Core tables present (schema sanity).
        $coreTables = ['users', 'jobs', 'failed_jobs', 'migrations'];
        $missing = [];
        $schemaInspected = true;

        try {
            foreach ($coreTables as $table) {
                if (! Schema::hasTable($table)) {
                    $missing[] = $table;
                }
            }
        } catch (Throwable $e) {
            $schemaInspected = false;
            $findings[] = [
                'label' => 'Schema sanity',
                'status' => 'skip',
                'detail' => 'Could not inspect the schema: '.mb_substr($e->getMessage(), 0, 200),
            ];
        }

        if ($schemaInspected) {
            $findings[] = $missing === []
                ? [
                    'label' => 'Schema sanity (core tables)',
                    'status' => 'pass',
                    'detail' => 'All core tables present: '.implode(', ', $coreTables).'.',
                ]
                : [
                    'label' => 'Schema sanity (core tables)',
                    'status' => 'fail',
                    'detail' => 'Missing tables: '.implode(', ', $missing).'. Migrations may not have run — run Migration status next; the running code may be ahead of the schema.',
                ];
        }

        // Connection utilization (MySQL only, tolerant).
        $utilization = $this->connectionUtilization();
        if ($utilization !== null) {
            $findings[] = $utilization;
        }

        // Recent database/migration errors the control plane has captured.
        $findings[] = $this->recentErrorFinding();

        return DiagnosticResult::fromFindings(
            $probe['reachable'] === false
                ? 'Database unreachable'
                : 'Database healthy'.($utilization !== null && $utilization['status'] === 'warn' ? ' — connection utilization elevated' : ''),
            $findings,
            $this->composeHealthInterpretation($probe['reachable'], $missing, $findings),
            $missing !== [] ? ['database.migration-status', 'deployment.recent'] : ['database.migration-status'],
        );
    }

    private function composeHealthInterpretation(?bool $reachable, array $missing, array $findings): string
    {
        if ($reachable === false) {
            return 'The database is not reachable at all — this is a DATABASE IS DOWN situation, not a query problem. Use the connectivity diagnostic for the precise failure mode, and check the database resource\'s Coolify status on the Applications page.';
        }

        if ($missing !== []) {
            return 'The database is up and credentials work, but core tables are missing. The application is almost certainly running against an incomplete schema (a migration that never ran or failed partway). Run Migration status before anything else; do NOT fix this by blindly running migrations in production — inspect which migration is missing and why first.';
        }

        $hasRecentErrors = collect($findings)->first(fn ($f) => str_contains($f['label'], 'Recent database'));

        if ($hasRecentErrors !== null && in_array($hasRecentErrors['status'], ['warn', 'fail'], true)) {
            return 'The database itself is healthy, but the control plane has captured recent database/migration errors from the application. That combination points at query or schema-level problems (a failing query, a code/schema mismatch) rather than an outage. Inspect the linked events and run Migration status.';
        }

        return 'The database is reachable, credentials work, the schema\'s core tables exist and there are no recent database-level errors captured by the control plane. If the application misbehaves anyway, the cause is more likely on the application side (see Application health).';
    }

    // ── database.connection-health ──────────────────────────────────────

    /**
     * Pool utilization: Threads_connected vs max_connections (+ MySQL only).
     */
    private function connectionHealth(): DiagnosticResult
    {
        $findings = [];
        $utilization = $this->connectionUtilization();

        if ($utilization === null) {
            return DiagnosticResult::inconclusive(
                'Connection-pool metrics unavailable',
                'Connection-pool statistics require the MySQL driver (SHOW STATUS / SHOW VARIABLES). The configured driver does not expose them through this interface. Connectivity itself is covered by the connectivity diagnostic.',
                ['database.connectivity'],
            );
        }

        $findings[] = $utilization;

        // Longest-running process (needs PROCESS privilege — tolerant).
        try {
            $rows = DB::select('SHOW PROCESSLIST');
            $maxSeconds = 0;
            $sleeping = 0;
            foreach ($rows as $row) {
                $seconds = (int) ($row->Time ?? 0);
                $maxSeconds = max($maxSeconds, $seconds);
                if (strtoupper((string) ($row->Command ?? '')) === 'SLEEP') {
                    $sleeping++;
                }
            }
            $total = count($rows);

            $findings[] = [
                'label' => 'Longest running process',
                'status' => $maxSeconds > 300 ? 'warn' : 'pass',
                'detail' => sprintf(
                    'Longest process has been running %d s. %d of %d connections are idle (SLEEP). %s',
                    $maxSeconds,
                    $sleeping,
                    $total,
                    $maxSeconds > 300 ? 'A process holding the connection for this long may be a stuck job or a long transaction — a classic pool-exhaustion precursor.' : '',
                ),
            ];
        } catch (Throwable) {
            $findings[] = [
                'label' => 'Longest running process',
                'status' => 'skip',
                'detail' => 'SHOW PROCESSLIST is unavailable (requires the PROCESS privilege). Not a failure.',
            ];
        }

        $status = $utilization['status'];

        return DiagnosticResult::fromFindings(
            $status === 'pass' ? 'Connection pool healthy' : 'Connection utilization elevated',
            $findings,
            $status === 'pass'
                ? 'Connection usage is comfortably below the server limit and no process is pinning a connection for long. Pool exhaustion is not the current problem.'
                : 'Connections are closer to the server limit than is comfortable. Pool exhaustion produces exactly the "SQLSTATE[HY000] [1040] too many connections" errors the classifier flags as critical. Usual causes: queue workers scaled up, long transactions, or leaked connections in long-running processes. Reducing worker counts or restarting the worker container releases them.',
            ['database.connectivity', 'queue.health'],
        );
    }

    /**
     * Threads_connected / max_connections utilization finding, or null when
     * the driver isn't MySQL. READ-ONLY (SHOW STATUS / SHOW VARIABLES).
     */
    private function connectionUtilization(): ?array
    {
        $driver = (string) config('database.connections.'.config('database.default').'.driver');

        if ($driver !== 'mysql') {
            return null;
        }

        try {
            $connected = (int) (DB::selectOne("SHOW STATUS WHERE Variable_name = 'Threads_connected'")->Value ?? 0);
            $max = (int) (DB::selectOne("SHOW VARIABLES WHERE Variable_name = 'max_connections'")->Value ?? 0);

            if ($max <= 0) {
                return null;
            }

            $pct = round($connected / $max * 100, 1);

            return [
                'label' => 'Connection utilization',
                'status' => $pct >= 80 ? 'warn' : 'pass',
                'detail' => sprintf(
                    '%d of max %d connections in use (%.1f%%).%s',
                    $connected,
                    $max,
                    $pct,
                    $pct >= 80 ? ' Above 80% — new connections will start failing before long.' : '',
                ),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    // ── database.migration-status ───────────────────────────────────────

    /**
     * Are migrations pending? Did one fail recently? Read-only — the brief
     * forbids auto-running production migrations, and this diagnostic never
     * will.
     */
    private function migrationStatus(): DiagnosticResult
    {
        $findings = [];
        $pending = [];
        $ran = [];

        try {
            if (! Schema::hasTable('migrations')) {
                return DiagnosticResult::fromFindings(
                    'Migrations table missing — migrations have never run',
                    [[
                        'label' => 'Migrations table',
                        'status' => 'fail',
                        'detail' => 'The "migrations" table does not exist. No migrations have been recorded on this database. If this is not a brand-new install, the application is running against a foreign or partially-provisioned schema.',
                    ]],
                    'The database has no migrations table at all. On a fresh deployment the deploy pipeline should have created it (docker-start.sh runs migrations on boot); its absence on an established database means the app is pointed at the wrong database, or the schema was reset. Verify DB_DATABASE first (connectivity diagnostic shows which database answers).',
                    ['database.connectivity', 'deployment.recent'],
                );
            }

            $migrator = app('migrator');
            $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
            $ran = $migrator->getRepository()->getRan();
            $pending = array_values(array_diff($files, $ran));

            $findings[] = [
                'label' => 'Migration state',
                'status' => $pending === [] ? 'pass' : 'warn',
                'detail' => $pending === []
                    ? sprintf('%d migrations recorded, none pending — the schema matches the deployed code.', count($ran))
                    : sprintf('%d pending migration(s): %s. The DEPLOYED CODE may expect columns/tables that do not exist yet.', count($pending), implode(', ', array_slice($pending, 0, 5)).(count($pending) > 5 ? ', …' : '')),
            ];
        } catch (Throwable $e) {
            return DiagnosticResult::inconclusive(
                'Could not read migration state',
                'Reading the migrations table failed: '.mb_substr($e->getMessage(), 0, 250).'. This is usually a symptom of the database itself being unreachable or the schema being inconsistent — run the connectivity diagnostic first.',
                ['database.connectivity'],
            );
        }

        // Recent migration EVENTS (failures captured by the control plane).
        $migrationEvents = OpsEvent::query()
            ->where('category', 'MIGRATION')
            ->whereIn('status', ['open', 'acknowledged'])
            ->orderByDesc('last_seen_at')
            ->limit(5)
            ->get();

        $findings[] = $migrationEvents->isEmpty()
            ? [
                'label' => 'Recent migration errors',
                'status' => 'pass',
                'detail' => 'No unresolved migration-category errors captured by the control plane.',
            ]
            : [
                'label' => 'Recent migration errors',
                'status' => 'fail',
                'detail' => sprintf(
                    '%d unresolved migration error(s), newest seen %s. Evidence suggests a migration has failed and the schema may be half-applied.',
                    $migrationEvents->count(),
                    $migrationEvents->first()->last_seen_at?->diffForHumans() ?? 'recently',
                ),
            ];

        // Last deployment for context (was there a deploy right before?).
        $lastDeployment = OpsEvent::query()
            ->where('category', 'DEPLOYMENT')
            ->orderByDesc('last_seen_at')
            ->first();

        $interpretation = $this->composeMigrationInterpretation($pending, $migrationEvents->isNotEmpty(), $lastDeployment);

        return DiagnosticResult::fromFindings(
            $pending === [] && $migrationEvents->isEmpty()
                ? 'Migrations up to date'
                : ($migrationEvents->isNotEmpty() ? 'Migration failure suspected' : count($pending).' migration(s) pending'),
            $findings,
            $interpretation,
            $pending !== [] || $migrationEvents->isNotEmpty() ? ['deployment.recent', 'container.recent-logs'] : [],
        );
    }

    private function composeMigrationInterpretation(array $pending, bool $hasMigrationErrors, ?OpsEvent $lastDeployment): string
    {
        if ($hasMigrationErrors) {
            return 'Evidence suggests a migration FAILED — the control plane has unresolved migration-category errors. Pending-migration counts alone cannot tell you WHERE it stopped, so treat the schema as suspect: code may be running against a half-applied migration. The failed migration\'s error is on the linked event page. This diagnostic deliberately does NOT run migrations: inspect the error, fix the cause (duplicate column, lock timeout, bad SQL), then redeploy or run the migration consciously from the deploy pipeline — never blind-fix a production schema from a dashboard.';
        }

        if ($pending !== []) {
            $context = $lastDeployment !== null
                ? ' The most recent deployment event the control plane saw was '.$lastDeployment->last_seen_at?->diffForHumans().'.'
                : '';

            return 'There are '.count($pending).' pending migration(s): the code on disk contains migrations that the database has not recorded. The application MAY be running against an older schema — expect "unknown column" or "table not found" errors if new code touches the missing parts.'.$context.' Nothing runs automatically from here: pending migrations are deployed through the normal pipeline (docker-start.sh runs migrations on container start), or deliberately by the operator after reviewing this list.';
        }

        return 'The schema is fully migrated: every migration file on disk is recorded in the database, and the control plane has no unresolved migration errors. If errors still point at the schema, they are more likely stale-code (a container still running old code after a deploy) — check Container health and Recent deployments.';
    }

    /**
     * Shared: recent DATABASE/MIGRATION events finding for database.health.
     */
    private function recentErrorFinding(): array
    {
        $recent = OpsEvent::query()
            ->whereIn('category', ['DATABASE', 'MIGRATION'])
            ->whereIn('status', ['open', 'acknowledged'])
            ->orderByRaw('CASE severity WHEN "critical" THEN 1 WHEN "error" THEN 2 WHEN "warning" THEN 3 ELSE 4 END')
            ->orderByDesc('last_seen_at')
            ->limit(5)
            ->get();

        if ($recent->isEmpty()) {
            return [
                'label' => 'Recent database errors (control plane)',
                'status' => 'pass',
                'detail' => 'No unresolved database or migration errors captured in the control plane.',
            ];
        }

        $critical = $recent->where('severity', 'critical')->count();

        return [
            'label' => 'Recent database errors (control plane)',
            'status' => $critical > 0 ? 'fail' : 'warn',
            'detail' => sprintf(
                '%d unresolved database/migration event(s) (%d critical). Newest: "%s" (%s).',
                $recent->count(),
                $critical,
                mb_substr($recent->first()->title, 0, 120),
                $recent->first()->last_seen_at?->diffForHumans() ?? 'recently',
            ),
        ];
    }
}
