<?php

declare(strict_types=1);

namespace App\Support\Seo;

use Illuminate\Support\Arr;

/**
 * Canonical URL normalizer.
 *
 * Single source of truth for "what is the clean URL of this page".
 *
 * Rules (see docs/SEO_AUDIT.md §5):
 *  - Tracking/display parameters are ALWAYS stripped (config list).
 *  - Pagination parameter is preserved only when explicitly allowed.
 *  - Additional params can be preserved per-call (e.g. nothing today, but
 *    future faceted pages may whitelist a param).
 *
 * Usage:
 *   CanonicalUrl::clean(url()->current());                        // strip tracking junk
 *   CanonicalUrl::clean(url()->current(), preserve: ['page']);    // paginated self-canonical
 *   CanonicalUrl::path('discover');                               // absolute clean URL for a path
 */
final class CanonicalUrl
{
    /**
     * Return $url with all non-essential query params removed.
     *
     * @param  string              $url      Absolute URL (may contain query string)
     * @param  array<int,string>   $preserve Extra params to keep (beyond config-stripped list)
     * @param  bool                $allowPagination  Whether the pagination param may be preserved
     */
    public static function clean(string $url, array $preserve = [], bool $allowPagination = false): string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['query'])) {
            // No query string — strip a trailing '?' if present.
            return rtrim($url, '?');
        }

        parse_str($parts['query'], $params);

        $stripped = (array) config('seo.canonical.stripped_params', []);
        $paginationParam = (string) config('seo.canonical.pagination_param', 'page');

        $keep = [];
        foreach ($params as $key => $value) {
            $key = (string) $key;

            // Explicitly preserved params always win.
            if (in_array($key, $preserve, true)) {
                $keep[$key] = $value;
                continue;
            }
            // Known tracking/display params always drop.
            if (in_array(strtolower($key), array_map('strtolower', $stripped), true)) {
                continue;
            }
            // Pagination only when the caller allows it.
            if ($key === $paginationParam) {
                if ($allowPagination && self::isMeaningfulPagination($value)) {
                    $keep[$key] = $value;
                }
                continue;
            }
            // Unknown params: drop by default. Conservative — a canonical
            // URL should represent the page's identity, and unknown params
            // are more likely tracking (there are none in the app today).
        }

        $base = self::baseUrl($parts);

        if ($keep === []) {
            return $base;
        }

        // Rebuild in original parameter order for stable canonicals.
        $ordered = [];
        foreach ($params as $key => $value) {
            if (array_key_exists($key, $keep)) {
                $ordered[$key] = $keep[$key];
            }
        }

        return $base . '?' . http_build_query($ordered);
    }

    /**
     * Clean URL for a named route or path with NO query string at all.
     * Used for hub/static pages whose canonical never has params.
     */
    public static function path(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return url($path);
    }

    /**
     * Pagination self-canonical: keeps only the page param, drops the rest.
     */
    public static function paginated(string $url): string
    {
        return self::clean($url, preserve: [], allowPagination: true);
    }

    /**
     * rel="prev" / rel="next" URLs for a paginated listing.
     * Returns [prev => ?string, next => ?string].
     *
     * @param  string $baseUrl  Clean (param-less) listing URL
     * @param  int    $page     Current 1-based page
     * @param  bool   $hasMore  Whether a next page exists
     * @return array{prev: ?string, next: ?string}
     */
    public static function paginationLinks(string $baseUrl, int $page, bool $hasMore): array
    {
        $paginationParam = (string) config('seo.canonical.pagination_param', 'page');

        $prev = null;
        $next = null;

        if ($page > 1) {
            $prev = $page === 2
                ? $baseUrl // page 1 is the clean URL
                : $baseUrl . '?' . $paginationParam . '=' . ($page - 1);
        }
        if ($hasMore) {
            $next = $baseUrl . '?' . $paginationParam . '=' . ($page + 1);
        }

        return ['prev' => $prev, 'next' => $next];
    }

    /**
     * "page=1" and "page=0"/non-numeric values are not meaningful pagination
     * — they duplicate the unpaginated URL.
     *
     * @param mixed $value
     */
    private static function isMeaningfulPagination($value): bool
    {
        if (is_array($value)) {
            return false;
        }
        $int = (int) $value;

        return ((string) $int === (string) $value) && $int > 1;
    }

    /**
     * @param array<string, mixed> $parts
     */
    private static function baseUrl(array $parts): string
    {
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? parse_url(config('app.url'), PHP_URL_HOST) ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';

        return $scheme . '://' . $host . $port . $path;
    }
}
