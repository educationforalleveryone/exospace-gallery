<?php

declare(strict_types=1);

namespace App\Support\Seo;

use Illuminate\Support\Arr;

final class CanonicalUrl
{
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

    public static function path(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return url($path);
    }

    public static function paginated(string $url): string
    {
        return self::clean($url, preserve: [], allowPagination: true);
    }

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

    private static function isMeaningfulPagination($value): bool
    {
        if (is_array($value)) {
            return false;
        }
        $int = (int) $value;

        return ((string) $int === (string) $value) && $int > 1;
    }

    private static function baseUrl(array $parts): string
    {
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? parse_url(config('app.url'), PHP_URL_HOST) ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';

        return $scheme . '://' . $host . $port . $path;
    }
}
