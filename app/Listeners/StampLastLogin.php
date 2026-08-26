<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ITERATION 6 — stamp users.last_login_at on every real login.
 *
 * Why this exists: the platform had no login-activity record, so the
 * weekly cohort retention report defined "active" partly as
 * "users.updated_at >= period start" — unbounded (future activity
 * counted in past periods) and noisy (plan changes, marketing prefs,
 * admin writes all bump users.updated_at). Retention was inflated and
 * semantically incoherent. This listener gives the product a truthful
 * engagement signal with ONE hook instead of edits in every auth path.
 *
 * Coverage — the Login event fires from SessionGuard on:
 *   - Auth::attempt()      → password login (LoginRequest::authenticate)
 *   - Auth::login()        → OAuth login, post-registration auto-login,
 *                            admin impersonation entry (see note below)
 *
 * It deliberately does NOT fire on remember-cookie session restoration
 * (userFromRecaller), so a stale browser tab does not count as a login.
 *
 * Impersonation note: ImpersonationService logs the admin in AS the user
 * via Auth::login — that stamps the user's last_login_at. A super-admin
 * impersonation is rare and always audit-logged separately
 * (impersonation audit rows carry the real actor), so the occasional
 * stamp is an acceptable, documented imprecision — far smaller than the
 * noise it replaces.
 *
 * Write shape: query-builder UPDATE (no model events, no observer fan-out
 * — the sitemap observer listens to Gallery saves, not User, but keeping
 * this to a single indexed UPDATE avoids touching anything else during
 * the login hot path). Never throws into the login flow: failures are
 * logged and swallowed.
 */
class StampLastLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || $user->id === null) {
            return;
        }

        try {
            DB::table('users')
                ->where('id', $user->id)
                ->update(['last_login_at' => now()]);
        } catch (\Throwable $e) {
            // Pre-Iteration-6 schema (rolling deploy before the migration
            // lands) or a transient DB hiccup — never break the login.
            Log::debug('StampLastLogin: could not record login time', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
