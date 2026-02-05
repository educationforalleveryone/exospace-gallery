<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('plan', ['free', 'pro', 'studio'])->default('free')->after('password');
            $table->unsignedInteger('max_galleries')->default(1)->after('plan');
            $table->unsignedInteger('max_images')->default(10)->after('max_galleries');
            $table->timestamp('plan_started_at')->nullable()->after('max_images');
            $table->timestamp('plan_expires_at')->nullable()->after('plan_started_at');
        });

        // Set generous limits for Pro/Studio (if any exist)
        DB::table('users')->where('plan', 'pro')->update([
            'max_galleries' => 999,
            'max_images' => 100
        ]);
        
        DB::table('users')->where('plan', 'studio')->update([
            'max_galleries' => 999,
            'max_images' => 100
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['plan', 'max_galleries', 'max_images', 'plan_started_at', 'plan_expires_at']);
        });
    }
};