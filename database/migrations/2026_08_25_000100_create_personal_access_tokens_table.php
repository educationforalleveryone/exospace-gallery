<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ITERATION-1 FIX (fresh-install schema completeness): create the Sanctum
 * `personal_access_tokens` table.
 *
 * The API token feature (ApiTokenController, /api/v1 tokens endpoints) relies
 * on this table, but NO migration in the repository created it — the original
 * `sanctum:install` migration file was evidently lost during one of the
 * migration-consolidation passes. Existing production databases have the
 * table (created before the loss), so production works; but any FRESH
 * install (new environment, CI, a rebuild from scratch) produced a schema
 * where every API-token operation throws "no such table:
 * personal_access_tokens".
 *
 * hasTable-guarded: no-op on databases that already have the table.
 *
 * Standard Sanctum v4 schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
