<?php

declare(strict_types=1);

/**
 * VISUAL-OVERRIDE NORMALIZATION — regression tests for the
 * deployed-screenshot incident (2026-09-05).
 *
 * A gallery served a broken-looking Infinite Void for days because its
 * visual_overrides column carried (a) a saturated purple background +
 * pre-polish dim rig saved from an old Live-Preview session and (b) stale
 * no-op post_fx values. Because the override layer ALWAYS wins over the
 * venue row (by design — curator intent), every later venue-side
 * remediation silently no-ops for such galleries, and nothing in the
 * payload showed the layer existed.
 *
 * These tests pin the two guards that make that class of incident
 * recoverable and non-accumulating:
 *
 *   1. SAVE-SIDE NORMALIZATION (GalleryController): persisted overrides are
 *      compared against the venue's CURRENT declaration — keys that only
 *      restate it (forgiving colour-format and numeric-string drift) are
 *      dropped; real deviations persist in canonical form ('0x…' colours);
 *      keys the venue does not declare are kept (deviation from nothing is
 *      intent). A venue SWITCH in the same save normalizes against the NEW
 *      venue, not the stale relation.
 *   2. EXPORT AUTHORITY (VenueConfigExporter): the gallery payload's only
 *      post-fx authority is visual_config.post_fx. The legacy-shaped
 *      sibling `post_fx` bucket (read by nothing on the page-load path —
 *      the panel patches over postMessage) must NOT ship again.
 *
 * Run: php artisan test --filter=GalleryVisualOverrideNormalizationTest
 */

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\User;
use App\Models\VenueTemplate;
use App\Services\VenueConfigExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GalleryVisualOverrideNormalizationTest extends TestCase
{
    use RefreshDatabase;

    /** The remediated Infinite Void declaration (v2.0.0 end state subset). */
    private function voidVenue(array $overrides = []): VenueTemplate
    {
        return VenueTemplate::factory()->create(array_merge([
            'name'            => 'Infinite Void',
            'slug'            => 'infinite-void-' . uniqid(),
            'visual_config'   => [
                'background_color'      => '0x000000',
                'ambient_intensity'     => 0.3,
                'spot_intensity'        => 1.3,
                'tone_mapping_exposure' => 0.9,
                'post_fx' => [
                    'bloom'             => false,
                    'vignette'          => true,
                    'vignette_darkness' => 1.0,
                    'vignette_offset'   => 1.35,
                ],
            ],
            'material_config' => [
                'floor_color'     => '0x0a0a0a',
                'floor_roughness' => 0.32,
                'floor_metalness' => 0.25,
            ],
            'is_draft'        => false,
            'is_active'       => true,
        ], $overrides));
    }

    /** Valid GalleryController::update() payload (whitelisted values). */
    private function updatePayload(array $overrides = []): array
    {
        return array_merge([
            'title'           => 'Normalization Test Gallery',
            'wall_texture'    => 'white',
            'frame_style'     => 'modern',
            'lighting_preset' => 'dramatic',
            'floor_material'  => 'marble',
            'room_layout'     => 'rotunda',
        ], $overrides);
    }

    // ── 1. No-op overrides never persist ─────────────────────────────────

    public function test_overrides_that_restate_the_venue_are_dropped(): void
    {
        $user    = User::factory()->create();
        $venue   = $this->voidVenue();
        $gallery = Gallery::factory()->create([
            'user_id'           => $user->id,
            'venue_template_id' => $venue->id,
        ]);

        // Restating the venue in drifted formats: '#000000' == '0x000000',
        // '0.9' == 0.9 — none of this is curator intent.
        $json = json_encode([
            'visual_config' => [
                'background_color'      => '#000000',
                'ambient_intensity'     => 0.3,
                'tone_mapping_exposure' => '0.9',
            ],
            'material_config' => [
                'floor_color' => '0x0a0a0a',
            ],
            'post_fx' => [
                'bloom'             => false,
                'vignette'          => true,
                'vignette_darkness' => 1.0,
            ],
        ]);

        $this->actingAs($user)
            ->put(route('admin.galleries.update', $gallery), $this->updatePayload([
                'visual_overrides_json' => $json,
            ]))
            ->assertRedirect();

        $gallery->refresh();
        $this->assertNull(
            $gallery->visual_overrides,
            'Overrides that only restate the venue declaration must not persist.'
        );
    }

    // ── 2. Real deviations persist, canonicalized ────────────────────────

    public function test_real_deviations_persist_in_canonical_form(): void
    {
        $user    = User::factory()->create();
        $venue   = $this->voidVenue();
        $gallery = Gallery::factory()->create([
            'user_id'           => $user->id,
            'venue_template_id' => $venue->id,
        ]);

        // The incident's real layer: a purple experiment + a slider value
        // the venue never declared. All genuine intent — all kept.
        $json = json_encode([
            'visual_config' => [
                'background_color'  => '#6D0DA0',
                'ambient_intensity' => 0.11,
            ],
            'post_fx' => [
                'bloom_strength' => 0.35,
            ],
        ]);

        $this->actingAs($user)
            ->put(route('admin.galleries.update', $gallery), $this->updatePayload([
                'visual_overrides_json' => $json,
            ]))
            ->assertRedirect();

        $gallery->refresh();
        $this->assertNotNull($gallery->visual_overrides);
        $this->assertSame(
            '0x6d0da0',
            $gallery->visual_overrides['visual_config']['background_color'],
            'Picker colours persist in the canonical 0x… lowercase form.'
        );
        $this->assertSame(0.11, (float) $gallery->visual_overrides['visual_config']['ambient_intensity']);
        $this->assertSame(0.35, (float) $gallery->visual_overrides['post_fx']['bloom_strength']);
    }

    // ── 3. Mixed save: deviations kept, no-ops stripped ──────────────────

    public function test_mixed_save_keeps_only_the_deviations(): void
    {
        $user    = User::factory()->create();
        $venue   = $this->voidVenue();
        $gallery = Gallery::factory()->create([
            'user_id'           => $user->id,
            'venue_template_id' => $venue->id,
        ]);

        $json = json_encode([
            'visual_config' => [
                'background_color'  => '0x6D0DA0', // deviation → kept
                'spot_intensity'    => 1.3,        // restates venue → dropped
            ],
        ]);

        $this->actingAs($user)
            ->put(route('admin.galleries.update', $gallery), $this->updatePayload([
                'visual_overrides_json' => $json,
            ]))
            ->assertRedirect();

        $gallery->refresh();
        $overrides = $gallery->visual_overrides;
        $this->assertArrayHasKey('background_color', $overrides['visual_config']);
        $this->assertArrayNotHasKey(
            'spot_intensity',
            $overrides['visual_config'],
            'A key restating the venue value must be stripped from the same save.'
        );
    }

    // ── 4. Venue switch normalizes against the NEW venue ─────────────────

    public function test_venue_switch_normalizes_against_the_new_venue(): void
    {
        $user    = User::factory()->create();
        $oldVenue = $this->voidVenue(['slug' => 'old-venue-' . uniqid()]);
        // The NEW venue declares a different background.
        $newVenue = $this->voidVenue([
            'slug'            => 'new-venue-' . uniqid(),
            'visual_config'   => [
                'background_color'      => '0x050510',
                'ambient_intensity'     => 0.5,
                'spot_intensity'        => 1.0,
                'tone_mapping_exposure' => 1.0,
                'post_fx' => ['bloom' => true, 'vignette' => false, 'vignette_darkness' => 0.4, 'vignette_offset' => 1.0],
            ],
        ]);
        $gallery = Gallery::factory()->create([
            'user_id'           => $user->id,
            'venue_template_id' => $oldVenue->id,
        ]);

        // Overrides restate the NEW venue (submitted in the same save that
        // switches to it) — against the stale relation they would look like
        // deviations and persist; against the new venue they are no-ops.
        $json = json_encode([
            'visual_config' => [
                'background_color'  => '0x050510',
                'ambient_intensity' => 0.5,
            ],
        ]);

        $this->actingAs($user)
            ->put(route('admin.galleries.update', $gallery), $this->updatePayload([
                'venue_template_id'     => $newVenue->id,
                'visual_overrides_json' => $json,
            ]))
            ->assertRedirect();

        $gallery->refresh();
        $this->assertSame($newVenue->id, $gallery->venue_template_id);
        $this->assertNull(
            $gallery->visual_overrides,
            'Same-save venue switches must normalize against the NEW venue declaration.'
        );
    }

    // ── 5. Reset-all still clears the column ─────────────────────────────

    public function test_reset_all_clears_stored_overrides(): void
    {
        $user    = User::factory()->create();
        $venue   = $this->voidVenue();
        $gallery = Gallery::factory()->create([
            'user_id'           => $user->id,
            'venue_template_id' => $venue->id,
            'visual_overrides'  => [
                'visual_config' => ['background_color' => '0x6d0da0'],
            ],
        ]);

        // The panel's "Reset all overrides" submits empty buckets.
        $this->actingAs($user)
            ->put(route('admin.galleries.update', $gallery), $this->updatePayload([
                'visual_overrides_json' => json_encode([
                    'visual_config'   => new \stdClass(),
                    'material_config' => new \stdClass(),
                    'post_fx'         => new \stdClass(),
                ]),
            ]))
            ->assertRedirect();

        $gallery->refresh();
        $this->assertNull($gallery->visual_overrides, 'Reset-all must clear the column so the venue row takes over.');
    }

    // ── 6. The exporter ships ONE post-fx authority ──────────────────────

    public function test_exporter_does_not_ship_the_legacy_post_fx_sibling(): void
    {
        $user    = User::factory()->create();
        $venue   = $this->voidVenue();
        $gallery = Gallery::factory()->create([
            'user_id'           => $user->id,
            'venue_template_id' => $venue->id,
            'visual_overrides'  => [
                'visual_config' => ['background_color' => '0x6d0da0'],
                'post_fx'       => ['bloom_strength' => 0.35],
            ],
        ]);

        $config = app(VenueConfigExporter::class)->forGallery($gallery);
        $this->assertNotNull($config);

        $this->assertArrayNotHasKey(
            'post_fx',
            $config,
            'The legacy sibling post_fx bucket is dead on the page-load path and must not ship.'
        );
        // The venue's declared post_fx is the only authority, merged and intact.
        $this->assertSame(false, $config['visual_config']['post_fx']['bloom']);
        $this->assertSame(1.0, (float) $config['visual_config']['post_fx']['vignette_darkness']);
        // The gallery's real visual deviation still rides on top.
        $this->assertSame('0x6d0da0', $config['visual_config']['background_color']);
    }
}
