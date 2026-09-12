<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\QaTestRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunQaProfile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public function __construct(public int $placeholderRunId) {}

    public function handle(): void
    {
        $binary = config('test-center.phpunit_binary', 'vendor/bin/phpunit');

        // Hard re-check: capability could vanish between dispatch and worker.
        if (! file_exists(base_path($binary)) || app()->isProduction()) {
            $this->markBlocked('Runner unavailable in this environment. '
                .'Use GitHub Actions → Test Profiles → Run workflow.');

            return;
        }

        $run = QaTestRun::find($this->placeholderRunId);

        if (! $run) {
            return; // deleted while queued — nothing to do
        }

        $run->forceFill(['status' => QaTestRun::STATUS_RUNNING])->save();

        $exit = 0;
        system(sprintf(
            'cd %s && %s %s %s artisan qa:run %s --target=local >> storage/logs/qa-runner.log 2>&1',
            escapeshellarg(base_path()),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            '',
            escapeshellarg($run->profile),
        ), $exit);

        if ($exit !== 0) {
            $run->forceFill([
                'status'         => QaTestRun::STATUS_CANCELLED,
                'blocked_reason' => "Superseded by shell-out execution (exit {$exit}) — see newest run for {$run->profile}.",
                'finished_at'    => now(),
            ])->save();
        } else {
            $run->forceFill([
                'status'      => QaTestRun::STATUS_CANCELLED,
                'blocked_reason' => 'Superseded by shell-out execution — see newest run for '.$run->profile.'.',
                'finished_at' => now(),
            ])->save();
        }
    }

    private function markBlocked(string $reason): void
    {
        optional(QaTestRun::find($this->placeholderRunId))?->forceFill([
            'status'         => QaTestRun::STATUS_BLOCKED,
            'blocked_reason' => $reason,
            'finished_at'    => now(),
        ])->save();
    }
}
