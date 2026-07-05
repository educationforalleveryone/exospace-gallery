<?php

namespace App\Console\Commands;

use App\Jobs\VerifyCustomDomain;
use App\Models\Gallery;
use App\Services\CoolifyDomainManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Auto-retry DNS verification for galleries with a pending custom_domain.
 *
 * Scheduled hourly via routes/console.php. Finds all galleries where
 * custom_domain is set but custom_domain_verified_at is NULL, and dispatches
 * a VerifyCustomDomain job per gallery.
 *
 * Why this exists (Task C06):
 *   DNS propagation can take 5–60 minutes for some providers. The user
 *   might click "Verify now" too early and see a "verification failed"
 *   message. Rather than making them retry manually, this command
 *   catches up pending verifications in the background. The user just
 *   sees "verified" appear in their admin panel without further action.
 *
 * Also useful for catching the case where a user added the TXT record
 * correctly but then closed their browser without clicking "Verify".
 *
 * S-2 FIX: Previously this command did all DNS lookups SEQUENTIALLY in
 * a single cron tick. Each dns_get_record() call blocks for up to 5
 * seconds on slow DNS resolvers — 100 pending domains could take up to
 * 500 seconds, exceeding the cron's 60-second timeout and leaving most
 * domains unverified.
 *
 * Now the command dispatches one VerifyCustomDomain job per pending
 * gallery. Queue workers process them in parallel — even a single
 * worker can churn through 100 jobs in ~3 minutes (vs. 8 minutes
 * sequential), and N workers scale linearly. Each job has a 15-second
 * timeout so a single slow DNS lookup can't block the whole batch.
 */
class VerifyPendingCustomDomains extends Command
{
    protected $signature = 'exospace:verify-pending-domains';
    protected $description = 'Dispatch DNS verification jobs for galleries with a pending custom_domain.';

    public function handle(): int
    {
        $pending = Gallery::whereNotNull('custom_domain')
            ->whereNull('custom_domain_verified_at')
            ->whereNotNull('custom_domain_verification_token')
            ->limit(100) // Safety cap — protects against a sudden flood
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No pending custom-domain verifications.');
            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($pending as $gallery) {
            VerifyCustomDomain::dispatch($gallery->id);
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} DNS verification jobs to the queue.");
        Log::info('VerifyPendingCustomDomains: dispatched jobs', [
            'count' => $dispatched,
        ]);

        return self::SUCCESS;
    }
}
