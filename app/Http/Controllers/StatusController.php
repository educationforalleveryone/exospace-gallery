<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use App\Support\ResilientCache;

class StatusController extends Controller
{
    public function show(Request $request): View
    {
        $checks = ResilientCache::remember('status:page', now()->addMinute(), function () {
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
