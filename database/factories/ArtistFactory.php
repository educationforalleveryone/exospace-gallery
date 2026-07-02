<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Artist>
 */
class ArtistFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->name();

        return [
            'name'       => $name,
            'slug'       => Str::slug($name),
            'bio'        => fake()->optional()->paragraph(),
            'portrait_path' => null,
            'website'    => fake()->optional()->url(),
            'instagram'  => fake()->optional()->userName(),
            'twitter'    => fake()->optional()->userName(),
            'email'      => fake()->optional()->safeEmail(),
            'location'   => fake()->optional()->city(),
            'created_by' => User::factory(),
        ];
    }
}
