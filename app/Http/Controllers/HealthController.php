<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class HealthController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        $checks = [];
        $allHealthy = true;

        try {
            DB::select('SELECT 1');
            $checks['database'] = ['status' => 'ok'];
        } catch (\Throwable $e) {
            $checks['database'] = ['status' => 'down', 'error' => 'DB unreachable'];
            $allHealthy = false;
        }

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

        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('processed_webhooks')) {
                $failedWebhooks = DB::table('processed_webhooks')->where('status', 'failed')->count();
                $checks['billing_webhooks'] = [
                    'status' => $failedWebhooks > 20 ? 'degraded' : ($failedWebhooks > 5 ? 'warning' : 'ok'),
                    'failed_webhooks' => $failedWebhooks,
                ];
                if ($failedWebhooks > 20) $allHealthy = false;
            } else {
                $checks['billing_webhooks'] = ['status' => 'skipped', 'detail' => 'ledger table not migrated yet'];
            }
        } catch (\Throwable $e) {
            $checks['billing_webhooks'] = ['status' => 'down', 'error' => 'Cannot query processed_webhooks'];
            $allHealthy = false;
        }

        try {
            $disk = Storage::disk('public');
            $checks['storage'] = [
                'status' => $disk->exists('.') ? 'ok' : 'down',
            ];
        } catch (\Throwable $e) {
            $checks['storage'] = ['status' => 'down', 'error' => 'Storage unreachable'];
            $allHealthy = false;
        }

        // ── Coolify API (optional) ────────────────────────────────────
        $coolifyConfigured = config('services.coolify.api_token')
            && config('services.coolify.api_base_url');
        if ($coolifyConfigured) {
            try {
                // PlatformSyncService sets this key for a 2-hour window when
                // the Coolify API stops responding and forgets it on the
                // first successful sync — presence IS the outage signal.
                $checks['coolify'] = [
                    'status' => Cache::has('ops:sync:coolify-unreachable-alerted') ? 'unreachable' : 'ok',
                ];
            } catch (\Throwable $e) {
                $checks['coolify'] = ['status' => 'unknown'];
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
