<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OpsCenter — Iteration 3: the diagnostic run store.
 *
 * One row per execution of an allow-listed diagnostic (read-only checks:
 * database/Redis/queue/container/server/deployment/application). Rows are
 * immutable snapshots — re-running creates a new row — and are pruned after
 * ops.diagnostics.retention_days (default 30) by the existing
 * ops:prune-events command (runs are reproducible on demand, so short
 * retention loses nothing).
 *
 * actor_id is nullable on purpose: today every run is operator-triggered
 * (super-admin + MFA), but the schema allows scheduled/automated runs later
 * without a migration.
 *
 * No secrets by construction: the DiagnosticEngine runs every finding and
 * summary through LogRedactor BEFORE persisting (defense in depth on top of
 * runners that already avoid sensitive values).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_diagnostic_runs', function (Blueprint $table) {
            $table->id();
            $table->string('diagnostic_id', 64);
            $table->foreignId('ops_application_id')
                  ->nullable()
                  ->constrained('ops_applications')
                  ->nullOnDelete();
            $table->foreignId('actor_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            // manual | event | incident (source_id = the triggering row's id)
            $table->string('source', 20)->default('manual');
            $table->unsignedInteger('source_id')->nullable();
            $table->string('status', 20); // healthy|degraded|failed|inconclusive
            $table->string('summary', 500);
            $table->json('findings')->nullable();   // [{label,status,detail}]
            $table->text('interpretation')->nullable();
            $table->json('next_steps')->nullable(); // [string]
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->index();

            $table->index(['diagnostic_id', 'created_at']);
            $table->index('ops_application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_diagnostic_runs');
    }
};
