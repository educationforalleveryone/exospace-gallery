<?php

namespace App\Console\Commands;

use App\Models\AnalyticsEvent;
use Illuminate\Console\Command;

/**
 * PERF-F31 (3D audit — iteration 6): real-user performance report.
 *
 * Aggregates 'perf' beacons (sent by the 3D viewer after 15 s of FPS
 * sampling per engaged visit) into a per-tier table:
 *
 *   php artisan exospace:perf-report            # last 14 days
 *   php artisan exospace:perf-report --days=30
 *
 * Reading the output:
 *   Sessions  — engaged visits that produced a beacon (Enter pressed)
 *   Avg FPS   — mean of per-visit average FPS; the headline experience number
 *   P10 FPS   — 10th percentile: the experience your WORST devices get
 *   Min FPS   — mean of per-visit worst 500 ms window (floor, not outlier)
 *   Draws     — mean draw calls per frame (draw-call merge verification)
 *   PR        — mean render pixel ratio (adaptive resolution verification)
 *   Heap MB   — mean JS heap (Chromium only); watch for growth across visits
 *   Enter ms  — mean ms from navigation start to Enter (load experience)
 *
 * Interpretation guide:
 *   P10 < 30 on mobile  → tighten the mobile tier (drop bloom/DPR further)
 *   Min FPS << Avg FPS  → stutter exists; check light-pool + decode paths
 *   Enter ms > 8000     → investigate network (WebP conversions? CDN?)
 *   Heap > ~500 MB      → memory leak investigation (dispose paths)
 *
 * The command is read-only and safe to run any time. No scheduling — run it
 * when evaluating a change, before/after a deploy.
 */
class PerfReport extends Command
{
    protected $signature = 'exospace:perf-report
                            {--days=14 : Look-back window in days}';

    protected $description = 'Aggregate real-user 3D performance beacons (perf events) by device tier';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $events = AnalyticsEvent::where('event', 'perf')
            ->where('created_at', '>=', $since)
            ->whereNotNull('perf_data')
            ->get(['perf_data', 'created_at']);

        if ($events->isEmpty()) {
            $this->info("No perf beacons in the last {$days} day(s).");
            $this->line('Beacons appear after visitors press Enter and stay ~15 s (iteration 6+ viewer).');
            return self::SUCCESS;
        }

        $rows = $events
            ->map(fn ($e) => $e->perf_data)
            ->filter(fn ($p) => is_array($p));

        $this->info("Exospace 3D — real-user performance, last {$days} day(s), {$rows->count()} session(s)");
        $this->newLine();

        $table = [];
        foreach (['high', 'mobile', 'low'] as $tier) {
            $tierRows = $rows->filter(fn ($p) => ($p['tier'] ?? null) === $tier)->values();
            if ($tierRows->isEmpty()) {
                continue;
            }
            $table[] = $this->summarize($tier, $tierRows);
        }
        $table[] = $this->summarize('ALL', $rows);

        $this->table(
            ['Tier', 'Sessions', 'Avg FPS', 'P10 FPS', 'Min FPS', 'Draws', 'PR', 'Heap MB', 'Enter ms', 'Partial'],
            $table
        );

        $this->newLine();
        $this->line('Avg FPS = mean of per-visit averages · P10 = worst-decile experience ·');
        $this->line('Min FPS = mean of per-visit worst 500 ms window · Partial = left before 15 s');

        return self::SUCCESS;
    }

    private function summarize(string $tier, $rows): array
    {
        $fpsList = $rows->pluck('fps')->filter()->sort()->values();
        $p10 = $fpsList->isEmpty() ? null
            : $fpsList->get(max(0, (int) floor($fpsList->count() * 0.10)));

        $avg = fn ($key, $default = '—') => ($v = $rows->pluck($key)->filter())->isEmpty()
            ? $default
            : round($v->avg(), $v->first() > 100 ? 0 : 1);

        return [
            $tier,
            $rows->count(),
            $avg('fps')          ?? '—',
            $p10                  ?? '—',
            $avg('fps_min')      ?? '—',
            $avg('draws')        ?? '—',
            $avg('pr')           ?? '—',
            $avg('heap')         ?? '—',
            $avg('ms', 0) === 0 && $rows->pluck('ms')->filter()->isEmpty() ? '—' : $avg('ms'),
            $avg('partial')      ?? '—',
        ];
    }
}
