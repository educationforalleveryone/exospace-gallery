<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Organic acquisition capture (SEO OS Iteration 7).
 *
 * On a visitor's FIRST page view (per session), record:
 *   - HTTP referrer (as sent by the browser)
 *   - landing page path
 *   - UTM params if present
 *   - a classified channel bucket
 *
 * The data is copied onto the user at registration (User::creating hook)
 * and powers the organic-acquisition report in the SEO console. It is
 * first-party, session-scoped, and never shared with third parties.
 *
 * Channel classification:
 *   campaign  — any utm_source present (the campaign owns the attribution)
 *   social    — referrer host matches a known social platform
 *   organic   — referrer host matches a known search engine
 *   referral  — referrer host is any other site
 *   direct    — no referrer (or same-host)
 */
class CaptureAcquisitionContext
{
    /** Search engine hosts (organic). */
    private const SEARCH_HOSTS = [
        'google.', 'bing.com', 'duckduckgo.com', 'yahoo.', 'ecosia.org',
        'brave.com', 'search.brave.com', 'startpage.com', 'baidu.com',
        'yandex.', 'qwant.com', 'mojeek.com', 'sogou.com', 'naver.com',
        'perplexity.ai', 'chatgpt.com', 'copilot.microsoft.com',
    ];

    /** Social platform hosts. */
    private const SOCIAL_HOSTS = [
        'facebook.com', 'instagram.com', 'twitter.com', 'x.com',
        'linkedin.com', 'tiktok.com', 'pinterest.', 'reddit.com',
        'youtube.com', 'threads.net', 'bsky.app', 'mastodon.',
        't.co', 'bit.ly',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Only the first HTML page view per session carries attribution;
        // subsequent views would overwrite with navigational referrers.
        if ($request->isMethod('get') && !$request->session()->has('acquisition')) {
            $request->session()->put('acquisition', $this->capture($request));
        }

        return $next($request);
    }

    /**
     * @return array{channel: string, referrer: ?string, landing_page: string, utm: array<string, string>}
     */
    private function capture(Request $request): array
    {
        $referrer = $request->headers->get('referer');
        $referrerHost = $referrer ? strtolower((string) parse_url($referrer, PHP_URL_HOST)) : null;
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        $utm = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
            $value = $request->query($key);
            if (is_string($value) && $value !== '') {
                $utm[$key] = mb_substr($value, 0, 200);
            }
        }

        $channel = $this->classify($referrerHost, $appHost, $utm !== []);

        return [
            'channel' => $channel,
            'referrer' => $referrer ? mb_substr($referrer, 0, 500) : null,
            'landing_page' => mb_substr('/' . ltrim($request->path(), '/'), 0, 500),
            'utm' => $utm,
        ];
    }

    private function classify(?string $referrerHost, string $appHost, bool $hasUtm): string
    {
        if ($hasUtm) {
            return 'campaign';
        }

        if (!$referrerHost || $referrerHost === $appHost || $referrerHost === 'www.' . $appHost) {
            return 'direct';
        }

        foreach (self::SEARCH_HOSTS as $host) {
            if (str_starts_with($referrerHost, $host) || str_contains($referrerHost, '.' . $host) || $referrerHost === $host || str_starts_with($referrerHost, 'www.' . $host)) {
                return 'organic';
            }
        }

        foreach (self::SOCIAL_HOSTS as $host) {
            if (str_contains($referrerHost, $host)) {
                return 'social';
            }
        }

        return 'referral';
    }
}
