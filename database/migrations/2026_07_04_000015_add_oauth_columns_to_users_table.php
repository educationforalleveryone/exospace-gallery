<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->after('dunning_last_sent_at');
            $table->string('github_id')->nullable()->after('google_id');
            $table->string('avatar_url')->nullable()->after('github_id');

            // Index for fast OAuth callback lookups
            $table->index('google_id');
            $table->index('github_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['google_id']);
            $table->dropIndex(['github_id']);
            $table->dropColumn(['google_id', 'github_id', 'avatar_url']);
        });
    }
};
