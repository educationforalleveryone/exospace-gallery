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
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['plan', 'max_galleries', 'max_images']);
        });
    }
};