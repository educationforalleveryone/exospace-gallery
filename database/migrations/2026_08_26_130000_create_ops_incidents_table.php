<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            $table->string('title', 250);
            // Worst severity among member events (can escalate).
            $table->string('severity', 10)->default('error');
            // open | acknowledged | resolved
            $table->string('status', 20)->default('open')->index();
            // Root-cause CANDIDATE (never claimed certain).
            $table->foreignId('root_cause_event_id')->nullable()->constrained('ops_events')->nullOnDelete();
            // DEPLOYMENT | MIGRATION | CONTAINER | ... | UNKNOWN
            $table->string('root_cause_category', 30)->nullable();
            $table->string('confidence', 10)->default('low');
            $table->string('correlation_key', 64)->unique();
            $table->unsignedInteger('event_count')->default(1);
            $table->timestamp('first_event_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
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
            $table->dropIndex(['ops_incident_id']);
        });

        Schema::table('ops_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ops_incident_id');
        });

        Schema::dropIfExists('ops_incidents');
    }
};
