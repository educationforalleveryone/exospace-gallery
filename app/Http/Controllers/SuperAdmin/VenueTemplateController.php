<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\VenueTemplate;
use Illuminate\Http\Request;

class VenueTemplateController extends Controller
{
    public function index()
    {
        $venues = VenueTemplate::orderBy('sort_order')->withCount('galleries')->get();
        return view('super-admin.venues.index', compact('venues'));
    }

    public function edit(VenueTemplate $venue)
    {
        return view('super-admin.venues.edit', compact('venue'));
    }

    public function update(Request $request, VenueTemplate $venue)
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:100',
            'description'   => 'required|string|max:500',
            'plan_required' => 'required|in:free,pro,studio',
            'capacity_min'  => 'required|integer|min:1',
            'capacity_max'  => 'nullable|integer|min:1',
            'sort_order'    => 'required|integer|min:0',
            'is_active'     => 'boolean',
        ]);

        $venue->update($validated);

        return redirect()->route('super.venues.index')->with('status', "Venue \"{$venue->name}\" updated.");
    }

    public function toggle(VenueTemplate $venue)
    {
        $venue->update(['is_active' => !$venue->is_active]);
        $state = $venue->is_active ? 'enabled' : 'disabled';
        return back()->with('status', "Venue \"{$venue->name}\" {$state}.");
    }
}