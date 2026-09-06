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
 * VENUE-OWNED ATMOSPHERE (post-deploy hotfix, 2026-09-05): the Live-Preview
 * BACKGROUND control is retired entirely — venue bodies (floor_edge_fade,
 * fog ramp, void dome) derive from background_color, so overriding it
 * recomposes the venue (the purple-belt incident) instead of tuning it.
 * The controller strips the key UNCONDITIONALLY on save, and the exporter
 * ignores it both for new writes and for LEGACY rows already carrying it —
 * which heals already-broken galleries on deploy, no manual reset needed.
 *
 * VENUE-OWNED ATMOSPHERE/ARCHITECTURE/RIG (Dark Museum deployed-screenshot
 * incident, 2026-09-06 — schema s2): the single-key guard proved too
 * narrow. A legacy override layer carrying violet fog + the pre-polish dim
 * rig + undeclared void keys (open_air, floor_reflection) recomposed the
 * v2 Dark Museum into a purple void WITH museum furniture. The authority
 * set is now the full composed-venue list (VenueConfigExporter::
 * VENUE_OWNED_VISUAL_KEYS + void_* prefix rule + texture_tint in
 * materials), enforced at every layer, plus a wholesale override reset on
 * venue switch (overrides saved under one venue are meaningless under
 * another). These tests pin the expanded contract.
 *
 * VENUE-OWNED MATERIAL IDENTITY + PRESENTATION (post-hotfix residual
 * incident, 2026-09-06 — schema s3): the user's second deployed screenshot
 * proved the stale layer also rode through the two buckets s2 did not
 * guard. material_config carried only texture_tint as owned, so a
 * white-cube-era floor layer (light colour + low roughness + metal)
 * recomposed the museum's dark stone into a bright polished plane; and
 * visual_config.post_fx — a NESTED object key — merges WHOLESALE, so a
 * stale {bloom:true} re-armed bloom and fell the blend back to the stock
 * grey glow. s3 owns the full declared material set + post_fx + placement
 * (curation), and drops the legacy sibling post_fx bucket on save. These
 * tests pin the s3 contract.
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

    // ── 2. Real deviations persist, canonicalized — EXCEPT venue-owned keys ─

    public function test_real_deviations_persist_in_canonical_form(): void
    {
        $user    = User::factory()->create();
        $venue   = $this->voidVenue();
        $gallery = Gallery::factory()->create([
            'user_id'           => $user->id,
            'venue_template_id' => $venue->id,
        ]);

        // The incident's real layer: a purple experiment + a slider value
        // the venue never declared. The purple is VENUE-OWNED (structural
        // atmosphere), and so is every rig key (s2). The legacy sibling
        // post_fx bucket is VENUE-OWNED PRESENTATION (s3) — dropped on save.
        // A genuine non-owned deviation (frame choice) is kept, canonicalized.
        $json = json_encode([
            'visual_config' => [
                'background_color'  => '#6D0DA0',
                'ambient_intensity' => 0.11,
                'frame_override'    => 'black',
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
        $this->assertArrayNotHasKey(
            'background_color',
            $gallery->visual_overrides['visual_config'] ?? [],
            'background_color is venue-owned atmosphere — a save can never persist it.'
        );
        $this->assertArrayNotHasKey(
            'ambient_intensity',
            $gallery->visual_overrides['visual_config'] ?? [],
            'The lighting rig is venue-owned (s2) — a save can never persist it.'
        );
        $this->assertArrayNotHasKey(
            'post_fx',
            $gallery->visual_overrides,
            'The legacy sibling post_fx bucket is venue-owned presentation (s3) — a save can never persist it.'
        );
        $this->assertSame(
            'black',
            $gallery->visual_overrides['visual_config']['frame_override'],
            'A genuine non-owned deviation (frame choice) still persists.'
        );
    }

    // ── 3. Mixed save: deviations kept, no-ops AND venue-owned keys stripped ─

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
                'background_color'  => '0x6D0DA0', // venue-owned → stripped
                'spot_intensity'    => 1.3,        // restates venue → dropped
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
            'A save carrying only venue-owned + no-op keys must clear the column.'
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
            'Same-save venue switches must normalize against the NEW venue declaration (and background_color is venue-owned regardless).'
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
    }

    // ── 7. VENUE-OWNED ATMOSPHERE: legacy background overrides are inert ──

    public function test_exporter_ignores_legacy_saved_background_overrides(): void
    {
        $user    = User::factory()->create();
        $venue   = $this->voidVenue();
        $gallery = Gallery::factory()->create([
            'user_id'           => $user->id,
            'venue_template_id' => $venue->id,
            // The incident row: purple saved by an old panel build.
            'visual_overrides'  => [
                'visual_config' => [
                    'background_color'  => '0x6d0da0',
                    'ambient_intensity' => 0.11,
                ],
            ],
        ]);

        $config = app(VenueConfigExporter::class)->forGallery($gallery);

        $this->assertSame(
            '0x000000',
            $config['visual_config']['background_color'],
            'The venue-declared background must win over ANY saved override — this is what heals already-broken galleries on deploy.'
        );
        // The rig is venue-owned too (s2): the legacy dim value is inert and
        // the venue's declaration ships instead.
        $this->assertSame(
            0.3,
            $config['visual_config']['ambient_intensity'],
            'The venue-declared rig must win over ANY saved override — a stale pre-polish dim rig must not defeat venue remediation.'
        );
    }

    // ── 7b. THE DARK MUSEUM DEPLOYED INCIDENT (s2): the whole stale layer
    //        — violet fog, dim rig, undeclared void keys — is inert at once.

    public function test_exporter_ignores_the_dark_museum_incident_override_row(): void
    {
        $user  = User::factory()->create();
        $venue = VenueTemplate::factory()->create([
            'name'            => 'Dark Museum',
            'slug'            => 'dark-museum-' . uniqid(),
            'visual_config'   => [
                'background_color'      => '0x050505',
                'fog_color'             => '0x050505',
                'fog_near'              => 12,
                'fog_far'               => 70,
                'wall_height'           => 5,
                'ambient_color'         => '0xffe8c8',
                'ambient_intensity'     => 3.2,
                'spot_intensity'        => 1.9,
                'fill_intensity'        => 0.5,
                'tone_mapping_exposure' => 0.8,
                'hemisphere_intensity'  => 0.04,
                'structure_pass'        => 'museum',
                'artwork_light_base'    => 0.32,
            ],
            'material_config' => [
                'texture_tint'    => true,
                'wall_color'      => '0x7a746c',
                'floor_color'     => '0x3a3835',
                'floor_roughness' => 0.3,
            ],
            'is_draft'  => false,
            'is_active' => true,
        ]);
        // The exact incident layer reconstructed from the deployed screenshot
        // + the documented incident class (void-era keys the old normalizer
        // kept because the venue did not declare them).
        $gallery = Gallery::factory()->create([
            'user_id'           => $user->id,
            'venue_template_id' => $venue->id,
            'visual_overrides'  => [
                'visual_config' => [
                    'fog_color'             => '0x6d0da0',
                    'fog_near'              => 12,
                    'fog_far'               => 70,
                    'open_air'              => true,
                    'floor_reflection'      => 'planar',
                    'placement_mode'        => 'float',
                    'structure_pass'        => 'phenomena',
                    'void_starfield'        => true,
                    'ambient_color'         => '0x8844ff',
                    'ambient_intensity'     => 0.18,
                    'spot_intensity'        => 0.5,
                    'fill_intensity'        => 0.15,
                    'hemisphere_intensity'  => 0.15,
                    'tone_mapping_exposure' => 0.55,
                    'artwork_light_base'    => 0.1,
                ],
                'material_config' => [
                    'texture_tint'    => false,
                    'floor_roughness' => 0.18,
                ],
            ],
        ]);

        $config = app(VenueConfigExporter::class)->forGallery($gallery);
        $vc = $config['visual_config'];

        $this->assertSame('0x050505', $vc['fog_color'], 'Venue fog authority — the violet belt vector.');
        $this->assertSame(3.2, (float) $vc['ambient_intensity'], 'Venue rig authority.');
        $this->assertSame(0.8, (float) $vc['tone_mapping_exposure'], 'Venue exposure authority.');
        $this->assertSame('museum', $vc['structure_pass'], 'Venue structure authority.');
        $this->assertSame(0.32, (float) $vc['artwork_light_base'], 'Venue legibility floor authority.');
        $this->assertArrayNotHasKey('open_air', $vc, 'An undeclared void key can never strip the room shell.');
        $this->assertArrayNotHasKey('floor_reflection', $vc, 'An undeclared void key can never mirror the museum floor.');
        $this->assertArrayNotHasKey('void_starfield', $vc, 'A venue that never declared a void effect can never grow one from an override.');
        $this->assertTrue($config['material_config']['texture_tint'], 'texture_tint is venue plumbing — a saved false cannot re-break tinted walls.');
        // The material identity is venue-owned in full (s3): the legacy
        // floor polish tweak is inert and the venue's declared stone ships.
        $this->assertSame(0.3, (float) $config['material_config']['floor_roughness'], 'floor_roughness is venue-owned material identity (s3).');
    }

    // ── 7d. THE POST-HOTFIX RESIDUAL INCIDENT (s3): the two buckets s2 did
    //        not guard — a white-cube-era floor layer + a stale post_fx
    //        object — are inert at once.

    public function test_exporter_ignores_the_residual_floor_and_post_fx_layers(): void
    {
        $user  = User::factory()->create();
        $venue = VenueTemplate::factory()->create([
            'name'            => 'Dark Museum',
            'slug'            => 'dark-museum-' . uniqid(),
            'visual_config'   => [
                'background_color' => '0x050505',
                'fog_color'        => '0x050505',
                'ambient_intensity' => 3.2,
                'tone_mapping_exposure' => 0.8,
                'placement' => ['density' => 'generous', 'focal_wall' => 'front', 'pair_orientation' => true],
                'post_fx'   => ['bloom' => false, 'vignette' => true, 'vignette_blend' => 'black', 'vignette_darkness' => 0.5, 'vignette_offset' => 1.15],
            ],
            'material_config' => [
                'texture_tint'    => true,
                'wall_color'      => '0x7a746c',
                'floor_color'     => '0x3a3835',
                'floor_roughness' => 0.3,
                'floor_metalness' => 0.15,
                'floor_tile_meters' => 3.0,
            ],
            'is_draft'  => false,
            'is_active' => true,
        ]);
        // The residual layer the second deployed screenshot proved out: the
        // s2 heal worked (fog/walls/rig) but the stale white-cube-era floor
        // numbers + the pre-restraint bloom object rode through.
        $gallery = Gallery::factory()->create([
            'user_id'           => $user->id,
            'venue_template_id' => $venue->id,
            'visual_overrides'  => [
                'visual_config' => [
                    'placement' => ['density' => 'packed'],
                    'post_fx'   => ['bloom' => true, 'vignette_blend' => 'grey'],
                ],
                'material_config' => [
                    'floor_color'     => '0x9c9c98',
                    'floor_roughness' => 0.12,
                    'floor_metalness' => 0.35,
                ],
            ],
        ]);

        $config = app(VenueConfigExporter::class)->forGallery($gallery);

        // The venue's dark stone ships — not the bright polished layer.
        $this->assertSame('0x3a3835', $config['material_config']['floor_color'], 'Floor colour is venue-owned material identity (s3).');
        $this->assertSame(0.3, (float) $config['material_config']['floor_roughness'], 'The museum polished-stone read is a venue declaration, not a per-gallery knob.');
        $this->assertSame(0.15, (float) $config['material_config']['floor_metalness'], 'Floor metalness is venue-owned (s3) — a stale layer cannot chrome-plate the stone.');
        $this->assertSame(3.0, (float) $config['material_config']['floor_tile_meters']);
        // The venue's restraint ships — bloom stays OFF, blend stays black.
        $this->assertSame(false, $config['visual_config']['post_fx']['bloom'], 'post_fx is venue-owned presentation (s3) — a stale layer cannot re-arm bloom.');
        $this->assertSame('black', $config['visual_config']['post_fx']['vignette_blend'], 'The venue black-blend vignette is the declared authority.');
        // The venue's hang ships — no packed re-cram from a stale layer.
        $this->assertSame('generous', $config['visual_config']['placement']['density'], 'placement is venue-owned curation (s3).');
    }

    // ── 7c. Venue switch discards submitted overrides wholesale ──────────

    public function test_venue_switch_discards_submitted_overrides_wholesale(): void
    {
        $user     = User::factory()->create();
        $oldVenue = $this->voidVenue(['slug' => 'old-venue-' . uniqid()]);
        $newVenue = $this->voidVenue(['slug' => 'new-venue-' . uniqid()]);
        $gallery  = Gallery::factory()->create([
            'user_id'           => $user->id,
            'venue_template_id' => $oldVenue->id,
            'visual_overrides'  => [
                'visual_config' => ['tone_mapping_exposure' => 0.55],
            ],
        ]);

        // The panel blob saved under the OLD venue rides along in the same
        // save that switches venues — it must not recompose the new venue.
        $json = json_encode([
            'visual_config' => ['fog_color' => '0x6d0da0'],
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
            'A venue switch must start the gallery FRESH on the new venue — neither the stored layer nor the same-save blob may cross over.'
        );
    }

    // ── 8. VENUE-OWNED ATMOSPHERE: preview runtime overrides cannot repaint it ─

    public function test_preview_runtime_overrides_cannot_set_background(): void
    {
        $user    = User::factory()->create();
        $venue   = $this->voidVenue();
        $gallery = Gallery::factory()->create([
            'user_id'           => $user->id,
            'venue_template_id' => $venue->id,
        ]);

        $config = app(VenueConfigExporter::class)->forGalleryPreview($gallery, [
            'visual_config' => [
                'background_color' => '0x6d0da0',
                'fog_color'        => '0x6d0da0',
                'fog_far'          => 42,
                'open_air'         => true,
            ],
            // s3: the runtime MATERIAL patch gets the same guard.
            'material_config' => [
                'floor_color'     => '0x9c9c98',
                'floor_roughness' => 0.12,
                'floor_metalness' => 0.35,
            ],
        ]);

        $this->assertSame(
            '0x000000',
            $config['visual_config']['background_color'],
            'A hand-crafted ?override= payload must not repaint the venue background.'
        );
        $this->assertArrayNotHasKey('fog_color', $config['visual_config'] ?? [], 'The preview payload cannot carry a venue-owned fog repaint.');
        $this->assertArrayNotHasKey('fog_far', $config['visual_config'] ?? [], 'The preview payload cannot carry a venue-owned fog ramp change (the venue never declared one).');
        $this->assertArrayNotHasKey('open_air', $config['visual_config'] ?? [], 'The preview payload cannot carry the void shell-strip flag.');
        $this->assertSame(
            '0x0a0a0a',
            $config['material_config']['floor_color'],
            'A hand-crafted ?override= payload must not chrome-plate the venue floor (s3 material authority).'
        );
        $this->assertSame(
            0.32,
            (float) $config['material_config']['floor_roughness'],
            'Runtime material patches cannot defeat the venue-declared floor PBR (s3).'
        );
    }
}
