<?php

namespace App\Http\Controllers;

use App\Models\SurveyResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * M-18: NPS/CSAT survey controller.
 *
 * Handles survey submission (AJAX) + admin NPS dashboard.
 */
class SurveyController extends Controller
{
    /**
     * Submit an NPS survey response.
     * POST /survey/nps
     */
    public function submitNps(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'score'    => ['required', 'integer', 'min:0', 'max:10'],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        // Check if user already responded (one NPS per user)
        $existing = SurveyResponse::where('user_id', $user->id)
            ->where('survey_type', 'nps')
            ->whereNotNull('responded_at')
            ->exists();

        if ($existing) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'You have already submitted an NPS response.'], 422);
            }
            return back()->with('info', 'You have already submitted a survey response.');
        }

        $survey = SurveyResponse::create([
            'user_id'      => $user->id,
            'survey_type'  => 'nps',
            'score'        => $validated['score'],
            'feedback'     => $validated['feedback'] ?? null,
            'triggered_at' => now(),
            'responded_at' => now(),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Thank you for your feedback!']);
        }

        return back()->with('status', 'Thank you for your feedback!');
    }

    /**
     * Admin: NPS dashboard.
     * GET /master-control/nps
     *
     * E-5 FIX (Iter-011): Replaced the `SurveyResponse::...->get()` call (which
     * loaded EVERY NPS response into a PHP Collection — 5-10MB at 10k responses)
     * with a single SQL aggregate query. The math (count promoters/passives/
     * detractors, avg score) now happens in the DB, not PHP. One row returned
     * instead of N rows.
     */
    public function npsDashboard(Request $request)
    {
        $responses = SurveyResponse::where('survey_type', 'nps')
            ->whereNotNull('responded_at')
            ->with('user')
            ->latest('responded_at')
            ->paginate(25);

        // E-5 FIX: Single aggregate query — one row, ~7 columns.
        // SUM(CASE WHEN ...) is portable across MySQL, PostgreSQL, SQLite.
        // AVG(score) returns a string from MySQL (decimal type) — cast to float.
        $agg = DB::table('survey_responses')
            ->where('survey_type', 'nps')
            ->whereNotNull('responded_at')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN score >= 9 THEN 1 ELSE 0 END) AS promoters')
            ->selectRaw('SUM(CASE WHEN score BETWEEN 7 AND 8 THEN 1 ELSE 0 END) AS passives')
            ->selectRaw('SUM(CASE WHEN score <= 6 THEN 1 ELSE 0 END) AS detractors')
            ->selectRaw('COALESCE(AVG(score), 0) AS avg_score')
            ->first();

        $total     = (int) ($agg->total ?? 0);
        $promoters = (int) ($agg->promoters ?? 0);
        $passives  = (int) ($agg->passives ?? 0);
        $detractors = (int) ($agg->detractors ?? 0);
        $avgScore  = round((float) ($agg->avg_score ?? 0), 1);
        $npsScore  = $total > 0 ? (int) round((($promoters - $detractors) / $total) * 100) : 0;

        $stats = [
            'total'      => $total,
            'promoters'  => $promoters,
            'passives'   => $passives,
            'detractors' => $detractors,
            'nps_score'  => $npsScore,
            'avg_score'  => $avgScore,
        ];

        return view('super-admin.nps.index', compact('responses', 'stats'));
    }
}
