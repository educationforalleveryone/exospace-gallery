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

        $gallery->increment('view_count');

        $galleryData = [
            'id'          => $gallery->id,
            'title'       => $gallery->title,
            'description' => $gallery->description,

            // Material settings
            'wall_texture'    => $gallery->wall_texture,
            'floor_material'  => $gallery->floor_material,
            'frame_style'     => $gallery->frame_style,
            'lighting_preset' => $gallery->lighting_preset,

            // Room layout — new field, default 'square' for existing galleries
            'room_layout' => $gallery->room_layout ?? 'square',

            // Images
            'images' => $gallery->images->map(function ($img) {
                return [
                    'id'          => $img->id,
                    'url'         => asset($img->path),
                    'width'       => $img->width,
                    'height'      => $img->height,
                    'aspectRatio' => $img->width / max($img->height, 1),
                    'orientation' => $img->orientation,
                    'title'       => $img->title ?? $img->original_name,
                    'description' => $img->description,
                ];
            })->values(),

            'imageCount' => $gallery->images->count(),
            'audioUrl'   => $gallery->audio_path
                ? asset('storage/' . $gallery->audio_path)
                : null,
        ];

        return view('gallery.view', compact('gallery', 'galleryData'));
    }
}