<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoRedirect extends Model
{
    protected $fillable = [
        'source_path', 'destination', 'status_code', 'is_active', 'created_by',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'is_active' => 'boolean',
        'hits' => 'integer',
        'last_hit_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public static function normalizePath(string $path): string
    {
        $path = parse_url(trim($path), PHP_URL_PATH) ?? trim($path);
        $path = strtolower($path);
        $path = preg_replace('#/+#', '/', $path);
        $path = trim((string) $path, '/');

        return $path;
    }

    public static function cachedMap(): array
    {
        try {
            return \Illuminate\Support\Facades\Cache::remember(
                // Key carries the entry shape version: a deploy that changes
                // the shape orphans the previous map instead of serving
                // misaligned entries for the remainder of its TTL.
                'seo:redirects:map:v2',
                now()->addMinutes(10),
                fn () => static::query()->active()->get(['id', 'source_path', 'destination', 'status_code'])
                    ->mapWithKeys(fn ($r) => [
                        $r->source_path => [$r->id, $r->destination, (int) $r->status_code],
                    ])->all(),
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('SeoRedirects: redirect map unavailable — serving without redirects', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public static function clearMapCache(): void
    {
        \Illuminate\Support\Facades\Cache::forget('seo:redirects:map:v2');
    }

    /**
     * Whether adding a redirect from $sourcePath to $destination would create
     * a loop: the destination lands on the source itself, or the existing
     * redirect chain walks back onto the source. Only relative destinations
     * can loop — absolute URLs leave the host entirely. Walk depth is capped
     * so a pre-existing loop elsewhere in the map cannot spin this check.
     */
    public static function createsLoop(string $sourcePath, string $destination): bool
    {
        if ($destination === '' || $destination[0] !== '/') {
            return false;
        }

        $sourcePath = self::normalizePath($sourcePath);
        $current = self::normalizePath($destination);

        if ($current === $sourcePath) {
            return true;
        }

        $map = self::query()
            ->active()
            ->get(['source_path', 'destination'])
            ->mapWithKeys(fn ($r) => [self::normalizePath($r->source_path) => $r->destination]);

        for ($hop = 0; $hop < 10; $hop++) {
            $next = $map[$current] ?? null;

            if ($next === null || $next === '' || $next[0] !== '/') {
                return false;
            }

            $current = self::normalizePath($next);

            if ($current === $sourcePath) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count a redirect hit atomically (single UPDATE, no read-modify-write)
     * so concurrent hits cannot lose counts. Analytics only — callers must
     * never let a recording failure block the redirect.
     */
    public static function recordHit(int $id): void
    {
        try {
            static::query()
                ->whereKey($id)
                ->increment('hits', 1, ['last_hit_at' => now()]);
        } catch (\Throwable) {
            // Analytics only — never block the redirect.
        }
    }
}
