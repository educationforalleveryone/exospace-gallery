<?php

namespace App\Jobs;

use App\Models\Gallery;
use App\Services\CoolifyDomainManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * S-2 FIX: Verify DNS for a single gallery's pending custom_domain, in parallel.
 *
 * Previously, VerifyPendingCustomDomains command did all 100 pending-domain
 * DNS lookups sequentially in a single cron tick. Each dns_get_record() call
 * blocks for up to 5 seconds on slow DNS resolvers, so 100 pending domains
 * could take up to 500 seconds — exceeding the cron's 60-second timeout and
 * leaving most domains unverified.
 *
 * Now VerifyPendingCustomDomains dispatches one VerifyCustomDomain job per
 * pending gallery. Queue workers process them in parallel (default: 1 worker
 * per cron tick, but you can scale to N workers via Coolify's worker service
 * config — see docker-start.sh). Each job does ONE dns_get_record() call,
 * so a 5-second timeout only blocks that one job, not the whole batch.
 *
 * Idempotent: if DNS hasn't propagated yet, the job silently exits (no error,
 * no retry). The next hourly cron tick will dispatch a fresh job for the same
 * gallery, which will succeed once DNS propagates.
 */
class VerifyCustomDomain implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // no retry — next hourly cron will redispatch
    public int $timeout = 15; // kill the job if dns_get_record blocks >15s

    public function __construct(
        public readonly int $galleryId,
    ) {}

    public function handle(CoolifyDomainManager $coolify): void
    {
        $gallery = Gallery::find($this->galleryId);

        if (! $gallery) {
            Log::info('VerifyCustomDomain: gallery not found (deleted?)', [
                'gallery_id' => $this->galleryId,
            ]);
            return;
        }

        // Defensive: if the gallery's custom_domain was cleared (downgrade,
        // admin action) between dispatch and handle, skip.
        if (! $gallery->custom_domain || ! $gallery->custom_domain_verification_token) {
            return;
        }

        // Already verified by a previous run — skip.
        if ($gallery->custom_domain_verified_at) {
            return;
        }

        $host = $gallery->domainVerificationTxtHost();
        $expected = $gallery->domainVerificationTxtValue();

        if (! $host || ! $expected) {
            return;
        }

        if (! $this->checkDnsTxtRecord($host, $expected)) {
            // DNS hasn't propagated yet — not an error, just not ready.
            // The next hourly cron tick will redispatch a fresh job.
            Log::debug('VerifyCustomDomain: TXT record not yet visible', [
                'gallery_id' => $gallery->id,
                'domain'     => $gallery->custom_domain,
            ]);
            return;
        }

        // Verified! Mark + register with Coolify + clear caches.
        $gallery->forceFill(['custom_domain_verified_at' => now()])->save();

        Cache::forget("custom_domain:{$gallery->custom_domain}");
        Cache::forget("custom_domain_gallery:{$gallery->id}");

        $result = $coolify->addDomain($gallery->custom_domain);
        if (! $result['success']) {
            Log::warning('VerifyCustomDomain: Coolify addDomain failed for verified domain', [
                'gallery_id' => $gallery->id,
                'domain'     => $gallery->custom_domain,
                'message'    => $result['message'],
            ]);
        } else {
            Log::info('VerifyCustomDomain: verified + registered', [
                'gallery_id' => $gallery->id,
                'domain'     => $gallery->custom_domain,
            ]);
        }
    }

    /**
     * Look up DNS TXT records for $host and return true if any record's
     * text matches $expectedValue exactly.
     *
     * Mirrors the private method in VerifyPendingCustomDomains + GalleryController.
     * Kept private here to avoid a service extraction (overkill for 3 callers).
     */
    private function checkDnsTxtRecord(string $host, string $expectedValue): bool
    {
        if (empty($host) || empty($expectedValue)) {
            return false;
        }

        $records = @dns_get_record($host, DNS_TXT);

        if (! is_array($records)) {
            return false;
        }

        foreach ($records as $record) {
            $candidates = [];
            if (isset($record['txt'])) {
                $candidates[] = trim($record['txt'], '"');
            }
            if (isset($record['entries']) && is_array($record['entries'])) {
                foreach ($record['entries'] as $entry) {
                    $candidates[] = trim($entry, '"');
                }
            }

            foreach ($candidates as $candidate) {
                if (hash_equals($expectedValue, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }
}
