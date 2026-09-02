<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Iteration 0 "Honesty" (roadmap P0.1): align venue descriptions with what
 * the 3D renderer actually delivers.
 *
 * WHY
 * ---
 * Six venues' descriptions promised effects the renderer does not ship:
 *   - infinite-void:      "Floating artworks"  → artworks stand on easels
 *   - crystal-cathedral:  "art hangs in a prism of colour" + "refracted
 *                          light" → octahedra + coloured lights only
 *   - mirror-lake:        "mirror floor reflects artworks floating above"
 *                         → glossy dark floor (no planar reflection), easels
 *   - zen-gallery:        "partial dividers" → no divider geometry exists
 *   - luxury-penthouse:   "Private gallery atmosphere" implied residential
 *                         program → dark walls + marble + gold only
 *   - cyber-gallery:      "Futuristic neon exhibition space" overstated two
 *                         emissive strips
 *
 * These are the copy-side of the promise/delivery gap; the render side is
 * scheduled for Iterations 2–3 (Phenomena / Rooms), after which the copy is
 * re-tightened per the roadmap.
 *
 * SAFETY (production data protection)
 * -----------------------------------
 * Each update is GUARDED: it only applies when the row's description still
 * matches the original seeded copy. If a super-admin has already customized
 * a venue's description, this migration leaves their text untouched.
 *
 * Idempotent: re-running is a no-op (old text no longer matches).
 * The seeder (VenueTemplateSeeder) is updated in the same iteration so
 * FRESH installations get the honest copy by default.
 *
 * Rollback: restores the original seeded descriptions (same guard logic).
 */
return new class extends Migration
{
    /**
     * slug => [old (original seeded) description, new (honest) description].
     */
    public function up(): void
    {
        foreach ($this->changes() as $slug => [$old, $new]) {
            DB::table('venue_templates')
                ->where('slug', $slug)
                ->where('description', $old)
                ->update(['description' => $new]);
        }
    }

    public function down(): void
    {
        foreach ($this->changes() as $slug => [$old, $new]) {
            DB::table('venue_templates')
                ->where('slug', $slug)
                ->where('description', $new)
                ->update(['description' => $old]);
        }
    }

    private function changes(): array
    {
        return [
            'infinite-void' => [
                'Floating artworks in an endless environment. No limits, no walls, no ceiling.',
                'A vast dark space with slowly drifting dust. Artworks presented in the round on easels — no walls, no ceiling.',
            ],
            'crystal-cathedral' => [
                'Floating glass shards catch refracted light. An ethereal space where art hangs in a prism of colour.',
                'Crystalline forms drift through a deep blue void, lit by shifting colour. An ethereal, open exhibition space.',
            ],
            'mirror-lake' => [
                'A perfectly still mirror floor reflects artworks floating above. Moonlit, misty, meditative.',
                'A still, dark lake floor beneath soft mist and moonlight. Quiet, spacious, meditative.',
            ],
            'zen-gallery' => [
                'Minimal architecture with natural materials. Calm and focused atmosphere with partial dividers.',
                'Minimal architecture with natural wood finishes and calm, warm light. A quiet, focused atmosphere.',
            ],
            'luxury-penthouse' => [
                'High-end collector experience. Private gallery atmosphere with marble floors and gold accents.',
                'A moody, intimate collector space. Dark walls, marble floors, gold accents.',
            ],
            'cyber-gallery' => [
                'Futuristic neon exhibition space. For digital and web3 creators.',
                'A dark futuristic exhibition space with neon light accents. For digital and web3 creators.',
            ],
        ];
    }
};
