<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GalleryViewController extends Controller
{
    public function show(string $slug): View
    {
        $gallery = Gallery::where('slug', $slug)
            ->where('is_active', true)
            ->with(['images' => function ($query) {
                $query->orderBy('position_order');
            }])
            ->firstOrFail();

        // Increment view count
        $gallery->increment('view_count');

        // Prepare optimized JSON payload for Three.js
        $galleryData = [
            'id' => $gallery->id,
            'title' => $gallery->title,
            'description' => $gallery->description,
            
            // Material Settings
            'wall_texture' => $gallery->wall_texture,
            'floor_material' => $gallery->floor_material,
            'frame_style' => $gallery->frame_style,
            'lighting_preset' => $gallery->lighting_preset,
            
            // Images with full URLs
            'images' => $gallery->images->map(function ($img) {
                return [
                    'id' => $img->id,
                    'url' => asset($img->path),
                    'width' => $img->width,
                    'height' => $img->height,
                    'aspectRatio' => $img->width / max($img->height, 1),
                    'orientation' => $img->orientation,
                    'title' => $img->title ?? $img->original_name,
                    'description' => $img->description,
                ];
            })->values(),
            
            // Metadata
            'imageCount' => $gallery->images->count(),
        ];

        return view('gallery.view', compact('gallery', 'galleryData'));
    }
}