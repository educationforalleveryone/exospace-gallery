<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * M-13: Admin impersonation service.
 *
 * Allows a super-admin to "log in as" another user to debug issues from
 * the user's perspective. The original admin's ID is stored in the session
 * so they can restore their own session when done.
 *
 * Security model:
 *   - Only super-admins can start impersonation (enforced by route middleware).
 *   - The impersonated user's session is a FULL session — they see exactly
 *     what the user sees (galleries, billing, profile, etc.).
 *   - A visible banner is shown at the top of every page while impersonating,
 *     with a "Return to admin" button. This prevents the admin from
 *     forgetting they're impersonating.
 *   - Every impersonation start/stop is recorded in AdminAuditLog (TD-19
 *     auto-captures dirty attributes).
 *   - The admin cannot impersonate other super-admins (prevents privilege
 *     escalation via impersonation chains).
 *   - The admin cannot impersonate themselves (no-op).
 *
 * Session keys:
 *   - 'impersonating_admin_id' — the original admin's user ID (set on start,
 *     cleared on stop).
 */
class ImpersonationService
{
    private const SESSION_KEY = 'impersonating_admin_id';

    /**
     * Start impersonating a user.
     *
     * @param  User  $admin   The super-admin initiating the impersonation.
     * @param  User  $target  The user to impersonate.
     * @return bool  True if impersonation started, false if not allowed.
     */
    public function start(User $admin, User $target): bool
    {
        // Cannot impersonate yourself
        if ($admin->id === $target->id) {
            return false;
        }

        // Cannot impersonate other super-admins (prevents privilege escalation)
        if ($target->is_super_admin) {
            return false;
        }

        // Cannot impersonate if already impersonating (no chains)
        if ($this->isImpersonating()) {
            return false;
        }

        // Store the admin's ID in the session
        session([self::SESSION_KEY => $admin->id]);

        // Log in as the target user
        Auth::login($target);

        // Audit log
        AdminAuditLog::record('impersonation_started', $target, [
            'admin_id'   => $admin->id,
            'admin_email'=> $admin->email,
            'target_email' => $target->email,
        ]);

        Log::info('ImpersonationService: admin started impersonating user', [
            'admin_id'  => $admin->id,
            'target_id' => $target->id,
        ]);

        return true;
    }

    /**
     * Stop impersonating and restore the original admin's session.
     *
     * @return bool  True if impersonation was stopped, false if not impersonating.
     */
    public function stop(): bool
    {
        if (! $this->isImpersonating()) {
            return false;
        }

        $adminId = session(self::SESSION_KEY);
        $admin = User::find($adminId);

        if (! $admin) {
            // Admin was deleted while impersonating — log out + clear session
            Log::warning('ImpersonationService: admin not found during stop, logging out', [
                'admin_id' => $adminId,
            ]);
            Auth::logout();
            session()->forget(self::SESSION_KEY);
            return true;
        }

        $impersonatedUser = Auth::user();

        // Restore the admin's session
        Auth::login($admin);
        session()->forget(self::SESSION_KEY);

        // Audit log
        if ($impersonatedUser) {
            AdminAuditLog::record('impersonation_stopped', $impersonatedUser, [
                'admin_id'   => $admin->id,
                'admin_email'=> $admin->email,
                'target_email' => $impersonatedUser->email,
            ]);
        }

        Log::info('ImpersonationService: admin stopped impersonating user', [
            'admin_id'  => $admin->id,
            'target_id' => $impersonatedUser?->id,
        ]);

        return true;
    }

    /**
     * Is the current session impersonating another user?
     */
    public function isImpersonating(): bool
    {
        return session()->has(self::SESSION_KEY);
    }

    /**
     * Get the original admin user (if currently impersonating).
     */
    public function getImpersonatingAdmin(): ?User
    {
        $adminId = session(self::SESSION_KEY);
        if (! $adminId) {
            return null;
        }

        return User::find($adminId);
    }
}
