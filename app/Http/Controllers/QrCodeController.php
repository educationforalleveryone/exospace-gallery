<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Generates a QR code PNG for a gallery's public URL.
 *
 * Route: GET /gallery/{slug}/qr
 *
 * Useful for curators who print QR codes on physical signage at in-person
 * openings — visitors scan to enter the 3D exhibition on their phones.
 *
 * Two output formats:
 *   - Default: PNG (300×300, prints crisply on posters)
 *   - ?format=svg: SVG (scalable, smaller file, ideal for print design)
 *
 * Requires the `endroid/qr-code` composer package:
 *
 *   composer require endroid/qr-code
 *
 * Cached for 24 hours per slug+format.
 */
class QrCodeController extends Controller
{
    public function show(string $slug): Response
    {
        $gallery = Gallery::where('slug', $slug)->firstOrFail();
        $format = request()->string('format', 'png')->toString();
        $url = $gallery->public_url;

        $cacheKey = "qr:{$slug}:{$format}";
        $content = Cache::remember($cacheKey, now()->addDay(), function () use ($url, $format) {
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
