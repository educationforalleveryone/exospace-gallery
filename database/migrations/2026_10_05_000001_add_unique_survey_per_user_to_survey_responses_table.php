<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The NPS flow enforces "one response per user" in application code
     * (SurveyController::submitNps checks then creates). The check-then-insert
     * window let concurrent double-submits persist duplicate rows, which skew
     * the NPS aggregate. This backs the invariant with a real database
     * constraint: one response per (user_id, survey_type).
     */
    public function up(): void
    {
        // Collapse any historical duplicates before the unique index can be
        // created. Keeps the most recent response per (user_id, survey_type).
        // The derived table sidesteps MySQL's ERROR 1093 (cannot re-select
        // the delete target inside the subquery) and works on sqlite too.
        DB::statement(<<<'SQL'
            DELETE FROM survey_responses
            WHERE id NOT IN (
                SELECT id FROM (
                    SELECT MAX(id) AS id FROM survey_responses GROUP BY user_id, survey_type
                ) keep_latest
            )
        SQL);

        Schema::table('survey_responses', function ($table) {
            $table->unique(['user_id', 'survey_type']);
        });
    }

    public function down(): void
    {
        Schema::table('survey_responses', function ($table) {
            $table->dropUnique(['user_id', 'survey_type']);
        });
    }
};
