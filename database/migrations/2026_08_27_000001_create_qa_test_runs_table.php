<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Testing Control Center — structured test-run history.
 *
 * One row per execution/import of a test profile. Case-level detail lives
 * in qa_test_case_results (second migration). These tables are written by
 * qa:run / qa:import locally and by the Control Center ingest API from CI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_test_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // What ran, where, how.
            $table->string('profile', 64)->index();                 // quick_check, pre_release, ...
            $table->string('environment', 32)->index();             // local, ci, staging, production
            $table->string('safety', 32);                           // test-only | staging-safe | prod-safe-read
            $table->string('trigger', 32)->index()->default('manual');   // manual | ci | api | schedule
            $table->string('runner', 64)->nullable();               // "local", "github-actions", host id

            // Which build was validated.
            $table->string('git_commit', 40)->nullable()->index();
            $table->string('git_branch', 120)->nullable();
            $table->string('git_tag', 120)->nullable();
            $table->string('app_version', 64)->nullable();
            $table->string('ci_run_url', 500)->nullable();          // GitHub Actions run link

            // Lifecycle + outcome.
            // queued|running|passed|failed|cancelled|timed_out|blocked|not_executed
            $table->string('status', 20)->index();
            $table->text('blocked_reason')->nullable();             // human explanation when blocked/not_executed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // Aggregates from the JUnit artifact.
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('passed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('errored')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('timed_out')->default(0);
            $table->unsignedBigInteger('assertions')->default(0);

            // Intelligence hints (enriched in later iterations).
            $table->decimal('coverage_pct', 5, 2)->nullable();
            $table->string('failure_class', 32)->nullable();        // application | infrastructure | mixed | null
            $table->boolean('flaky_suspected')->default(false)->index();

            // Environment fingerprint for honest reporting.
            $table->string('db_driver', 20)->nullable();            // sqlite | mysql
            $table->string('php_version', 20)->nullable();
            $table->json('meta')->nullable();                       // extension info, junit path, ci metadata...

            $table->timestamps();
            $table->index(['profile', 'created_at']);
            $table->index(['environment', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_test_runs');
    }
};
