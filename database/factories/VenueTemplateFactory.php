<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VenueTemplate>
 */
class VenueTemplateFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);
        return [
            'name'          => ucfirst($name),
            'slug'          => str()->slug($name),
            'description'   => fake()->sentence(),
            'view_count'    => fake()->numberBetween(0, 1000),
            'is_active'     => true,
            // ITERATION-1 FIX: default_settings is NOT NULL in the schema
            // but the factory never set it — every VenueTemplate::factory()
            // create crashed with an integrity violation (test suite only;
            // production seeds set it explicitly).
            'default_settings' => [
                'wall_texture'    => 'white',
                'floor_material'  => 'wood',
                'frame_style'     => 'modern',
                'lighting_preset' => 'bright',
                'room_layout'     => 'square',
            ],
        ];
    }
}
