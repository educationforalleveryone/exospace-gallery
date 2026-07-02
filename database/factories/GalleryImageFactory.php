<?php

namespace Database\Factories;

use App\Models\Gallery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GalleryImage>
 */
class GalleryImageFactory extends Factory
{
    public function definition(): array
    {
        $width = fake()->numberBetween(800, 2400);
        $height = fake()->numberBetween(600, 2400);
        $ratio = $width / $height;

        return [
            'gallery_id'    => Gallery::factory(),
            'filename'      => fake()->uuid() . '.jpg',
            'original_name' => fake()->words(3, true) . '.jpg',
            'path'          => 'storage/galleries/1/' . fake()->uuid() . '.jpg',
            'mime_type'     => 'image/jpeg',
            'size'          => fake()->numberBetween(100000, 5000000),
            'width'         => $width,
            'height'        => $height,
            'orientation'   => $ratio > 1.1 ? 'landscape' : ($ratio < 0.9 ? 'portrait' : 'square'),
            'position_order'=> fake()->numberBetween(1, 50),
            'title'         => fake()->optional()->sentence(3),
            'description'   => fake()->optional()->paragraph(),
        ];
    }
}
