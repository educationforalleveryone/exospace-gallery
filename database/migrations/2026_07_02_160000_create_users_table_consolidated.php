<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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

            // Subscription tracking columns
            $table->string('subscription_id')->nullable();
            $table->string('subscription_status')->nullable();
            $table->timestamp('subscription_cancelled_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();

            // Dunning tracking columns
            $table->tinyInteger('dunning_step')->nullable();
            $table->timestamp('dunning_last_sent_at')->nullable();

            // Trial period
            $table->timestamp('trial_ends_at')->nullable();

            // Super admin (from 2026_02_07_042958)
            $table->boolean('is_super_admin')->default(false);

            // Team context (from 2026_04_23_121455)
            $table->unsignedBigInteger('current_team_id')->nullable();
            $table->foreign('current_team_id')->references('id')->on('teams')->onDelete('set null');
            $table->index('current_team_id');

            // Ban (from 2026_04_25_015249)
            $table->timestamp('banned_at')->nullable();
            $table->text('ban_reason')->nullable();

            $table->timestamp('lifecycle_nudged_at')->nullable();
            $table->timestamp('inactive_nudged_at')->nullable();
            $table->timestamp('plan_expiry_reminded_at')->nullable();

            // MFA (from 2026_07_02_170200 + 2026_07_04_000005)
            $table->text('google2fa_secret')->nullable();
            $table->timestamp('mfa_enabled_at')->nullable();
            $table->json('mfa_backup_codes')->nullable();

            // CAN-SPAM / GDPR marketing consent (from 2026_07_04_000001)
            $table->boolean('marketing_consent')->default(false);

            // OAuth
            $table->string('google_id')->nullable();
            $table->string('github_id')->nullable();
            $table->string('avatar_url')->nullable();
            $table->index('google_id');
            $table->index('github_id');

            // has_password column for OAuth unlink guard
            $table->boolean('has_password')->default(true);
            $table->timestamp('password_set_at')->nullable();

            $table->timestamps();

            // Index for trial queries (from 2026_07_04_000016)
            $table->index('trial_ends_at');
        });
    }

    public function down(): void
    {
        foreach (['galleries', 'team_user', 'team_invitations', 'teams',
                  'pending_upgrades', 'invoices', 'transactions',
                  'newsletter_signups', 'gdpr_deletion_requests',
                  'personal_access_tokens', 'password_histories',
                  'user_notifications', 'user_feedback', 'survey_responses'] as $dependent) {
            Schema::dropIfExists($dependent);
        }
        Schema::dropIfExists('users');
    }
};
