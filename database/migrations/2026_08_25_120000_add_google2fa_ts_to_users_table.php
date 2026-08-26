<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ITERATION-3 FIX (TOTP replay window): users.google2fa_ts.
 *
 * MfaController previously accepted any valid TOTP code via
 * Google2FA::verifyKey() — which has no memory. Within each 30-second
 * slice (and the ±1 drift window the library allows by default), the SAME
 * six-digit code verified successfully an unlimited number of times: a
 * phished or keylogged code stayed usable for up to ~90 seconds after the
 * legitimate login, and nothing recorded that it had already been spent.
 *
 * google2fa_ts stores the OTP counter of the LAST accepted code (the
 * counter domain is floor(unixtime/30), what pragmarx's verifyKeyNewer()
 * returns). Verification now uses verifyKeyNewer($secret, $code, $ts):
 *   - codes matching a counter ≤ the stored one are rejected outright,
 *   - a successful verification persists the new counter,
 * so every code is single-use while retaining the ±1 window for clock
 * drift between the user's phone and the server.
 *
 * NULL = MFA enabled before this migration (or not enabled at all): the
 * first post-deploy verification accepts any current code and stamps the
 * baseline, exactly like a fresh enable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'google2fa_ts')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('google2fa_ts')->nullable()->after('google2fa_secret');
            });
        }
    }

    public function down(): void
    {
        // ITERATION-1 FIX (consolidated-migration coexistence): the users
        // table may already be gone when this down() runs in a full
        // rollback on fresh installs.
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'google2fa_ts')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('google2fa_ts');
            });
        }
    }
};
