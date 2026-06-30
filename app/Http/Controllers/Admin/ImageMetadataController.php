<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesGalleryAccess;
use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Per-artwork metadata editor.
 *
 * Each image in a gallery has a metadata panel (title, description, artist,
 * price, medium, year, dimensions, edition, for_sale flag, external_url).
 * This controller handles AJAX updates from the edit-gallery page.
 *
 * The image upload itself is handled by ImageController. This controller
 * handles editing EXISTING images.
 */
class ImageMetadataController extends Controller
{
    use AuthorizesGalleryAccess;

    /**
     * Update metadata for a single image.
     * AJAX endpoint called from the edit-gallery page.
     */
    public function update(Request $request, Gallery $gallery, GalleryImage $image)
    {
        $this->authorizeGalleryAccess($gallery);

        if ($image->gallery_id !== $gallery->id) {
            abort(404);
        }

        $validated = $request->validate([
            'title'           => ['nullable', 'string', 'max:255'],
            'description'     => ['nullable', 'string', 'max:1000'],
            'artist_id'       => ['nullable', 'integer', 'exists:artists,id'],
            'price'           => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'currency'        => ['nullable', 'string', 'size:3'],
            'for_sale'        => ['boolean'],
            'medium'          => ['nullable', 'string', 'max:255'],
            'year'            => ['nullable', 'integer', 'min:1000', 'max:' . (date('Y') + 1)],
            'dimensions'      => ['nullable', 'string', 'max:100'],
            'edition_size'    => ['nullable', 'integer', 'min:1'],
            'edition_number'  => ['nullable', 'string', 'max:50'],
            'external_url'    => ['nullable', 'string', 'max:500', 'url'],
        ]);

        // Boolean normalization
        $validated['for_sale'] = $request->boolean('for_sale');

        // If artist_id is empty string, normalize to null
        if (isset($validated['artist_id']) && empty($validated['artist_id'])) {
            $validated['artist_id'] = null;
        }

        $image->update($validated);
        $image->refresh();
        $image->load('artist');

        return response()->json([
            'success'           => true,
            'message'           => 'Artwork details saved.',
            'image'             => $image->toArray(),
            'formatted_price'   => $image->formattedPrice(),
            'formatted_edition' => $image->formattedEdition(),
            'artist_name'       => $image->artist?->name,
            'artist_slug'       => $image->artist?->slug,
        ]);
    }
}
