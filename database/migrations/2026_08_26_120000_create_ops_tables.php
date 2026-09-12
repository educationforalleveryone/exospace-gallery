<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_applications', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('name');
            // Where this row came from: coolify | ingest | self | manual
            $table->string('provider', 30)->default('manual');
            $table->string('provider_uuid', 100)->nullable()->index();
            // application | database | service | server
            $table->string('kind', 30)->default('application');
            $table->string('environment', 50)->default('production');
            $table->string('url')->nullable();
            $table->string('status', 60)->default('unknown');
            // Derived rollup: unknown | running | degraded | stopped
            $table->string('health', 20)->default('unknown');
            $table->timestamp('status_checked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('meta')->nullable();
            $table->boolean('is_self')->default(false)->index();
            $table->timestamps();

            $table->index(['kind', 'health']);
        });

        Schema::create('ops_events', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->foreignId('ops_application_id')
                ->nullable()
                ->constrained('ops_applications')
                ->nullOnDelete();
            $table->string('source', 30)->default('system');
            $table->string('category', 30)->default('UNKNOWN');
            // critical | error | warning | info
            $table->string('severity', 10)->default('info');
            $table->string('title', 250);
            $table->text('message')->nullable();

            $table->unsignedInteger('occurrence_count')->default(1);
            $table->unsignedBigInteger('total_count')->default(1);

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            // open | acknowledged | resolved
            $table->string('status', 20)->default('open')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->string('environment', 50)->nullable();

            $table->json('context')->nullable();
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
