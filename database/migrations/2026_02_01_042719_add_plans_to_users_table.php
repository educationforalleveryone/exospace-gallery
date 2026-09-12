<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('plan', ['free', 'pro', 'studio'])->default('free');
            $table->unsignedInteger('max_galleries')->default(1);
            $table->unsignedInteger('max_images')->default(10);
            $table->timestamp('plan_started_at')->nullable();
            $table->timestamp('plan_expires_at')->nullable();
        });

        DB::table('users')->where('plan', 'free')->update([
            'max_galleries'  => 1,
            'max_images'     => 10,
            'plan_started_at'=> DB::raw('COALESCE(plan_started_at, CURRENT_TIMESTAMP)'),
        ]);
        DB::table('users')->where('plan', 'pro')->update([
            'max_galleries'  => 5,
            'max_images'     => 100,
            'plan_started_at'=> DB::raw('COALESCE(plan_started_at, CURRENT_TIMESTAMP)'),
        ]);
        DB::table('users')->where('plan', 'studio')->update([
            'max_galleries'  => 999,
            'max_images'     => 500,  // ← was 100, fixed in task H04
            'plan_started_at'=> DB::raw('COALESCE(plan_started_at, CURRENT_TIMESTAMP)'),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'plan_expires_at',
                'plan_started_at',
                'max_images',
                'max_galleries',
                'plan',
            ]);
        });
    }
};
