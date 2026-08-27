<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OpsCenter — Iteration 5 — credential rotation ledger.
 *
 * The inventory SERVICE (OpsCredentialInventoryService) owns the static
 * catalog of the platform's credential surfaces (which env vars exist,
 * whether they are configured, recommended cadence, §15 exposure flags).
 * This table stores only the ROTATION HISTORY: one row per credential
 * key, updated each time an operator records a rotation.
 *
 * DELIBERATELY absent: any column that could hold a secret VALUE. The
 * table stores the key ("coolify-token"), when it was last rotated, by
 * whom, and an operator note — nothing else. Values live only in the
 * environment (Coolify), never in the database, never in the UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_credentials', function (Blueprint $table) {
            // Catalog key, e.g. 'coolify-token' (see
            // OpsCredentialInventoryService::CATALOG). UNIQUE — one ledger
            // row per credential, updated in place.
            $table->string('key', 60)->primary();
            $table->timestamp('last_rotated_at')->nullable();
            // Who recorded the rotation (null if the account is later
            // deleted — the timestamp itself stays).
            $table->foreignId('rotated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            // Free-form operator note (validated to 250 chars upstream —
            // this column is never a place to paste a secret; the UI says so
            // and tests assert values never round-trip).
            $table->string('notes', 250)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_credentials');
    }
};
