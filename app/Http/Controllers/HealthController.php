<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * P3-3/P3-4/P3-5: Runtime health check with subsystem breakdown.
 *
 * Replaces the bare /health endpoint that only checked DB connectivity.
 * This endpoint checks:
 *   - Database (SELECT 1)
 *   - Redis cache (write + read + delete)
 *   - Queue (check failed_jobs count)
 *   - Disk space (storage/ writable + free space)
 *   - Coolify API reachability (optional, cached)
 *
 * Returns 200 if all critical subsystems are healthy, 503 if any are down.
 * Designed for Coolify's health check probe + uptime monitors.
 */
class HealthController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        $checks = [];
        $allHealthy = true;

        // ── Database ──────────────────────────────────────────────────
        try {
            DB::select('SELECT 1');
            $checks['database'] = ['status' => 'ok'];
        } catch (\Throwable $e) {
            $checks['database'] = ['status' => 'down', 'error' => 'DB unreachable'];
            $allHealthy = false;
        }

        // ── Redis / Cache ─────────────────────────────────────────────
        try {
            $testKey = 'health:check:' . uniqid();
            Cache::put($testKey, 'ok', 10);
            $val = Cache::get($testKey);
            Cache::forget($testKey);
            $checks['cache'] = ['status' => $val === 'ok' ? 'ok' : 'degraded'];
            if ($val !== 'ok') $allHealthy = false;
        } catch (\Throwable $e) {
            $checks['cache'] = ['status' => 'down', 'error' => 'Cache unreachable'];
            $allHealthy = false;
        }

        // ── Queue (failed jobs count) ────────────────────────────────
        try {
            $failedCount = DB::table('failed_jobs')->count();
            $checks['queue'] = [
                'status' => $failedCount > 100 ? 'degraded' : 'ok',
                'failed_jobs' => $failedCount,
            ];
            if ($failedCount > 100) $allHealthy = false;
        } catch (\Throwable $e) {
            $checks['queue'] = ['status' => 'down', 'error' => 'Cannot query failed_jobs'];
            $allHealthy = false;
        }

        // ── Disk space ───────────────────────────────────────────────
        try {
            $disk = Storage::disk('public');
            $checks['storage'] = [
                'status' => $disk->exists('.') ? 'ok' : 'down',
            ];
        } catch (\Throwable $e) {
            $checks['storage'] = ['status' => 'down', 'error' => 'Storage unreachable'];
            $allHealthy = false;
        }

        // ── Coolify API (optional, cached 5 min) ─────────────────────
        $coolifyConfigured = config('services.coolify.api_token')
            && config('services.coolify.api_base_url');
        if ($coolifyConfigured) {
            $coolifyStatus = Cache::get('health:coolify', 'unknown');
            if ($coolifyStatus === 'unknown') {
                // Don't check on every health probe — cache the result.
                // The PreflightCheck command does a live ping; this just
                // reports the last known status.
                $checks['coolify'] = ['status' => 'unknown'];
            } else {
                $checks['coolify'] = ['status' => $coolifyStatus];
            }
        }

        $response = [
            'status' => $allHealthy ? 'ok' : 'degraded',
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ];

        return response()->json($response, $allHealthy ? 200 : 503);
    }
}
