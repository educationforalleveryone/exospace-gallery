<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ImpersonationService
{
    private const SESSION_KEY = 'impersonating_admin_id';

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

        session()->regenerate();

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

        session()->regenerate();

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

    public function isImpersonating(): bool
    {
        return session()->has(self::SESSION_KEY);
    }

    public function getImpersonatingAdmin(): ?User
    {
        $adminId = session(self::SESSION_KEY);
        if (! $adminId) {
            return null;
        }

        return User::find($adminId);
    }
}
