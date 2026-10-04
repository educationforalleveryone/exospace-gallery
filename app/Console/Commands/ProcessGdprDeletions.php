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
    // A claim older than this means the run that took it died mid-deletion
    // (the daily cadence guarantees any live run is far younger); the request
    // is handed back to the queue instead of staying stranded.
    private const STALE_CLAIM_HOURS = 6;

    protected $signature = 'exospace:process-gdpr-deletions';
    protected $description = 'Execute GDPR deletion requests whose 30-day grace period has expired.';

    public function handle(UserDeletionService $deletionService, JobHeartbeatService $heartbeats): int
    {
        $this->reclaimStaleProcessing();

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
        // Each request is atomically claimed (pending → processing) so a
        // concurrent run (e.g. a manual invocation alongside the scheduled
        // task) can never execute the same deletion twice.
        foreach ($due as $request) {
            if (! $this->claim($request)) {
                continue;
            }

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
                $this->release($request);

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

    private function reclaimStaleProcessing(): void
    {
        $reclaimed = GdprDeletionRequest::where('status', 'processing')
            ->where('updated_at', '<', now()->subHours(self::STALE_CLAIM_HOURS))
            ->update(['status' => 'pending']);

        if ($reclaimed > 0) {
            Log::warning('ProcessGdprDeletions: reclaimed stale processing claims', [
                'count' => $reclaimed,
            ]);
        }
    }

    private function claim(GdprDeletionRequest $request): bool
    {
        $claimed = GdprDeletionRequest::where('id', $request->id)
            ->where('status', 'pending')
            ->where('scheduled_deletion_at', '<=', now())
            ->update(['status' => 'processing', 'updated_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        $request->refresh();

        return true;
    }

    private function release(GdprDeletionRequest $request): void
    {
        GdprDeletionRequest::where('id', $request->id)
            ->where('status', 'processing')
            ->update(['status' => 'pending']);
    }
}
