<?php

namespace App\Console\Commands;

use App\Jobs\VerifyCustomDomain;
use App\Models\Gallery;
use App\Services\CoolifyDomainManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
