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
    // ── Dashboard ─────────────────────────────────────────────────────────

    public function index()
    {
        $users = User::withCount('galleries')
            ->with(['galleries' => function ($query) {
                $query->withCount('images');
            }])
            ->orderBy('created_at', 'desc')
            ->get();

        $stats = [
            'total_users'     => User::count(),
            'total_galleries' => Gallery::count(),
            'free_users'      => User::where('plan', 'free')->count(),
            'pro_users'       => User::where('plan', 'pro')->count(),
            'studio_users'    => User::where('plan', 'studio')->count(),
            'total_images'    => DB::table('gallery_images')->count(),
            'total_views'     => Gallery::sum('view_count'),
            'banned_users'    => User::whereNotNull('banned_at')->count(),
            'unverified_users'=> User::whereNull('email_verified_at')->count(),
        ];

        return view('super-admin.index', compact('users', 'stats'));
    }

    // ── Update plan ───────────────────────────────────────────────────────

    public function updatePlan(Request $request, User $user)
    {
        $this->preventSelfAction($user, 'change the plan of');

        $request->validate(['plan' => 'required|in:free,pro,studio']);

        $limits = [
            'free'   => ['max_galleries' => 1,   'max_images' => 10],
            'pro'    => ['max_galleries' => 999,  'max_images' => 100],
            'studio' => ['max_galleries' => 999,  'max_images' => 100],
        ];

        $plan = $request->plan;

        $user->update([
            'plan'           => $plan,
            'max_galleries'  => $limits[$plan]['max_galleries'],
            'max_images'     => $limits[$plan]['max_images'],
            'plan_started_at'=> now(),
            'plan_expires_at'=> $plan === 'free' ? null : now()->addYear(),
        ]);

        return back()->with('success', "Plan updated to {$plan} for {$user->name}.");
    }

    // ── Delete user ───────────────────────────────────────────────────────

    public function deleteUser(User $user)
    {
        $this->preventSelfAction($user, 'delete');

        $userName = $user->name;

        // 1. Delete files for all personal galleries
        $user->galleries()->with('images')->get()->each(function ($gallery) {
            $this->deleteGalleryFiles($gallery);
        });

        // 2. Delete teams owned by this user and their team galleries
        foreach ($user->ownedTeams as $team) {
            $team->galleries()->with('images')->get()->each(function ($gallery) {
                $this->deleteGalleryFiles($gallery);
            });
            // Clear current_team_id for all members of this team
            User::where('current_team_id', $team->id)->update(['current_team_id' => null]);
            $team->delete(); // cascades team_user + team_invitations
        }

        // 3. Clear current_team_id if pointing at a team they don't own
        // (handled by FK set null on teams, but belt-and-suspenders)
        $user->forceFill(['current_team_id' => null])->save();

        // 4. Delete the user (DB cascade handles galleries, images, events, transactions)
        $user->delete();

        return redirect()->route('super.index')
                         ->with('success', "User \"{$userName}\" and all their data permanently deleted.");
    }

    // ── Ban / Unban ───────────────────────────────────────────────────────

    public function banUser(Request $request, User $user)
    {
        $this->preventSelfAction($user, 'ban');

        $request->validate(['reason' => 'nullable|string|max:500']);

        $user->forceFill([
            'banned_at'  => now(),
            'ban_reason' => $request->input('reason') ?: 'No reason provided.',
        ])->save();

        return back()->with('success', "{$user->name} has been banned.");
    }

    public function unbanUser(User $user)
    {
        $this->preventSelfAction($user, 'unban');

        $user->forceFill([
            'banned_at'  => null,
            'ban_reason' => null,
        ])->save();

        return back()->with('success', "{$user->name} has been unbanned.");
    }

    // ── Email verification ────────────────────────────────────────────────

    public function verifyEmail(User $user)
    {
        if ($user->hasVerifiedEmail()) {
            return back()->with('success', "{$user->name}'s email is already verified.");
        }

        $user->markEmailAsVerified();

        return back()->with('success', "{$user->name}'s email manually verified.");
    }

    public function unverifyEmail(User $user)
    {
        $this->preventSelfAction($user, 'unverify email for');

        $user->forceFill(['email_verified_at' => null])->save();

        return back()->with('success', "{$user->name}'s email verification revoked.");
    }

    // ── Toggle super admin ────────────────────────────────────────────────

    public function toggleSuperAdmin(User $user)
    {
        $this->preventSelfAction($user, 'change super admin status for');

        $user->forceFill(['is_super_admin' => ! $user->is_super_admin])->save();

        $status = $user->is_super_admin ? 'granted super admin' : 'revoked super admin';

        return back()->with('success', "Super admin access {$status} for {$user->name}.");
    }

    // ── User galleries view ───────────────────────────────────────────────

    public function userGalleries(User $user)
    {
        $galleries = $user->galleries()
            ->withCount('images')
            ->with(['images' => fn($q) => $q->orderBy('position_order')])
            ->get();

        return view('super-admin.user-galleries', compact('user', 'galleries'));
    }

    // ── Toggle gallery ────────────────────────────────────────────────────

    public function toggleGallery(Gallery $gallery)
    {
        $gallery->update(['is_active' => ! $gallery->is_active]);

        $status = $gallery->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "Gallery \"{$gallery->title}\" {$status}.");
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function preventSelfAction(User $user, string $action): void
    {
        if ($user->id === auth()->id()) {
            abort(403, "You cannot {$action} your own account.");
        }
    }

    private function deleteGalleryFiles(Gallery $gallery): void
    {
        foreach ($gallery->images as $image) {
            $this->deleteFile($image->path);
        }
        if ($gallery->audio_path)      $this->deleteFile($gallery->audio_path);
        if ($gallery->custom_logo_path) $this->deleteFile($gallery->custom_logo_path);
    }

    private function deleteFile(?string $path): void
    {
        if (empty($path)) return;

        $clean = str_replace('storage/', '', $path);

        if (Storage::disk('public')->exists($clean)) {
            Storage::disk('public')->delete($clean);
        } elseif (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}