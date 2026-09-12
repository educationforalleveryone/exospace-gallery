<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_test_case_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qa_test_run_id')->constrained()->cascadeOnDelete();

            $table->string('test_identifier')->index();             // Class FQCN :: method (data-set normalised)
            $table->string('classname')->index();
            $table->string('method_name');
            $table->string('data_set', 255)->nullable();            // data-provider suffix if any

            // passed | failed | error | skipped | warning | timed_out
            $table->string('status', 16)->index();

            $table->unsignedInteger('time_ms')->nullable();
            $table->text('message')->nullable();                    // first line of failure/error message
            $table->longText('detail')->nullable();                 // full failure diff / exception text
            $table->string('exception_class', 190)->nullable();

            $table->timestamps();

            $table->index(['qa_test_run_id', 'status']);
            $table->index(['test_identifier', 'created_at']);      // history per test (flaky detection)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_test_case_results');
    }
};
