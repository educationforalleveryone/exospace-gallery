<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- Teams ---
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->onDelete('cascade');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // --- Team Members (pivot) ---
        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('role', ['owner', 'editor', 'viewer'])->default('viewer');
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
        });

        // --- Team Invitations ---
        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->onDelete('cascade');
            $table->string('email');
            $table->enum('role', ['editor', 'viewer'])->default('viewer');
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['team_id', 'email']);
        });

        // --- Add team_id to galleries (nullable so existing galleries aren't broken) ---
        Schema::table('galleries', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('user_id')
                  ->constrained()->onDelete('set null');
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        // ITERATION-1 FIX (consolidated-migration coexistence): on fresh
        // installs the galleries table (and its team_id column) is owned by
        // the consolidated migration that runs later in the batch and has
        // already been rolled back — guard the column removal, and skip the
        // ALTER entirely when only the table drops remain.
        if (Schema::hasTable('galleries') && Schema::hasColumn('galleries', 'team_id')) {
            Schema::table('galleries', function (Blueprint $table) {
                $table->dropForeign(['team_id']);
                $table->dropColumn('team_id');
            });
        }

        Schema::dropIfExists('team_invitations');
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
