<?php

declare(strict_types=1);

/**
 * Garden iteration-5 fix tests — post-V4 rendering errors + global artwork
 * backside / double-sided presentation fix.
 *
 * Pins the four iteration-5 contracts:
 *
 *   1. CSP: `connect-src` allows `blob:` — three.js's GLTFLoader converts
 *      every GLB-embedded image to a blob: object URL and loads it through
 *      ImageBitmapLoader, which fetch()es that URL. fetch() is governed by
 *      connect-src; without the token every embedded texture of every GLB
 *      failed in production. The header is asserted LIVE (production env),
 *      not just in source.
 *   2. /track transport: the analytics module no longer sends dwell/perf via
 *      navigator.sendBeacon (which cannot attach the X-CSRF-TOKEN header →
 *      guaranteed 419 for every engagement/perf event). Every event travels
 *      via fetch with keepalive + the CSRF header, with a dead-session backoff.
 *   3. Artwork rear presentation: the shared artwork group builder creates a
 *      rear backing board (named 'artwork-backing') so a visitor who can walk
 *      around a piece never sees an accidental empty frame. The canvas stays
 *      FrontSide; no venue-specific branch exists.
 *   4. Garden asset completeness: the v4 asset manifest's bench role ships
 *      its GLB (bench_01.glb) — the manifest ↔ files parity that stops the
 *      recurring load-time 404.
 *
 * Run: php artisan test --filter=GardenIteration5FixesTest
 */

namespace Tests\Feature;

use Tests\TestCase;

class GardenIteration5FixesTest extends TestCase
{
    // ── 1. CSP ──────────────────────────────────────────────────────────────

    public function test_csp_connect_src_allows_blob_for_glb_embedded_textures(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('/');
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp, 'CSP header should be set in non-local environments.');

        preg_match('/connect-src[^;]*/', $csp, $m);
        $this->assertNotEmpty($m, 'CSP must declare a connect-src directive.');
        $this->assertStringContainsString(
            'blob:',
            $m[0],
            'connect-src must allow blob: — three.js ImageBitmapLoader fetches GLB-embedded textures from blob: object URLs.'
        );
    }

    public function test_csp_keeps_its_hardening_posture(): void
    {
        $this->app['env'] = 'production';

        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        // The blob: addition must not have weakened anything else.
        $this->assertStringContainsString("'nonce-", $csp);
        $this->assertStringContainsString("'strict-dynamic'", $csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        // img-src already allowed blob: (TextureLoader path) — unchanged.
        preg_match('/img-src[^;]*/', $csp, $m);
        $this->assertStringContainsString('blob:', $m[0] ?? '');
    }

    // ── 2. /track transport ─────────────────────────────────────────────────

    public function test_analytics_never_uses_sendbeacon_for_csrf_protected_track(): void
    {
        $src = file_get_contents(resource_path('js/gallery/Analytics.js'));

        $this->assertStringNotContainsString(
            'sendBeacon(',
            $src,
            'sendBeacon cannot attach X-CSRF-TOKEN — every dwell/perf event 419s. All events must use fetch.'
        );
        $this->assertStringContainsString('keepalive: true', $src, 'Unload-time events (dwell/perf) need keepalive fetch.');
        $this->assertStringContainsString("'X-CSRF-TOKEN'", $src, 'Every track POST must carry the CSRF header.');
        $this->assertStringContainsString('_csrfDead', $src, 'A dead session must back off instead of erroring repeatedly.');
    }

    // ── 3. Artwork rear presentation ────────────────────────────────────────

    public function test_artwork_group_builds_a_rear_backing_board_globally(): void
    {
        $src = file_get_contents(resource_path('js/gallery/ArtworkPlacer.js'));

        // The backing is created in the SHARED artwork group builder (global
        // — not behind any venue branch).
        $this->assertStringContainsString(
            "backing.name = 'artwork-backing'",
            $src,
            'Every artwork group must build the rear backing board.'
        );
        $this->assertStringContainsString('backing.rotation.y = Math.PI', $src, 'The backing must face the artwork rear.');
        // No venue-specific conditioning may guard the fix.
        foreach (['sculpture-garden', 'sculptureGarden', 'isGarden'] as $slug) {
            $this->assertStringNotContainsString($slug, $src, "The artwork pipeline must stay venue-agnostic (found {$slug}).");
        }
    }

    // ── 4. Garden asset completeness ────────────────────────────────────────

    public function test_every_garden_manifest_role_ships_its_glb(): void
    {
        // Mirror resolveGardenAssetRequests({}): default manifest filenames
        // must exist under public/assets/venues/sculpture-garden/.
        $manifest = [
            'tree_large'  => 'tree_large_01.glb',
            'tree_medium' => 'tree_medium_01.glb',
            'tree_accent' => 'tree_medium_02.glb',
            'shrub'       => 'shrub_01.glb',
            'grass'       => 'grass_clump_01.glb',
            'boulder'     => 'boulder_01.glb',
            'bench'       => 'bench_01.glb',
        ];
        foreach ($manifest as $role => $file) {
            $this->assertFileExists(
                public_path('assets/venues/sculpture-garden/' . $file),
                "Garden asset role [{$role}] ships its GLB ({$file}) — a missing file is a recurring production 404."
            );
        }
    }
}
