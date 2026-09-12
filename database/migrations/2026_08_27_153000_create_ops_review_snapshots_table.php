<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_review_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('week_start');
            $table->date('week_end');
            $table->string('trigger', 20); // scheduled | manual
            // Aggregate counts only — see the class docblock.
            $table->json('metrics');
            $table->timestamp('created_at')->index();

            $table->index(['week_start', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_review_snapshots');
    }
};
