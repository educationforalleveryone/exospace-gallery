<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add lifecycle_nudged_at column to users for tracking lifecycle
     * email dispatch. (Task H55)
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('lifecycle_nudged_at')->nullable()->after('ban_reason');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('lifecycle_nudged_at');
        });
    }
};
