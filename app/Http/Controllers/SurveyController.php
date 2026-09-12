<?php

namespace App\Http\Controllers;

use App\Models\SurveyResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SurveyController extends Controller
{
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

    public function npsDashboard(Request $request)
    {
        $responses = SurveyResponse::where('survey_type', 'nps')
            ->whereNotNull('responded_at')
            ->with('user')
            ->latest('responded_at')
            ->paginate(25);

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
