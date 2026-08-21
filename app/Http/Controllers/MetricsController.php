<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A-8 FIX (Iter-006): /metrics endpoint for observability.
 *
 * Exposes application-level metrics in JSON format for monitoring tools
 * (Coolify, Datadog, Prometheus via a scraper). Coolify can scrape this
 * endpoint to track application health over time.
 *
 * AUDIT-P0-1.5 FIX: Previously public (rate-limited only). The endpoint
 * exposes PHP version, environment name, top 5 DB tables by row count, and
 * disk usage — sufficient information for an attacker to fingerprint the
 * deployment and target known vulnerabilities. Now requires a token
 * passed via the `?token=` query param. The expected token is read from
 * the `METRICS_TOKEN` env var. When that env var is empty (the default),
 * the endpoint FAILS CLOSED (returns 404) — premium SaaS default.
 *
 * To enable scraping:
 *   1. Set `METRICS_TOKEN=<random-32-char-hex>` in .env (generate with
 *      `openssl rand -hex 16`).
 *   2. Configure your scraper to hit `/metrics?token=<your-token>`.
 *
 * Metrics exposed (when token matches):
 *   - queue: pending jobs, failed jobs
 *   - cache: hit/miss ratio (if available)
 *   - db: connection status, table sizes (approximate)
 *   - storage: disk usage (approximate)
 *   - app: PHP version, Laravel version, uptime (approximate)
 *
 * Route: GET /metrics?token=<token> (rate-limited: 10/min/IP)
 */
class MetricsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // AUDIT-P0-1.5 FIX: Token-gate the metrics endpoint. Fail-closed
        // (404) when METRICS_TOKEN env var is not set OR when the supplied
        // token does not match. hash_equals() for timing safety.
        $expectedToken = config('app.metrics_token');
        if (! is_string($expectedToken) || $expectedToken === '') {
            abort(404);
        }
        $suppliedToken = $request->query('token', '');
        if (! is_string($suppliedToken) || ! hash_equals($expectedToken, $suppliedToken)) {
            abort(404);
        }

        $metrics = [
            'timestamp' => now()->toIso8601String(),
            'app' => $this->appMetrics(),
            'queue' => $this->queueMetrics(),
            'database' => $this->databaseMetrics(),
            'storage' => $this->storageMetrics(),
        ];

        return response()->json($metrics, 200, [
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Content-Type' => 'application/json',
        ]);
    }

    private function appMetrics(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'environment' => app()->environment(),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ];
    }

    private function queueMetrics(): array
    {
        try {
            $failedJobs = DB::table('failed_jobs')->count();
        } catch (\Throwable $e) {
            $failedJobs = null;
        }

        // Try to get pending job count from the jobs table
        try {
            $pendingJobs = DB::table('jobs')->count();
        } catch (\Throwable $e) {
            $pendingJobs = null;
        }

        return [
            'failed_jobs' => $failedJobs,
            'pending_jobs' => $pendingJobs,
        ];
    }

    private function databaseMetrics(): array
    {
        try {
            DB::select('SELECT 1');
            $dbStatus = 'ok';
        } catch (\Throwable $e) {
            $dbStatus = 'down';
        }

        // Approximate table sizes (top 5 by row count)
        $tableSizes = [];
        try {
            $tables = DB::table('information_schema.TABLES')
                ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_TYPE', 'BASE TABLE')
                ->orderByDesc('TABLE_ROWS')
                ->limit(5)
                ->get(['TABLE_NAME', 'TABLE_ROWS', 'DATA_LENGTH']);

            foreach ($tables as $t) {
                $tableSizes[$t->TABLE_NAME] = [
                    'rows' => (int) $t->TABLE_ROWS,
                    'size_mb' => round((float) $t->DATA_LENGTH / 1024 / 1024, 2),
                ];
            }
        } catch (\Throwable $e) {
            // SQLite or unsupported — skip
        }

        return [
            'status' => $dbStatus,
            'largest_tables' => $tableSizes,
        ];
    }

    private function storageMetrics(): array
    {
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk('public');
            $diskPath = $disk->path('');
            $freeBytes = @disk_free_space($diskPath);
            $totalBytes = @disk_total_space($diskPath);

            return [
                'status' => 'ok',
                'free_mb' => $freeBytes !== false ? round($freeBytes / 1024 / 1024, 2) : null,
                'total_mb' => $totalBytes !== false ? round($totalBytes / 1024 / 1024, 2) : null,
                'used_pct' => ($freeBytes !== false && $totalBytes !== false && $totalBytes > 0)
                    ? round((1 - $freeBytes / $totalBytes) * 100, 2)
                    : null,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'down', 'error' => 'Storage unreachable'];
        }
    }
}
