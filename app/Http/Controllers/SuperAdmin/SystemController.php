<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Gallery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class SystemController extends Controller
{
    /**
     * Display the Super Admin Dashboard
     */
    public function index()
    {
        // Get all users with their gallery counts
        $users = User::withCount('galleries')
            ->with(['galleries' => function ($query) {
                $query->withCount('images');
            }])
            ->orderBy('created_at', 'desc')
            ->get();

        // Calculate platform statistics
        $stats = [
            'total_users' => User::count(),
            'total_galleries' => Gallery::count(),
            'free_users' => User::where('plan', 'free')->count(),
            'pro_users' => User::where('plan', 'pro')->count(),
            'studio_users' => User::where('plan', 'studio')->count(),
            'total_images' => DB::table('gallery_images')->count(),
            'total_views' => Gallery::sum('view_count'),
        ];

        return view('super-admin.index', compact('users', 'stats'));
    }

    /**
     * Update a user's plan and limits
     */
    public function updatePlan(Request $request, User $user)
    {
        $request->validate([
            'plan' => 'required|in:free,pro,studio',
        ]);

        $plan = $request->plan;

        // Set plan limits based on tier
        $limits = [
            'free' => ['max_galleries' => 1, 'max_images' => 10],
            'pro' => ['max_galleries' => 999, 'max_images' => 100],
            'studio' => ['max_galleries' => 999, 'max_images' => 100],
        ];

        $user->update([
            'plan' => $plan,
            'max_galleries' => $limits[$plan]['max_galleries'],
            'max_images' => $limits[$plan]['max_images'],
            'plan_started_at' => now(),
            'plan_expires_at' => $plan === 'free' ? null : now()->addYear(),
        ]);

        return redirect()
            ->route('super.index')
            ->with('success', "User {$user->name} upgraded to {$plan} plan!");
    }

    /**
     * Delete a user and all their data
     */
    public function deleteUser(User $user)
    {
        // Prevent deleting yourself
        if ($user->id === auth()->id()) {
            return redirect()
                ->route('super.index')
                ->with('error', 'You cannot delete your own account!');
        }

        // Get all galleries for file cleanup
        $galleries = $user->galleries()->with('images')->get();

        foreach ($galleries as $gallery) {
            // Delete all gallery images from storage
            foreach ($gallery->images as $image) {
                $this->deleteFileFromStorage($image->path);
            }

            // Delete audio file if exists
            if ($gallery->audio_path) {
                $this->deleteFileFromStorage($gallery->audio_path);
            }

            // Delete custom logo if exists
            if ($gallery->custom_logo_path) {
                $this->deleteFileFromStorage($gallery->custom_logo_path);
            }
        }

        // Delete the user (cascade will handle database records)
        $userName = $user->name;
        $user->delete();

        return redirect()
            ->route('super.index')
            ->with('success', "User {$userName} and all their data deleted successfully!");
    }

    /**
     * View all galleries from a specific user
     */
    public function userGalleries(User $user)
    {
        $galleries = $user->galleries()
            ->withCount('images')
            ->with(['images' => function ($query) {
                $query->orderBy('position_order');
            }])
            ->get();

        return view('super-admin.user-galleries', compact('user', 'galleries'));
    }

    /**
     * Toggle gallery active status
     */
    public function toggleGallery(Gallery $gallery)
    {
        $gallery->update([
            'is_active' => !$gallery->is_active
        ]);

        return redirect()
            ->back()
            ->with('success', "Gallery {$gallery->title} " . ($gallery->is_active ? 'activated' : 'deactivated'));
    }

    /**
     * Helper method to properly delete files from public storage disk
     * 
     * Handles paths that may start with 'storage/' prefix from database
     * and ensures deletion happens on the correct disk
     */
    private function deleteFileFromStorage(?string $path): void
    {
        if (empty($path)) {
            return;
        }

        // Strip 'storage/' prefix if it exists (since we're using public disk)
        $cleanPath = str_replace('storage/', '', $path);
        
        // Try to delete from public disk
        if (Storage::disk('public')->exists($cleanPath)) {
            Storage::disk('public')->delete($cleanPath);
        }
        
        // Also try the original path in case it's stored differently
        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}