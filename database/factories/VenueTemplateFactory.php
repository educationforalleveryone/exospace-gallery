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
        ];
    }
}
