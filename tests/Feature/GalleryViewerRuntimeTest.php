<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class GalleryViewerRuntimeTest extends TestCase
{
    private const VIEWER_PAGES = [
        'resources/views/gallery/view.blade.php',
        'resources/views/venues/preview.blade.php',
    ];

    public function test_mobile_touch_input_stands_down_while_the_tour_scripts_the_camera(): void
    {
        $mobile = (string) file_get_contents(base_path('resources/js/gallery/Mobile.js'));

        $this->assertStringContainsString(
            'if (this._cameraScripted) return;',
            $mobile,
            'Mobile movement must early-return while the guided tour tweens the camera — '
            .'the desktop path is gated by pointer-lock, touch input has no such gate.'
        );
    }

    public function test_touch_devices_receive_touch_hints_not_keyboard_hints(): void
    {
        foreach (self::VIEWER_PAGES as $view) {
            $source = (string) file_get_contents(base_path($view));

            $block = $this->coarsePointerBlock($source);
            $this->assertNotNull($block, "{$view}: the coarse-pointer media query must exist.");

            $this->assertStringContainsString('.desktop-hint, .desktop-text { display: none !important; }', $block,
                "{$view}: keyboard hints must be hidden on touch devices.");
            $this->assertStringContainsString('.mobile-hint  { display: block !important; }', $block,
                "{$view}: the touch hint ships Tailwind's `hidden` — the media query must flip it back on.");
            $this->assertStringContainsString('.mobile-text  { display: inline !important; }', $block,
                "{$view}: the focus-mode touch hint must be flipped back on.");
        }
    }

    public function test_info_panel_fits_phone_viewports_on_touch_pages(): void
    {
        foreach (self::VIEWER_PAGES as $view) {
            $source = (string) file_get_contents(base_path($view));

            $block = $this->coarsePointerBlock($source);
            $this->assertNotNull($block, "{$view}: the coarse-pointer media query must exist.");

            $this->assertStringContainsString('#info-panel', $block,
                "{$view}: the fixed 380px info panel overflows a phone viewport — the coarse-pointer block must fit it.");
        }
    }

    /**
     * Extract the balanced `@media (pointer: coarse), (hover: none) { ... }` block.
     */
    private function coarsePointerBlock(string $source): ?string
    {
        $start = strpos($source, '@media (pointer: coarse), (hover: none)');
        if ($start === false) {
            return null;
        }

        $open = strpos($source, '{', $start);
        $depth = 0;
        for ($i = $open, $len = strlen($source); $i < $len; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open);
                }
            }
        }

        return null;
    }

    public function test_hdri_loader_is_imported_directly_without_a_dead_fallback_chain(): void
    {
        $loader = (string) file_get_contents(base_path('resources/js/gallery/AssetLoader.js'));

        $this->assertStringContainsString(
            "import { HDRLoader } from 'three/addons/loaders/HDRLoader.js';",
            $loader,
            'HDRLoader must be imported directly — the RGBE fallback chain referenced a '
            .'non-existent export and emitted a Rollup warning on every production build.'
        );
        $this->assertStringNotContainsString('RGBELoader', $loader,
            'The dead RGBELoader fallback must stay removed.');
    }
}
