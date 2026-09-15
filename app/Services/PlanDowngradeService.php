<?php

namespace App\Services;

use App\Models\Gallery;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PlanDowngradeService
{
    public function __construct(
        private readonly CoolifyDomainManager $coolify,
    ) {}

    public function downgradeToFree(User $user, string $reason): void
    {
        $limits = User::planLimits('free');

        $user->forceFill([
            'plan'            => 'free',
            'max_galleries'   => $limits['max_galleries'],
            'max_images'      => $limits['max_images'],
            'plan_expires_at' => now(),
        ])->save();

        Log::info('PlanDowngradeService: user downgraded to free', [
            'user_id' => $user->id,
            'reason'  => $reason,
        ]);

        $user->galleries()
            ->where(function ($q) {
                $q->whereNotNull('custom_domain')
                  ->orWhereNotNull('custom_logo_path')
                  ->orWhereNotNull('curtain_logo_path')
                  ->orWhereNotNull('audio_path');
            })
            ->chunkById(50, function ($galleries) use ($reason) {
                foreach ($galleries as $gallery) {
                    $this->cleanupGalleryStudioResources($gallery, $reason);
                }
            });
    }

    public function cleanupGalleryStudioResources(Gallery $gallery, string $reason = ''): void
    {
        $customDomain = $gallery->getOriginal('custom_domain');

        if (! empty($customDomain)) {
            try {
                $result = $this->coolify->removeDomain($customDomain);
                if (! $result['success']) {
                    Log::warning('PlanDowngradeService: CoolifyDomainManager::removeDomain failed', [
                        'gallery_id' => $gallery->id,
                        'domain'     => $customDomain,
                        'message'    => $result['message'] ?? null,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('PlanDowngradeService: CoolifyDomainManager::removeDomain threw', [
                    'gallery_id' => $gallery->id,
                    'domain'     => $customDomain,
                    'error'      => $e->getMessage(),
                ]);
            }

            $normalizedHost = $this->normalizeHostForCache($customDomain);
            Cache::forget("custom_domain:{$normalizedHost}");
            Cache::forget("custom_domain:{$customDomain}");

            $gallery->forceFill([
                'custom_domain'                     => null,
                'custom_domain_verification_token'  => null,
                'custom_domain_verified_at'         => null,
            ])->save();

            Log::info('PlanDowngradeService: cleared custom_domain', [
                'gallery_id' => $gallery->id,
                'domain'     => $customDomain,
                'reason'     => $reason,
            ]);
        }

        $fileFields = ['custom_logo_path', 'curtain_logo_path', 'audio_path'];

        $updates = [];
        foreach ($fileFields as $field) {
            $path = $gallery->getOriginal($field);
            if (empty($path)) {
                continue;
            }

            $this->deletePublicDiskFile($path);
            $updates[$field] = null;

            Log::info('PlanDowngradeService: cleared file field', [
                'gallery_id' => $gallery->id,
                'field'      => $field,
                'reason'     => $reason,
            ]);
        }

        if (! empty($updates)) {
            $gallery->forceFill($updates)->save();
        }
    }

    private function deletePublicDiskFile(string $path): void
    {
        if (empty($path)) {
            return;
        }

        $disk = Storage::disk('public');

        $clean = \Illuminate\Support\Str::after($path, 'storage/');

        try {
            if ($disk->exists($clean)) {
                $disk->delete($clean);
            } elseif ($disk->exists($path)) {
                // Defensive fallback for paths stored without the storage/ prefix.
                $disk->delete($path);
            }
        } catch (\Throwable $e) {
            Log::warning('PlanDowngradeService: file delete failed', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function normalizeHostForCache(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = explode('/', $domain)[0];
        $domain = explode(':', $domain)[0];
        $domain = preg_replace('/^www\./', '', $domain);
        return $domain;
    }
}
