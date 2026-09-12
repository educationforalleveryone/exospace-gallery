<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RobotsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $resolvedGallery = $request->attributes->get('resolved_gallery');

        if ($resolvedGallery) {
            $body = $this->customDomainRobots($request);
        } else {
            $body = $this->primaryRobots();
        }

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function primaryRobots(): string
    {
        $lines = [
            '# ' . config('seo.site_name', 'Exospace') . ' — robots.txt',
            '# Public exhibitions, artists, artworks and hubs are crawlable.',
            '# Admin, auth, billing and duplicate/preview endpoints are not.',
            '',
            'User-agent: *',
            'Allow: /',
        ];

        foreach ((array) config('seo.robots.disallow', []) as $path) {
            $lines[] = 'Disallow: ' . $path;
        }
        foreach ((array) config('seo.robots.disallow_query', []) as $pattern) {
            $lines[] = 'Disallow: ' . $pattern;
        }

        $lines[] = '';
        $lines[] = '# Sitemap';
        $lines[] = 'Sitemap: ' . rtrim((string) config('app.url'), '/') . '/sitemap.xml';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function customDomainRobots(Request $request): string
    {
        $host = $request->getSchemeAndHttpHost();

        $lines = [
            '# robots.txt for ' . $host,
            '# This host serves a single white-label exhibition. Everything is',
            '# crawlable; the sitemap below lists this exhibition only.',
            '',
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /login',
            'Disallow: /register',
            '',
            'Sitemap: ' . $host . '/sitemap.xml',
            '',
        ];

        return implode("\n", $lines);
    }
}
