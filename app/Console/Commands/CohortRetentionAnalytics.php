<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * M-17: Cohort retention analytics.
 *
 * Groups users into weekly cohorts by registration date, then tracks
 * what % of each cohort is still active (has logged in or created/updated
 * a gallery) in each subsequent week.
 *
 * Output: a retention matrix (cohort × week → % retained).
 */
class CohortRetentionAnalytics extends Command
{
    protected $signature = 'exospace:cohort-retention {--weeks=8 : Number of weeks to analyze}';
    protected $description = 'Generate cohort retention analytics report.';

    public function handle(): int
    {
        $weeks = (int) $this->option('weeks');
        $this->info("Cohort retention (last {$weeks} weeks)");
        $this->newLine();

        // Build weekly cohorts
        $cohorts = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $weekStart = now()->startOfWeek()->subWeeks($i);
            $weekEnd = $weekStart->copy()->addWeek();

            $cohortSize = User::whereBetween('created_at', [$weekStart, $weekEnd])->count();
            $cohortLabel = $weekStart->format('M j');

            $retention = [];
            for ($w = 0; $w < $weeks - $i; $w++) {
                $periodStart = $weekStart->copy()->addWeeks($w);
                $periodEnd = $periodStart->copy()->addWeek();

                // Active = updated a gallery or logged in (session updated) during this period
                $active = User::whereBetween('created_at', [$weekStart, $weekEnd])
                    ->where(function ($q) use ($periodStart, $periodEnd) {
                        $q->whereExists(function ($sq) use ($periodStart, $periodEnd) {
                            $sq->select(DB::raw(1))
                                ->from('galleries')
                                ->whereColumn('galleries.user_id', 'users.id')
                                ->whereBetween('galleries.updated_at', [$periodStart, $periodEnd]);
                        })->orWhere('users.updated_at', '>=', $periodStart);
                    })
                    ->count();

                $retention[$w] = $cohortSize > 0 ? round(($active / $cohortSize) * 100, 1) : 0;
            }

            $cohorts[] = [
                'label' => $cohortLabel,
                'size'  => $cohortSize,
                'retention' => $retention,
            ];
        }

        // Print retention matrix
        $this->info(str_pad('Cohort', 12) . str_pad('Size', 8) . implode('', array_map(fn($w) => str_pad("W{$w}", 8), range(0, $weeks - 1))));
        $this->info(str_repeat('-', 12 + 8 + $weeks * 8));

        foreach ($cohorts as $cohort) {
            $row = str_pad($cohort['label'], 12) . str_pad((string)$cohort['size'], 8);
            foreach ($cohort['retention'] as $pct) {
                $row .= str_pad($pct > 0 ? "{$pct}%" : '-', 8);
            }
            $this->info($row);
        }

        $this->newLine();
        $this->info('W0 = registration week, W1 = 1 week after, etc.');
        $this->info('Values = % of cohort users active during that week (gallery update or profile update).');

        Log::info('CohortRetentionAnalytics: report generated', [
            'weeks' => $weeks,
            'cohorts' => count($cohorts),
        ]);

        return self::SUCCESS;
    }
}
