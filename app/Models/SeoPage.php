<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SeoPage extends Model
{
    public const TYPES = ['landing', 'editorial'];

    public const STATUSES = ['draft', 'published'];

    protected $fillable = [
        'type', 'slug', 'title', 'seo_title', 'meta_description',
        'blocks', 'og_image_path', 'canonical_override', 'noindex',
        'status', 'published_at', 'author_id',
    ];

    protected $casts = [
        'blocks'       => 'array',
        'noindex'      => 'boolean',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Slug normalization: lowercase, hyphens only.
        static::saving(function (self $page) {
            $page->slug = Str::slug($page->slug);
            $page->type = in_array($page->type, self::TYPES, true) ? $page->type : 'landing';
            $page->status = in_array($page->status, self::STATUSES, true) ? $page->status : 'draft';
        });

        // Keep the fallback-route slug allow-list fresh.
        static::saved(fn () => self::bumpCacheVersion());
        static::deleted(fn () => self::bumpCacheVersion());
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', 'published')
            ->where(function ($q) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    public function getPublicUrlAttribute(): string
    {
        if ($this->type === 'editorial') {
            $prefix = (string) config('seo.pages.editorial_prefix', 'resources');

            return url("{$prefix}/{$this->slug}");
        }

        return url('/' . $this->slug);
    }

    public function effectiveTitle(): string
    {
        return $this->seo_title ?: $this->title;
    }

    public function isScheduled(): bool
    {
        return $this->published_at !== null && $this->published_at->isFuture();
    }

    public function isIndexable(): bool
    {
        return $this->status === 'published' && !$this->noindex && !$this->isScheduled();
    }

    public function previewToken(): string
    {
        return hash_hmac('sha256', 'seo-page-preview:' . $this->id, (string) config('app.key'));
    }

    public function isValidPreviewToken(?string $token): bool
    {
        return $token !== null && hash_equals($this->previewToken(), $token);
    }

    public static function cachedSlugMap(): array
    {
        try {
            $version = (int) \Illuminate\Support\Facades\Cache::get('seo:pages:version', 1);

            return \Illuminate\Support\Facades\Cache::remember(
                "seo:pages:slug-map:v{$version}",
                now()->addHour(),
                fn () => static::publishedSlugMap(),
            );
        } catch (\Throwable $e) {
            // The fallback route runs this on every unmatched path — a cache
            // outage must degrade to a direct query, not 500 the request.
            \Illuminate\Support\Facades\Log::warning('SeoPage: slug map cache unavailable — querying directly', [
                'error' => $e->getMessage(),
            ]);

            return static::publishedSlugMap();
        }
    }

    private static function publishedSlugMap(): array
    {
        return static::query()
            ->where('status', 'published')
            ->get(['id', 'type', 'slug'])
            ->mapWithKeys(fn ($page) => [
                self::pathFor($page->type, $page->slug) => $page->id,
            ])
            ->all();
    }

    public static function bumpCacheVersion(): void
    {
        $bump = function (): void {
            try {
                // Atomic increment — concurrent saves must not lose a bump.
                \Illuminate\Support\Facades\Cache::add('seo:pages:version', 1);
                \Illuminate\Support\Facades\Cache::increment('seo:pages:version');
            } catch (\Throwable $e) {
                // Slug-map entries carry an hourly TTL, so a failed bump
                // degrades to TTL staleness instead of failing the save.
                \Illuminate\Support\Facades\Log::warning('SeoPage: slug map version bump failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        };

        // Same commit-ordering rule as the sitemap version: never rotate the
        // slug map before the page row is actually committed.
        \Illuminate\Support\Facades\DB::afterCommit($bump);
    }

    public static function pathFor(string $type, string $slug): string
    {
        if ($type === 'editorial') {
            $prefix = (string) config('seo.pages.editorial_prefix', 'resources');

            return $prefix . '/' . $slug;
        }

        return $slug;
    }
}
