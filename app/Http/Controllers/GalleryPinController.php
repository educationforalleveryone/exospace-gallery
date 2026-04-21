<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use Illuminate\Http\Request;

class GalleryPinController extends Controller
{
    /**
     * Show the PIN entry screen.
     */
    public function show(string $slug)
    {
        $gallery = Gallery::where('slug', $slug)->where('is_active', true)->firstOrFail();

        if (!$gallery->hasPinProtection()) {
            return redirect()->route('gallery.view', $slug);
        }

        // Already verified in this session?
        if (session("pin_verified_{$gallery->id}")) {
            return redirect()->route('gallery.view', $slug);
        }

        return view('gallery.pin', compact('gallery'));
    }

    /**
     * Verify the submitted PIN.
     */
    public function verify(Request $request, string $slug)
    {
        $gallery = Gallery::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $request->validate(['pin' => 'required|digits:4']);

        if ($gallery->verifyPin($request->pin)) {
            session(["pin_verified_{$gallery->id}" => true]);
            return redirect()->route('gallery.view', $slug);
        }

        return back()->withErrors(['pin' => 'Incorrect PIN. Please try again.'])->withInput();
    }
}