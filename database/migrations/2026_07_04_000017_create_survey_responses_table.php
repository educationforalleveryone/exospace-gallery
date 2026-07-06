<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M-18: NPS/CSAT survey responses table.
 *
 * Stores Net Promoter Score (NPS) responses:
 *   - score: 0-10 (0-6 = detractor, 7-8 = passive, 9-10 = promoter)
 *   - feedback: optional text feedback
 *   - triggered_at: when the survey was shown to the user
 *   - responded_at: when the user submitted a response
 *
 * NPS = % promoters - % detractors (range: -100 to +100)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('survey_type')->default('nps'); // nps, csat
            $table->tinyInteger('score'); // 0-10 for NPS, 1-5 for CSAT
            $table->text('feedback')->nullable();
            $table->timestamp('triggered_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['survey_type', 'responded_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
    }
};
