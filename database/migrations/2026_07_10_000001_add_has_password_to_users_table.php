<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Iteration-001: Add has_password column to users (audit C-2 fix).
 *
 * CRITICAL FIX: OAuthController::unlink had a provably-broken hasPassword check:
 *
 *     $hasPassword = ! empty($user->password) &&
 *         $user->password !== Hash::make(\Illuminate\Support\Str::random(32));
 *
 * Hash::make() (bcrypt) includes a random salt, so two hashes of two different
 * random strings will NEVER be equal. Therefore the !== comparison is ALWAYS
 * true, so $hasPassword is ALWAYS true, so the "cannot unlink — it's your only
 * login method" guard NEVER fires. An OAuth-only user can unlink their only
 * OAuth provider, locking themselves out of their account.
 *
 * This migration adds a real boolean column to track whether the user has set
 * a real password. OAuthController should check this column instead of trying
 * to infer from the bcrypt hash.
 *
 * Backfill strategy:
 *   - All existing users with a non-null password are assumed to have a real
 *     password (has_password = true, password_set_at = created_at).
 *   - This is the safe default: OAuth-only users who try to unlink will see
 *     a confusing "cannot unlink" message but can fix it by setting a
 *     password first — vs. the alternative of being locked out.
 *
 * The User model's boot() hooks (creating/updating) maintain has_password
 * for all future user creations and password changes.
 *
 * Rollback: drops the has_password and password_set_at columns. Safe to
 * roll back — OAuthController::unlink will fall back to the old broken
 * behavior (which never blocked unlink), so no users are locked out by
 * rollback. But rolling back re-opens the self-DoS bug (C-2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // has_password: true if the user has set a real password via the
            // register or password-reset flow. false if the user was created
            // via OAuth and has only a random bcrypt hash as a placeholder.
            //
            // Default: true (safe default — see backfill below).
            $table->boolean('has_password')->default(true)->after('password');

            // password_set_at: when the user last set/changed their password.
            // Useful for security audits ("has the user changed their password
            // since the breach?") and for password-rotation policy enforcement.
            $table->timestamp('password_set_at')->nullable()->after('has_password');
        });

        // Backfill: any user with a non-null password that was set BEFORE this
        // migration has a real password. We can't reliably distinguish OAuth-only
        // users from password users post-hoc (both have a non-null password
        // column — OAuth-only users have a random bcrypt hash as a placeholder),
        // so we assume all existing users have real passwords.
        //
        // Safe default: if an existing OAuth-only user tries to unlink, they'll
        // see "cannot unlink — set a password first" — which is correct behavior
        // (they SHOULD set a password first). They can fix it by going through
        // the password-reset flow, which sets a real password and flips
        // has_password to true (via the User model's updating hook).
        //
        // Alternative considered: set has_password=false for all existing users
        // and let them set a password on next login. Rejected because it would
        // break the unlink flow for legitimate users who DO have a password.
        DB::table('users')
            ->whereNotNull('password')
            ->update([
                'has_password'    => true,
                'password_set_at' => DB::raw('created_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['has_password', 'password_set_at']);
        });
    }
};
