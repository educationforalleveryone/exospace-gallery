<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add notified_at column to pending_upgrades for abandoned-cart
     * recovery tracking. (Task H53)
     */
    public function up(): void
    {
        Schema::table('pending_upgrades', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('expires_at');
            $table->index('notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('pending_upgrades', function (Blueprint $table) {
            $table->dropIndex(['notified_at']);
            $table->dropColumn('notified_at');
        });
    }
};
