<?php

namespace App\Console\Commands;

use App\Models\GdprDeletionRequest;
use App\Models\User;
use App\Services\JobHeartbeatService;
use App\Services\UserDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessGdprDeletions extends Command
{
    protected $signature = 'exospace:process-gdpr-deletions';
    protected $description = 'Execute GDPR deletion requests whose 30-day grace period has expired.';

    public function handle(UserDeletionService $deletionService, JobHeartbeatService $heartbeats): int
    {
        $due = GdprDeletionRequest::where('status', 'pending')
            ->where('scheduled_deletion_at', '<=', now())
            ->get();

        if ($due->isEmpty()) {
            $this->info('No due GDPR deletion requests.');

            app(JobHeartbeatService::class)->stamp('exospace:process-gdpr-deletions');

            return self::SUCCESS;
        }

        $completed = 0;
        $failed = 0;

        // Per-request isolation: one failing deletion (e.g. a corrupted user
        // row) must not stall the remaining requests until the next daily run.
        foreach ($due as $request) {
            try {
                $user = User::find($request->user_id);

                if ($user) {
                    $deletionService->deleteUser($user, 'GDPR deletion request (30-day grace period expired)');
                }

                $request->update([
                    'status'       => 'completed',
                    'completed_at' => now(),
                ]);

                $completed++;
            } catch (\Throwable $e) {
                $failed++;

                Log::error('ProcessGdprDeletions: deletion request failed', [
                    'request_id' => $request->id,
                    'user_id'    => $request->user_id,
                    'error'      => $e->getMessage(),
                ]);

                $this->error("Deletion request #{$request->id} failed: {$e->getMessage()}");
            }
        }

        $this->info("GDPR deletions: {$completed} completed, {$failed} failed ({$due->count()} due).");

        Log::info('ProcessGdprDeletions: run complete', [
            'due'      => $due->count(),
            'completed' => $completed,
            'failed'   => $failed,
        ]);

        // Only report cadence health when the run finished cleanly; a failed
        // run must not refresh the heartbeat so the alerting picks it up.
        if ($failed === 0) {
            $heartbeats->stamp('exospace:process-gdpr-deletions');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
