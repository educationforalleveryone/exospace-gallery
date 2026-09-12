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
                'seo:redirects:map',
                now()->addMinutes(10),
                fn () => static::query()->active()->get(['source_path', 'destination', 'status_code'])
                    ->mapWithKeys(fn ($r) => [
                        $r->source_path => [$r->destination, (int) $r->status_code],
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
        \Illuminate\Support\Facades\Cache::forget('seo:redirects:map');
    }

    public function recordHit(): void
    {
        try {
            $this->forceFill(['hits' => $this->hits + 1, 'last_hit_at' => now()])->save();
        } catch (\Throwable) {
            // Analytics only — never block the redirect.
        }
    }
}
