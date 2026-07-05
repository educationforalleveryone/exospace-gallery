<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Gallery>
 *
 * TD-17 FIX: Factory now includes all required columns from the consolidated
 * galleries migration. Previously missing:
 *   - venue_template_id (added in Round 1 — galleries belong to a venue)
 *   - team_id (added in Round 4 — team galleries)
 *   - is_featured (added in Round 4 — featured exhibitions)
 *
 * SoftDeletes (deleted_at) is managed by the model via SoftDeletes trait —
 * no factory field needed; the column defaults to NULL.
 */
class GalleryFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->catchPhrase();

        return [
            'user_id'          => User::factory(),
            'team_id'          => null, // personal gallery by default; use forTeam() state for team galleries
            'venue_template_id'=> null, // null = use default venue; use forVenue() state to set
            'title'            => $title,
            'slug'             => Str::slug($title) . '-' . uniqid(),
            'description'      => fake()->optional()->paragraph(),
            'wall_texture'     => fake()->randomElement(['white', 'concrete', 'brick', 'wood']),
            'frame_style'      => fake()->randomElement(['modern', 'classic', 'minimal']),
            'lighting_preset'  => fake()->randomElement(['bright', 'warm', 'dramatic']),
            'floor_material'   => fake()->randomElement(['wood', 'marble', 'concrete']),
            'room_layout'      => fake()->randomElement(['rectangular', 'square', 'l-shaped']),
            'is_active'        => true,
            'is_featured'      => false, // TD-17: featured exhibitions (Round 4)
            'view_count'       => fake()->numberBetween(0, 500),
            'opens_at'         => null,
            'closes_at'        => null,
            'pin_hash'         => null,
            'custom_domain'    => null,
        ];
    }

    // ── States ──────────────────────────────────────────────────────────

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    public function featured(): static
    {
        // TD-17: featured state for the discover page's "featured" sort
        return $this->state(fn (array $attributes) => ['is_featured' => true]);
    }

    public function forTeam(\App\Models\Team $team): static
    {
        // TD-17: team gallery state — sets team_id + keeps user_id as the
        // gallery creator (the team owner).
        return $this->state(fn (array $attributes) => [
            'team_id' => $team->id,
            'user_id' => $team->owner_id,
        ]);
    }

    public function forVenue(\App\Models\VenueTemplate $venue): static
    {
        // TD-17: venue state — sets the venue_template_id FK.
        return $this->state(fn (array $attributes) => [
            'venue_template_id' => $venue->id,
        ]);
    }

    public function pinProtected(string $pin = '1234'): static
    {
        return $this->state(fn (array $attributes) => [
            'pin_hash' => \Illuminate\Support\Facades\Hash::make($pin),
        ]);
    }

    public function scheduled(\DateTime $opensAt = null, \DateTime $closesAt = null): static
    {
        return $this->state(fn (array $attributes) => [
            'opens_at'  => $opensAt?->format('Y-m-d H:i:s'),
            'closes_at' => $closesAt?->format('Y-m-d H:i:s'),
        ]);
    }

    public function withCustomDomain(string $domain = null): static
    {
        return $this->state(fn (array $attributes) => [
            'custom_domain'                     => $domain ?? fake()->domainName(),
            'custom_domain_verification_token'  => \Illuminate\Support\Str::random(32),
            'custom_domain_verified_at'         => now(),
        ]);
    }
}
