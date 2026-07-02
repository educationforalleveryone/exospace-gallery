<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Gallery;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemController extends Controller
{
    // ── Dashboard ─────────────────────────────────────────────────────────

    public function index()
    {
        // FIX: Paginate users instead of loading all at once
        // This prevents N+1 queries and memory issues (OOM)
        $users = User::withCount('galleries')
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        // Calculate total images once and reuse
        $totalImages = DB::table('gallery_images')->count();

        $stats = [
            'total_users'     => User::count(),
            'total_galleries' => Gallery::count(),
            'free_users'      => User::where('plan', 'free')->count(),
            'pro_users'       => User::where('plan', 'pro')->count(),
            'studio_users'    => User::where('plan', 'studio')->count(),
            'total_images'    => $totalImages, // Use pre-calculated value
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

        $plan    = $request->plan;
        $oldPlan = $user->plan;
        $limits  = User::planLimits($plan);

        if ($plan === 'free' && $oldPlan !== 'free') {
            // Downgrade path — use PlanDowngradeService so Studio-only
            // resources (custom_domain, branding files) are cleaned up,
            // not just the plan column flipped (task C05).
            app(\App\Services\PlanDowngradeService::class)
                ->downgradeToFree($user, "Admin plan change ({$oldPlan} → free)");
        } else {
            // Upgrade or lateral move — no cleanup needed. Use forceFill
            // because plan / max_* / plan_* are guarded columns (task C09).
            $user->forceFill([
                'plan'            => $plan,
                'max_galleries'   => $limits['max_galleries'],
                'max_images'      => $limits['max_images'],
                'plan_started_at' => now(),
                'plan_expires_at' => $plan === 'free' ? null : now()->addYear(),
            ])->save();
        }

        AdminAuditLog::record('plan_changed', $user, ['from' => $oldPlan, 'to' => $plan]);

        return back()->with('success', "Plan updated to {$plan} for {$user->name}.");
    }

    // ── Delete user ───────────────────────────────────────────────────────

    public function deleteUser(User $user)
    {
        $this->preventSelfAction($user, 'delete');

        $userName = $user->name;
        $userEmail = $user->email;
        $userPlan = $user->plan;

        AdminAuditLog::record('user_deleted', $user, ['email' => $userEmail, 'plan' => $userPlan]);

        // Delegate to UserDeletionService — same code path as the self-serve
        // ProfileController::destroy. Handles file cleanup (images, audio,
        // logos, artist portraits), Coolify custom-domain removal, owned-team
        // cleanup, and the final user row delete. (Tasks C05 + C10.)
        app(\App\Services\UserDeletionService::class)
            ->deleteUser($user, 'Admin deletion');

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

        AdminAuditLog::record('user_banned', $user, ['reason' => $request->input('reason') ?: 'No reason provided']);

        return back()->with('success', "{$user->name} has been banned.");
    }

    public function unbanUser(User $user)
    {
        $this->preventSelfAction($user, 'unban');

        $user->forceFill([
            'banned_at'  => null,
            'ban_reason' => null,
        ])->save();

        AdminAuditLog::record('user_unbanned', $user);

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

        AdminAuditLog::record('super_admin_toggled', $user);

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
}
