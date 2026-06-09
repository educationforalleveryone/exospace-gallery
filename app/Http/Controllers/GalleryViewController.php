<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GalleryViewController extends Controller
{
    public function show(string $slug): View|\Illuminate\Http\RedirectResponse
    {
        $gallery = Gallery::where('slug', $slug)
            ->where('is_active', true)
            ->with('images')
            ->firstOrFail();

        // Time-gate: not open yet
        if ($gallery->hasNotOpenedYet()) {
            return view('gallery.coming-soon', compact('gallery'));
        }

        // Time-gate: exhibition has closed
        if ($gallery->hasClosed()) {
            return view('gallery.closed', compact('gallery'));
        }

        // PIN protection — redirect to PIN screen if not yet verified
        if ($gallery->hasPinProtection() && !session("pin_verified_{$gallery->id}")) {
            return redirect()->route('gallery.pin', $slug);
        }

        $gallery->increment('view_count');

        $galleryData = [
            'id'          => $gallery->id,
            'title'       => $gallery->title,
            'description' => $gallery->description,
            'wall_texture'    => $gallery->wall_texture,
            'floor_material'  => $gallery->floor_material,
            'frame_style'     => $gallery->frame_style,
            'lighting_preset' => $gallery->lighting_preset,
            'room_layout'     => $gallery->room_layout ?? 'square',
            'venue_slug'      => $gallery->venueTemplate?->slug ?? 'white-cube',
            'images' => $gallery->images->map(fn($img) => [
                'id'          => $img->id,
                'url'         => asset($img->path),
                'width'       => $img->width,
                'height'      => $img->height,
                'aspectRatio' => $img->width / max($img->height, 1),
                'orientation' => $img->orientation,
                'title'       => $img->title ?? $img->original_name,
                'description' => $img->description,
            ])->values(),
            'imageCount' => $gallery->images->count(),
            'audioUrl'   => $gallery->audio_path ? asset('storage/' . $gallery->audio_path) : null,
        ];

        return view('gallery.view', compact('gallery', 'galleryData'));
    }
}