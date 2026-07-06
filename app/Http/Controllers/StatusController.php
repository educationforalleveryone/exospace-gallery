<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * M-20: Public status page.
 *
 * Shows a human-friendly status page at /status that mirrors the /health
 * JSON endpoint but renders an HTML page. Uses the same subsystem checks
 * as HealthController (P3-3) but caches the result for 60 seconds to
 * avoid hammering the DB/Redis on every page load.
 *
 * The status page is public (no auth) so users can check if Exospace is
 * down before contacting support. It shows:
 *   - Overall status (All Systems Operational / Partial Degradation / Outage)
 *   - Per-subsystem status (Database, Cache, Queue, Storage)
 *   - Last checked timestamp
 *
 * Intentionally does NOT show:
 *   - Error details (could leak infrastructure info)
 *   - Coolify API status (internal)
 *   - Failed job counts (internal)
 *   - Disk space numbers (internal)
 * Only shows "Operational" / "Degraded" / "Down" per subsystem.
 */
class StatusController extends Controller
{
    public function show(Request $request): View
    {
        $checks = Cache::remember('status:page', now()->addMinute(), function () {
            return $this->runChecks();
        });

        return view('pages.status', [
            'checks'    => $checks['checks'],
            'allHealthy' => $checks['allHealthy'],
            'checkedAt'  => $checks['timestamp'],
        ]);
    }

    private function runChecks(): array
    {
        $checks = [];
        $allHealthy = true;

        // Database
        try {
            DB::select('SELECT 1');
            $checks['database'] = 'operational';
        } catch (\Throwable $e) {
            $checks['database'] = 'down';
            $allHealthy = false;
        }

        // Cache
        try {
            $testKey = 'status:check:' . uniqid();
            Cache::put($testKey, 'ok', 10);
            $val = Cache::get($testKey);
            Cache::forget($testKey);
            $checks['cache'] = $val === 'ok' ? 'operational' : 'degraded';
            if ($val !== 'ok') $allHealthy = false;
        } catch (\Throwable $e) {
            $checks['cache'] = 'down';
            $allHealthy = false;
        }

        // Queue
        try {
            $failedCount = DB::table('failed_jobs')->count();
            $checks['queue'] = $failedCount > 100 ? 'degraded' : 'operational';
            if ($failedCount > 100) $allHealthy = false;
        } catch (\Throwable $e) {
            $checks['queue'] = 'down';
            $allHealthy = false;
        }

        // Storage
        try {
            $disk = Storage::disk('public');
            $checks['storage'] = $disk->exists('.') ? 'operational' : 'down';
            if (!$disk->exists('.')) $allHealthy = false;
        } catch (\Throwable $e) {
            $checks['storage'] = 'down';
            $allHealthy = false;
        }

        return [
            'checks' => $checks,
            'allHealthy' => $allHealthy,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
