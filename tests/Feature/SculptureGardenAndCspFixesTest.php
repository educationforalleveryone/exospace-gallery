<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class SculptureGardenAndCspFixesTest extends TestCase
{

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

    public function test_artwork_group_builds_a_rear_backing_board_globally(): void
    {
        $src = file_get_contents(resource_path('js/gallery/ArtworkPlacer.js'));

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

    public function test_every_garden_manifest_role_ships_its_glb(): void
    {
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
