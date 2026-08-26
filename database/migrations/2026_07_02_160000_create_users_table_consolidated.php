<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated users table creation. (Task H40 / audit M4)
 *
 * ITERATION-003 FIX (audit G-3): This consolidated migration was MISSING
 * 12+ columns from later additive migrations. The archive README instructs
 * maintainers to move additive migrations to archive/ once all production
 * environments have run the consolidated version. The moment that happens,
 * every fresh install (migrate:fresh) would produce a users table missing:
 *   - lifecycle_nudged_at, inactive_nudged_at, plan_expiry_reminded_at
 *   - google2fa_secret, mfa_enabled_at, mfa_backup_codes
 *   - marketing_consent
 *   - subscription_id, subscription_status, subscription_cancelled_at,
 *     subscription_ends_at
 *   - dunning_step, dunning_last_sent_at
 *   - google_id, github_id, avatar_url
 *   - trial_ends_at
 *   - has_password, password_set_at (added in Iter-001)
 *
 * MFA login, subscription billing, dunning emails, OAuth login, and trial
 * workflows would all crash with "column not found".
 *
 * FIX: Added ALL columns from the missing additive migrations to this
 * consolidated migration. Mirrors the exact P0-6 fix pattern used for
 * venue_templates. A MigrateFreshTest (added in this iteration) asserts
 * every column the User model references exists after migrate:fresh.
 *
 * Replaces these additive migrations for fresh installs:
 *   0001_01_01_000000_create_users_table.php                          (base)
 *   2026_02_01_042719_add_plans_to_users_table.php                    (plan columns)
 *   2026_02_07_042958_add_super_admin_flag_to_users_table.php         (super admin)
 *   2026_04_23_121455_add_current_team_id_to_users_table.php          (team context)
 *   2026_04_25_015249_add_banned_at_to_users_table.php                (ban)
 *   2026_07_02_170100_add_lifecycle_nudged_at_to_users_table.php      (lifecycle)
 *   2026_07_02_170200_add_mfa_columns_to_users_table.php              (MFA)
 *   2026_07_04_000001_add_marketing_consent_to_users_table.php        (CAN-SPAM)
 *   2026_07_04_000002_split_lifecycle_nudged_at_on_users_table.php    (lifecycle split)
 *   2026_07_04_000005_add_mfa_backup_codes_to_users_table.php         (MFA backup)
 *   2026_07_04_000010_add_subscription_columns_to_users_table.php     (subscription)
 *   2026_07_04_000011_add_dunning_columns_to_users_table.php          (dunning)
 *   2026_07_04_000015_add_oauth_columns_to_users_table.php            (OAuth)
 *   2026_07_04_000016_add_trial_columns_to_users_table.php            (trial)
 *   2026_07_10_000001_add_has_password_to_users_table.php             (Iter-001: has_password)
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

            // M-1: Subscription tracking columns (from 2026_07_04_000010)
            $table->string('subscription_id')->nullable();
            $table->string('subscription_status')->nullable();
            $table->timestamp('subscription_cancelled_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();

            // M-9: Dunning tracking columns (from 2026_07_04_000011)
            $table->tinyInteger('dunning_step')->nullable();
            $table->timestamp('dunning_last_sent_at')->nullable();

            // M-7: Trial period (from 2026_07_04_000016)
            $table->timestamp('trial_ends_at')->nullable();

            // Super admin (from 2026_02_07_042958)
            $table->boolean('is_super_admin')->default(false);

            // Team context (from 2026_04_23_121455)
            $table->unsignedBigInteger('current_team_id')->nullable();
            // G-3 FIX: The teams migration does NOT add a FK on
            // users.current_team_id (despite the old comment claiming so).
            // The FK + index are added by 2026_07_04_000003. We add them
            // inline here so the consolidated migration is self-contained.
            $table->foreign('current_team_id')->references('id')->on('teams')->onDelete('set null');
            $table->index('current_team_id');

            // Ban (from 2026_04_25_015249)
            $table->timestamp('banned_at')->nullable();
            $table->text('ban_reason')->nullable();

            // Lifecycle nudges (from 2026_07_02_170100 + 2026_07_04_000002)
            // The original lifecycle_nudged_at was split into inactive_nudged_at
            // and plan_expiry_reminded_at. The split migration keeps
            // lifecycle_nudged_at for backward compat (it's nullable and unused).
            $table->timestamp('lifecycle_nudged_at')->nullable();
            $table->timestamp('inactive_nudged_at')->nullable();
            $table->timestamp('plan_expiry_reminded_at')->nullable();

            // MFA (from 2026_07_02_170200 + 2026_07_04_000005)
            $table->text('google2fa_secret')->nullable();
            $table->timestamp('mfa_enabled_at')->nullable();
            $table->json('mfa_backup_codes')->nullable();

            // CAN-SPAM / GDPR marketing consent (from 2026_07_04_000001)
            $table->boolean('marketing_consent')->default(false);

            // M-24: OAuth (from 2026_07_04_000015)
            $table->string('google_id')->nullable();
            $table->string('github_id')->nullable();
            $table->string('avatar_url')->nullable();
            $table->index('google_id');
            $table->index('github_id');

            // C-2 FIX (Iter-001): has_password column for OAuth unlink guard
            $table->boolean('has_password')->default(true);
            $table->timestamp('password_set_at')->nullable();

            $table->timestamps();

            // Index for trial queries (from 2026_07_04_000016)
            $table->index('trial_ends_at');
        });
    }

    public function down(): void
    {
        // ITERATION-1 FIX (portable rollback): see the base users migration —
        // dependent tables first (FK enforcement cannot be disabled inside
        // the migration transaction).
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
