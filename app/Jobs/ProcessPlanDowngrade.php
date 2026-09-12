<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\PlanDowngradeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPlanDowngrade implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 180, 540];

    public function __construct(
        public readonly int $userId,
        public readonly string $reason,
    ) {}

    public function handle(PlanDowngradeService $service): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            Log::info('ProcessPlanDowngrade: user not found (deleted?)', [
                'user_id' => $this->userId,
            ]);
            return;
        }

        if ($user->plan !== 'free') {
            Log::info('ProcessPlanDowngrade: user is no longer on free — skipping cleanup', [
                'user_id' => $this->userId,
                'plan'    => $user->plan,
            ]);
            return;
        }

        Log::info('ProcessPlanDowngrade: starting gallery cleanup', [
            'user_id' => $this->userId,
            'reason'  => $this->reason,
        ]);

        $service->downgradeToFree($user, $this->reason);

        Log::info('ProcessPlanDowngrade: cleanup complete', [
            'user_id' => $this->userId,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProcessPlanDowngrade: job failed after retries', [
            'user_id' => $this->userId,
            'reason'  => $this->reason,
            'error'   => $e->getMessage(),
        ]);
    }
}
