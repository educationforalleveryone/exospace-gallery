<?php

namespace Database\Seeders;

use App\Models\VenueTemplate;
use Illuminate\Database\Seeder;

class VenueTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name'          => 'Modern White Cube',
                'slug'          => 'white-cube',
                'description'   => 'Minimal contemporary exhibition space. The professional standard.',
                'plan_required' => 'free',
                'capacity_min'  => 20,
                'capacity_max'  => 60,
                'sort_order'    => 1,
                'default_settings' => [
                    'wall_texture'    => 'white',
                    'floor_material'  => 'concrete',
                    'lighting_preset' => 'bright',
                    'frame_style'     => 'minimal',
                    'room_layout'     => 'square',
                ],
            ],
            [
                'name'          => 'Industrial Loft',
                'slug'          => 'industrial-loft',
                'description'   => 'Concrete, steel and large open spaces. Urban contemporary feel.',
                'plan_required' => 'pro',
                'capacity_min'  => 30,
                'capacity_max'  => 80,
                'sort_order'    => 2,
                'default_settings' => [
                    'wall_texture'    => 'concrete',
                    'floor_material'  => 'concrete',
                    'lighting_preset' => 'dramatic',
                    'frame_style'     => 'modern',
                    'room_layout'     => 'corridor',
                ],
            ],
            [
                'name'          => 'Dark Museum',
                'slug'          => 'dark-museum',
                'description'   => 'Dramatic lighting with black walls. Premium artwork presentation.',
                'plan_required' => 'pro',
                'capacity_min'  => 15,
                'capacity_max'  => 50,
                'sort_order'    => 3,
                'default_settings' => [
                    'wall_texture'    => 'brick',
                    'floor_material'  => 'marble',
                    'lighting_preset' => 'moody',
                    'frame_style'     => 'classic',
                    'room_layout'     => 'square',
                ],
            ],
            [
                'name'          => 'Japanese Zen Gallery',
                'slug'          => 'zen-gallery',
                'description'   => 'Minimal architecture with natural materials. Calm and focused.',
                'plan_required' => 'pro',
                'capacity_min'  => 10,
                'capacity_max'  => 40,
                'sort_order'    => 4,
                'default_settings' => [
                    'wall_texture'    => 'wood',
                    'floor_material'  => 'wood',
                    'lighting_preset' => 'bright',
                    'frame_style'     => 'minimal',
                    'room_layout'     => 'rotunda',
                ],
            ],
            [
                'name'          => 'Luxury Penthouse',
                'slug'          => 'luxury-penthouse',
                'description'   => 'High-end collector experience. Private gallery atmosphere.',
                'plan_required' => 'studio',
                'capacity_min'  => 10,
                'capacity_max'  => 40,
                'sort_order'    => 5,
                'default_settings' => [
                    'wall_texture'    => 'white',
                    'floor_material'  => 'marble',
                    'lighting_preset' => 'moody',
                    'frame_style'     => 'classic',
                    'room_layout'     => 'l-shape',
                ],
            ],
            [
                'name'          => 'Cyber Gallery',
                'slug'          => 'cyber-gallery',
                'description'   => 'Futuristic neon exhibition space. For digital and web3 creators.',
                'plan_required' => 'studio',
                'capacity_min'  => 20,
                'capacity_max'  => 100,
                'sort_order'    => 6,
                'default_settings' => [
                    'wall_texture'    => 'concrete',
                    'floor_material'  => 'concrete',
                    'lighting_preset' => 'dramatic',
                    'frame_style'     => 'modern',
                    'room_layout'     => 'corridor',
                ],
            ],
            [
                'name'          => 'Outdoor Sculpture Garden',
                'slug'          => 'sculpture-garden',
                'description'   => 'Open-air exhibition environment. For large installations.',
                'plan_required' => 'studio',
                'capacity_min'  => 10,
                'capacity_max'  => 100,
                'sort_order'    => 7,
                'default_settings' => [
                    'wall_texture'    => 'white',
                    'floor_material'  => 'wood',
                    'lighting_preset' => 'bright',
                    'frame_style'     => 'minimal',
                    'room_layout'     => 'l-shape',
                ],
            ],
            [
                'name'          => 'Infinite Void',
                'slug'          => 'infinite-void',
                'description'   => 'Floating artworks in an endless environment. No limits.',
                'plan_required' => 'studio',
                'capacity_min'  => 1,
                'capacity_max'  => null,
                'sort_order'    => 8,
                'default_settings' => [
                    'wall_texture'    => 'brick',
                    'floor_material'  => 'marble',
                    'lighting_preset' => 'dramatic',
                    'frame_style'     => 'minimal',
                    'room_layout'     => 'rotunda',
                ],
            ],
        ];

        foreach ($templates as $data) {
            VenueTemplate::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}