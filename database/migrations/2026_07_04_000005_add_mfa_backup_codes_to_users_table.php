<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3-7: Add MFA backup codes column to users.
 *
 * When a super-admin enables MFA, 10 one-time backup codes are generated.
 * Each code is bcrypt-hashed and stored as a JSON array. The plain-text
 * codes are shown once at setup time. If the super-admin loses their TOTP
 * device, they can use a backup code to verify MFA.
 *
 * Used backup codes are removed from the array (set to null in the JSON).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('mfa_backup_codes')->nullable()->after('mfa_enabled_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('mfa_backup_codes');
        });
    }
};
