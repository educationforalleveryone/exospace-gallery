<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
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
