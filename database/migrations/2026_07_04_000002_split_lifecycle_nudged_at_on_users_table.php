<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add the two new columns.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('inactive_nudged_at')->nullable()->after('lifecycle_nudged_at');
            $table->timestamp('plan_expiry_reminded_at')->nullable()->after('inactive_nudged_at');
        });

        DB::table('users')
            ->whereNotNull('lifecycle_nudged_at')
            ->update([
                'inactive_nudged_at' => DB::raw('lifecycle_nudged_at'),
            ]);

        // 3. Drop the old column.
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('lifecycle_nudged_at');
        });
    }

    public function down(): void
    {
        // 1. Restore the old column.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('lifecycle_nudged_at')->nullable()->after('ban_reason');
        });

        DB::table('users')
            ->whereNotNull('inactive_nudged_at')
            ->update([
                'lifecycle_nudged_at' => DB::raw('inactive_nudged_at'),
            ]);

        // 3. Drop the two new columns.
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('inactive_nudged_at');
            $table->dropColumn('plan_expiry_reminded_at');
        });
    }
};
