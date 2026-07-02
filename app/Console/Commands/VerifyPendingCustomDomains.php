<?php

namespace App\Console\Commands;

use App\Models\Gallery;
use App\Services\CoolifyDomainManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Auto-retry DNS verification for galleries with a pending custom_domain.
 *
 * Scheduled hourly via routes/console.php. Finds all galleries where
 * custom_domain is set but custom_domain_verified_at is NULL, looks up
 * the TXT record, and if found, marks the gallery verified + registers
 * the domain with Coolify.
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
 */
class VerifyPendingCustomDomains extends Command
{
    protected $signature = 'exospace:verify-pending-domains';
    protected $description = 'Retry DNS verification for galleries with a pending custom_domain.';

    public function handle(CoolifyDomainManager $coolify): int
    {
        $pending = Gallery::whereNotNull('custom_domain')
            ->whereNull('custom_domain_verified_at')
            ->whereNotNull('custom_domain_verification_token')
            ->limit(100) // Safety cap — DNS lookups are slow, don't OOM
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No pending custom-domain verifications.');
            return self::SUCCESS;
        }

        $verified = 0;
        $failed = 0;

        foreach ($pending as $gallery) {
            $host = $gallery->domainVerificationTxtHost();
            $expected = $gallery->domainVerificationTxtValue();

            if (! $host || ! $expected) {
                continue;
            }

            if ($this->checkDnsTxtRecord($host, $expected)) {
                $gallery->forceFill(['custom_domain_verified_at' => now()])->save();

                // Clear the cache so DetectCustomDomain picks it up.
                Cache::forget("custom_domain:{$gallery->custom_domain}");

                // Register with Coolify.
                $result = $coolify->addDomain($gallery->custom_domain);
                if (! $result['success']) {
                    Log::warning('VerifyPendingCustomDomains: Coolify addDomain failed for verified domain', [
                        'gallery_id' => $gallery->id,
                        'domain'     => $gallery->custom_domain,
                        'message'    => $result['message'],
                    ]);
                }

                $this->info("Verified: {$gallery->custom_domain} (gallery #{$gallery->id})");
                $verified++;
            } else {
                $failed++;
            }
        }

        $this->info("Done. Verified: {$verified}, still pending: {$failed}.");
        return self::SUCCESS;
    }

    /**
     * Look up DNS TXT records for $host and return true if any record's
     * text matches $expectedValue exactly.
     *
     * Mirrors GalleryController::checkDnsTxtRecord — kept private here
     * to avoid forcing a service extraction (which would be overkill for
     * two callers).
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
