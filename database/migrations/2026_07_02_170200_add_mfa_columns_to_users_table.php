<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add MFA columns to the users table for TOTP-based two-factor
     * authentication. (Task H56 / audit M3)
     *
     * Columns:
     *   - google2fa_secret: encrypted TOTP secret (null = MFA not enabled)
     *   - mfa_enabled_at: timestamp when MFA was activated
     *
     * Only super-admin accounts are required to use MFA (enforced by
     * the RequireMfa middleware). Regular users can optionally enable
     * it from their profile settings.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google2fa_secret')->nullable()->after('ban_reason');
            $table->timestamp('mfa_enabled_at')->nullable()->after('google2fa_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['google2fa_secret', 'mfa_enabled_at']);
        });
    }
};
