<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add affiliate_id column to pending_upgrades for referral tracking.
     * (Task H58)
     */
    public function up(): void
    {
        Schema::table('pending_upgrades', function (Blueprint $table) {
            $table->string('affiliate_id', 100)->nullable()->after('notified_at');
            $table->index('affiliate_id');
        });
    }

    public function down(): void
    {
        Schema::table('pending_upgrades', function (Blueprint $table) {
            $table->dropIndex(['affiliate_id']);
            $table->dropColumn('affiliate_id');
        });
    }
};
