<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('banned_at')->nullable()->after('current_team_id');
            $table->text('ban_reason')->nullable()->after('banned_at');
        });
    }

    public function down(): void
    {
        // ITERATION-1 FIX (consolidated-migration coexistence): guard for
        // fresh installs where the consolidated migration owns the column.
        if (! Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['banned_at', 'ban_reason']);
        });
    }
};