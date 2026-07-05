<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * P3-12: Thrown when an uploaded image's pixel dimensions exceed the safe
 * pre-decode cap (50MP by default).
 *
 * Thrown by ImageProcessingService::process() BEFORE Intervention::read() is
 * called, so the giant pixel buffer is never allocated. ImageController::store()
 * catches this and returns a 422 with a user-friendly message.
 *
 * Without this pre-check, a 12000×9000 PNG would decode to ~432MB of RGBA
 * pixels — enough to OOM a 256MB PHP-FPM worker (killed by the kernel, returns
 * 500 with no useful error to the client).
 */
class ImageTooLargeException extends RuntimeException
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $pixels,
        public readonly int $maxPixels,
    ) {
        $megapixels = number_format($pixels / 1_000_000, 1);
        $maxMegapixels = number_format($maxPixels / 1_000_000, 1);

        parent::__construct(
            "Image is {$width}x{$height} ({$megapixels}MP), which exceeds the "
            . "{$maxMegapixels}MP upload limit. Please resize the image to "
            . "at most ~7000x7000 pixels and try again."
        );
    }
}
