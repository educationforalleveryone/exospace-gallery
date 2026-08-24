<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Managed SEO redirect (Iteration 4).
 *
 * Source paths are stored WITHOUT a leading slash, lowercased. The
 * middleware matches request path (lowercased, leading slash stripped,
 * query string ignored) against the cached active redirect map.
 */
class SeoRedirect extends Model
{
    protected $fillable = [
        'source_path', 'destination', 'status_code', 'is_active', 'created_by',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'is_active'   => 'boolean',
        'hits'        => 'integer',
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

    /**
     * Normalize a path for storage/lookup: lowercase, no leading slash,
     * trailing slash collapsed, query string removed.
     */
    public static function normalizePath(string $path): string
    {
        $path = parse_url(trim($path), PHP_URL_PATH) ?? trim($path);
        $path = strtolower($path);
        $path = preg_replace('#/+#', '/', $path);
        $path = trim((string) $path, '/');

        return $path;
    }

    /**
     * The cached redirect map: [source_path => [destination, status_code]].
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function cachedMap(): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            'seo:redirects:map',
            now()->addMinutes(10),
            fn () => static::query()->active()->get(['source_path', 'destination', 'status_code'])
                ->mapWithKeys(fn ($r) => [
                    $r->source_path => [$r->destination, (int) $r->status_code],
                ])->all(),
        );
    }

    public static function clearMapCache(): void
    {
        \Illuminate\Support\Facades\Cache::forget('seo:redirects:map');
    }

    /**
     * Record a hit (fire-and-forget; failure must never break the redirect).
     */
    public function recordHit(): void
    {
        try {
            $this->forceFill(['hits' => $this->hits + 1, 'last_hit_at' => now()])->save();
        } catch (\Throwable) {
            // Analytics only — never block the redirect.
        }
    }
}
