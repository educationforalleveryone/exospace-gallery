<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OpsCenter — Iteration 2: the incident model.
 *
 * An incident is a CORRELATED group of events that belong to one
 * operational story (the brief's core example: deployment #184 → migration
 * failed → container restarted → HTTP 500 spike → Sentry spike = ONE
 * incident with a timeline, not five unrelated errors).
 *
 * Linking: ops_events.ops_incident_id → ops_incidents.id. Events stay
 * first-class (the Errors pages are unchanged); the incident is an
 * overlay that groups them.
 *
 * Correlation keys:
 *   - Grouping is done by IncidentCorrelationService (time window +
 *     application + causal-chain detection), NOT by this schema.
 *   - `correlation_key` dedups incident creation for the same root event
 *     (sha256 of app|root_event_id) so re-running correlation never
 *     duplicates an incident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ops_application_id')
                  ->nullable()
                  ->constrained('ops_applications')
                  ->nullOnDelete();
            // Human headline: "Deployment failure cascade — Exospace" or
            // the root event's title.
            $table->string('title', 250);
            // Worst severity among member events (can escalate).
            $table->string('severity', 10)->default('error');
            // open | acknowledged | resolved
            $table->string('status', 20)->default('open')->index();
            // Root-cause CANDIDATE (never claimed certain).
            $table->foreignId('root_cause_event_id')->nullable()->constrained('ops_events')->nullOnDelete();
            // DEPLOYMENT | MIGRATION | CONTAINER | ... | UNKNOWN
            $table->string('root_cause_category', 30)->nullable();
            // high  — a causal header (deployment/migration) demonstrably
            //         preceded the symptoms
            // medium — same-application time cluster, no causal header
            // low   — single-event incident
            $table->string('confidence', 10)->default('low');
            // sha256(application_id | root_event_id | created_window) —
            // unique so correlation re-runs never duplicate incidents.
            $table->string('correlation_key', 64)->unique();
            $table->unsignedInteger('event_count')->default(1);
            $table->timestamp('first_event_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            // Chain summary rendered on the timeline: deployment uuid,
            // commit, container, affected subsystems...
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index(['last_event_at']);
        });

        Schema::table('ops_events', function (Blueprint $table) {
            $table->foreignId('ops_incident_id')
                  ->nullable()
                  ->after('ops_application_id')
                  ->constrained('ops_incidents')
                  ->nullOnDelete();
            $table->index('ops_incident_id');
        });
    }

    public function down(): void
    {
        Schema::table('ops_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ops_incident_id');
        });

        Schema::dropIfExists('ops_incidents');
    }
};
