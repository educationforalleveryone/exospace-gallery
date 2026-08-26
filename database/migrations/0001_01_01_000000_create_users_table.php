<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // ITERATION-1 FIX (portable rollback): late migrations (galleries,
        // teams, invoices, ...) hold FKs to users, and SQLite enforces them
        // during DROP TABLE even when the referencing table's own migration
        // has already rolled back (migrations run inside a transaction —
        // PRAGMA foreign_keys is a no-op there). Drop the dependent tables
        // first; they are recreated by their own migrations on re-migrate.
        foreach (['galleries', 'team_user', 'team_invitations', 'teams',
                  'pending_upgrades', 'invoices', 'transactions',
                  'newsletter_signups', 'gdpr_deletion_requests',
                  'personal_access_tokens', 'password_histories',
                  'user_notifications', 'user_feedback', 'survey_responses'] as $dependent) {
            Schema::dropIfExists($dependent);
        }
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
