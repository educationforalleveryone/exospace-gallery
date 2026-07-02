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
            //
            // (Task H03) Plan-expiry semantics: webhook-granted plans are
            // lifetime (plan_expires_at = null). Admin-granted plans now
            // match that semantic — previously they expired in 1 year via
            // CheckPlanExpiry middleware, which contradicted the pricing
            // page's "lifetime access, no subscription" promise and silently
            // downgraded customers a year later.
            //
            // If you actually want expiring plans (e.g. for promotional
            // grants), set plan_expires_at explicitly via forceFill here
            // and document the expiry in the admin form.
            $user->forceFill([
                'plan'            => $plan,
                'max_galleries'   => $limits['max_galleries'],
                'max_images'      => $limits['max_images'],
                'plan_started_at' => now(),
                'plan_expires_at' => null, // Lifetime — matches webhook semantics (task H03)
            ])->save();
        }

        AdminAuditLog::record('plan_changed', $user, ['from' => $oldPlan, 'to' => $plan]);

        // Send confirmation email to the user for upgrade path (task H03).
        // Downgrade path goes through PlanDowngradeService which already
        // handles notification internally (via the downgrade log entry).
        if ($plan !== 'free' && $oldPlan !== $plan) {
            try {
                \Illuminate\Support\Facades\Mail::to($user->email)
                    ->send(new \App\Mail\PlanUpgradedEmail($user, $plan, null));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('SystemController: PlanUpgradedEmail send failed', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

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

        // (Task H07 / audit H17) — audit this action. Email verification
        // is security-relevant (verified users can do paid things). Was
        // previously silent.
        AdminAuditLog::record('email_verified', $user);

        return back()->with('success', "{$user->name}'s email manually verified.");
    }

    public function unverifyEmail(User $user)
    {
        $this->preventSelfAction($user, 'unverify email for');

        $user->forceFill(['email_verified_at' => null])->save();

        // (Task H07 / audit H17) — audit this action.
        AdminAuditLog::record('email_unverified', $user);

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
        $oldActive = $gallery->is_active;
        $gallery->update(['is_active' => ! $gallery->is_active]);

        $status = $gallery->is_active ? 'activated' : 'deactivated';

        // (Task H07 / audit H17) — audit this action. A super-admin
        // deactivating a gallery is a material action that should be
        // auditable. Was previously silent.
        AdminAuditLog::record('gallery_toggled', $gallery, [
            'from' => $oldActive,
            'to'   => $gallery->is_active,
        ]);

        return back()->with('success', "Gallery \"{$gallery->title}\" {$status}.");
    }

    // ── Pending Upgrades (Task H67) ──────────────────────────────────────

    public function pendingUpgrades()
    {
        $pendingUpgrades = \App\Models\PendingUpgrade::with('user')
            ->orderByDesc('created_at')
            ->paginate(25);

        return view('super-admin.pending-upgrades', compact('pendingUpgrades'));
    }

    public function manualUpgrade(\App\Models\PendingUpgrade $pending)
    {
        $user = $pending->user;

        if (! $user) {
            return back()->with('error', 'User not found for this pending upgrade.');
        }

        if ($pending->status !== 'pending') {
            return back()->with('error', "This pending upgrade is already {$pending->status}.");
        }

        // Manually upgrade the user (bypasses 2Checkout payment)
        $plan = $pending->plan;
        $limits = User::planLimits($plan);

        $user->forceFill([
            'plan'            => $plan,
            'max_galleries'   => $limits['max_galleries'],
            'max_images'      => $limits['max_images'],
            'plan_started_at' => now(),
            'plan_expires_at' => null, // lifetime
        ])->save();

        // Mark the pending upgrade as converted
        $pending->forceFill(['status' => 'converted'])->save();

        // Record a transaction (manual — no invoice_id from 2Checkout)
        \DB::table('transactions')->insert([
            'user_id'        => $user->id,
            'invoice_id'     => 'MANUAL-' . $pending->id . '-' . time(),
            'sale_id'        => null,
            'product_id'     => $pending->product_id,
            'plan'           => $plan,
            'amount'         => 0.00,
            'currency'       => 'USD',
            'customer_email' => $user->email,
            'customer_name'  => $user->name,
            'status'         => 'manual',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        AdminAuditLog::record('manual_upgrade', $user, [
            'plan'              => $plan,
            'pending_upgrade_id'=> $pending->id,
        ]);

        return back()->with('success', "Manually upgraded {$user->name} to {$plan}.");
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function preventSelfAction(User $user, string $action): void
    {
        if ($user->id === auth()->id()) {
            abort(403, "You cannot {$action} your own account.");
        }
    }
}
