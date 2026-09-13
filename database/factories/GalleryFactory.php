<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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
            'is_featured'      => false,
            'view_count'       => fake()->numberBetween(0, 500),
            'opens_at'         => null,
            'closes_at'        => null,
            'pin_hash'         => null,
            'custom_domain'    => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    public function featured(): static
    {
        // Featured state for the discover page's "featured" sort
        return $this->state(fn (array $attributes) => ['is_featured' => true]);
    }

    public function forTeam(\App\Models\Team $team): static
    {
        return $this->state(fn (array $attributes) => [
            'team_id' => $team->id,
            'user_id' => $team->owner_id,
        ]);
    }

    public function forVenue(\App\Models\VenueTemplate $venue): static
    {
        // Venue state — sets the venue_template_id FK.
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
