<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OpsCenter — Iteration 1 schema.
 *
 * Two tables, deliberately minimal:
 *
 *   ops_applications — the platform-wide registry of monitored things
 *                      (Coolify apps, databases, services, servers, plus
 *                      applications that self-report via the ingest API).
 *
 *   ops_events       — the normalized, DEDUPLICATED event/error store.
 *                      One row per distinct (application, category,
 *                      normalized message) fingerprint. Occurrences update
 *                      counters instead of inserting rows, so a 500-error
 *                      storm produces ONE row with occurrence_count=37 —
 *                      not 37 rows. Raw logs stay in log files / Sentry;
 *                      this table stores aggregates only (bounded growth,
 *                      see config/ops.php retention).
 *
 * Incidents (ops_incidents) arrive in Iteration 2; diagnostics runs in
 * Iteration 3. Keeping them out of Iteration 1 avoids schema churn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_applications', function (Blueprint $table) {
            $table->id();
            // Stable machine identifier. For Coolify-synced rows this is the
            // Coolify resource UUID; for ingest-API apps it's the token key;
            // for self it's 'self'.
            $table->string('slug', 100)->unique();
            $table->string('name');
            // Where this row came from: coolify | ingest | self | manual
            $table->string('provider', 30)->default('manual');
            $table->string('provider_uuid', 100)->nullable()->index();
            // application | database | service | server
            $table->string('kind', 30)->default('application');
            $table->string('environment', 50)->default('production');
            $table->string('url')->nullable();
            // Last known Coolify status string (running:healthy, exited:1,
            // restarting, degrading, ...) — raw, plus derived rollup.
            $table->string('status', 60)->default('unknown');
            // Derived rollup: unknown | running | degraded | stopped
            $table->string('health', 20)->default('unknown');
            $table->timestamp('status_checked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            // Provider-specific extras (image, commit, ports, domains...).
            // Redacted before write when it originates from ingest payloads.
            $table->json('meta')->nullable();
            $table->boolean('is_self')->default(false)->index();
            $table->timestamps();

            $table->index(['kind', 'health']);
        });

        Schema::create('ops_events', function (Blueprint $table) {
            $table->id();
            // Grouping key: sha256(application_id | category | normalized
            // message). UNIQUE — re-ingesting the same error updates this
            // row instead of inserting (the dedup contract).
            $table->string('fingerprint', 64)->unique();
            $table->foreignId('ops_application_id')
                ->nullable()
                ->constrained('ops_applications')
                ->nullOnDelete();
            // Where the event came from:
            // exception | app_log | coolify | ingest | health | heartbeat |
            // backup | webhook | scheduler | system
            $table->string('source', 30)->default('system');
            // DATABASE | MIGRATION | APPLICATION | PHP | LARAVEL | REDIS |
            // QUEUE | WEBHOOK | BUILD | DEPLOYMENT | DOCKER | CONTAINER |
            // STORAGE | NETWORK | AUTHENTICATION | AUTHORIZATION |
            // EXTERNAL_SERVICE | BACKUP | INFRASTRUCTURE | UNKNOWN
            $table->string('category', 30)->default('UNKNOWN');
            // critical | error | warning | info
            $table->string('severity', 10)->default('info');
            $table->string('title', 250);
            $table->text('message')->nullable();

            // Episode counters (see OpsEventIngestor):
            //   occurrence_count — hits since the event last became open
            //   total_count      — all-time hits (survives reopen/resolves)
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->unsignedBigInteger('total_count')->default(1);

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            // open | acknowledged | resolved
            $table->string('status', 20)->default('open')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->string('environment', 50)->nullable();

            // Redacted context: request_id, url, file/line, stack excerpt,
            // deployment/commit correlation keys, provider payloads...
            $table->json('context')->nullable();
            // Classifier output: likely_causes[], recommended_diagnostics[],
            // confidence, matched_pattern. The dashboard renders this into
            // the "likely cause" / "recommended next step" sections.
            $table->json('classification')->nullable();

            $table->timestamps();

            $table->index(['severity', 'status']);
            $table->index(['last_seen_at']);
            $table->index(['category']);
            $table->index(['source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_events');
        Schema::dropIfExists('ops_applications');
    }
};
