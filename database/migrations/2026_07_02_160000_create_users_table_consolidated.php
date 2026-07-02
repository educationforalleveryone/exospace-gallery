<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated users table creation. (Task H40 / audit M4)
 *
 * Replaces 5 additive migrations for fresh installs:
 *   0001_01_01_000000_create_users_table.php           (base)
 *   2026_02_01_042719_add_plans_to_users_table.php      (plan columns)
 *   2026_02_07_042958_add_super_admin_flag_to_users_table.php
 *   2026_04_23_121455_add_current_team_id_to_users_table.php
 *   2026_04_25_015249_add_banned_at_to_users_table.php
 *
 * No-op on existing databases (checks hasTable first).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();

            // Plan columns (from 2026_02_01_042719)
            $table->enum('plan', ['free', 'pro', 'studio'])->default('free');
            $table->integer('max_galleries')->default(1);
            $table->integer('max_images')->default(10);
            $table->timestamp('plan_started_at')->nullable();
            $table->timestamp('plan_expires_at')->nullable();

            // Super admin (from 2026_02_07_042958)
            $table->boolean('is_super_admin')->default(false);

            // Team context (from 2026_04_23_121455)
            $table->unsignedBigInteger('current_team_id')->nullable();
            // FK added by the teams migration's constrained() call

            // Ban (from 2026_04_25_015249)
            $table->timestamp('banned_at')->nullable();
            $table->text('ban_reason')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
