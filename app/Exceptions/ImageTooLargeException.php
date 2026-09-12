<?php

namespace App\Exceptions;

use RuntimeException;

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
