<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3-9: Add password_histories table for password reuse prevention.
 *
 * When a user changes their password, the old hash is stored here.
 * PasswordController checks the last 5 hashes before allowing a new
 * password — if the new password matches any of the last 5, it's rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('password_hash');
            $table->timestamp('created_at')->useCurrent();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_histories');
    }
};
