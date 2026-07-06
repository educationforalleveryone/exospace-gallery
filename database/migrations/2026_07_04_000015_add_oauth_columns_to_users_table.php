<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M-24: Add OAuth provider columns to users table.
 *
 * Stores the provider name + provider ID for users who registered/logged
 * in via Google or GitHub. A user can link multiple providers.
 *
 *   - google_id: Google's unique user ID (null = not linked)
 *   - github_id: GitHub's unique user ID (null = not linked)
 *   - avatar_url: profile picture URL from the OAuth provider (optional)
 *
 * Linking flow:
 *   1. User clicks "Link Google" on their profile page
 *   2. OAuth redirect → Google consent → callback
 *   3. OAuthController::callback() stores google_id on the user
 *   4. User can now log in with Google (in addition to email/password)
 *
 * Registration flow:
 *   1. User clicks "Sign up with Google" on the register page
 *   2. OAuth redirect → Google consent → callback
 *   3. If no user exists with that google_id or email → create new user
 *   4. If user exists with matching email → link google_id (account merge)
 *   5. Log in the user
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->after('dunning_last_sent_at');
            $table->string('github_id')->nullable()->after('google_id');
            $table->string('avatar_url')->nullable()->after('github_id');

            // Index for fast OAuth callback lookups
            $table->index('google_id');
            $table->index('github_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['google_id']);
            $table->dropIndex(['github_id']);
            $table->dropColumn(['google_id', 'github_id', 'avatar_url']);
        });
    }
};
