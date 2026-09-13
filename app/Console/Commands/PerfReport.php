<?php

namespace App\Console\Commands;

use App\Models\AnalyticsEvent;
use Illuminate\Console\Command;

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
            $this->line('Beacons appear after visitors press Enter and stay ~15 s.');
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
