<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // TEXT type — the encrypted secret can exceed VARCHAR(255)
            $table->text('google2fa_secret')->nullable()->after('ban_reason');
            $table->timestamp('mfa_enabled_at')->nullable()->after('google2fa_secret');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['google2fa_secret', 'mfa_enabled_at']);
        });
    }
};