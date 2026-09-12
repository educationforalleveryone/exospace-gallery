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
