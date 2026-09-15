<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Support\ResilientCache;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Response;

class QrCodeController extends Controller
{
    public function show(string $slug): Response
    {
        $gallery = Gallery::where('slug', $slug)
            ->whereDoesntHave('user', fn ($q) => $q->whereNotNull('banned_at'))
            ->firstOrFail();

        if (! $gallery->is_active) {
            abort(404);
        }

        $format = request()->string('format', 'png')->toString();
        if (! in_array($format, ['png', 'svg'], true)) {
            // Anything else would poison the cache with a junk key per guess.
            abort(404);
        }

        $url = $gallery->public_url;

        // The encoded URL rides in the key: adding, removing or switching a
        // custom domain changes public_url and lands on a fresh entry instead
        // of serving codes that still point at the old address. Old keys
        // simply expire.
        $hostTag = $gallery->custom_domain ?: 'app';
        $cacheKey = "qr:{$slug}:{$hostTag}:{$format}";
        $content = ResilientCache::flexible($cacheKey, [now()->addDay(), now()->addDays(2)], function () use ($url, $format) {
            return $this->build($url, $format);
        });

        if ($format === 'svg') {
            return response($content, 200, [
                'Content-Type' => 'image/svg+xml',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }

        return response($content, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function build(string $url, string $format): string
    {
        if ($format === 'svg') {
            $result = Builder::create()
                ->writer(new SvgWriter())
                ->data($url)
                ->encoding(new Encoding('UTF-8'))
                ->errorCorrectionLevel(ErrorCorrectionLevel::High)
                ->size(400)
                ->margin(10)
                ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
                ->build();
            return $result->getString();
        }

        $result = Builder::create()
            ->data($url)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(ErrorCorrectionLevel::High)
            ->size(600)
            ->margin(20)
            ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
            ->build();
        return $result->getString();
    }
}
