<?php

declare(strict_types=1);

namespace App\Ops\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Ops\Services\OpsMorningDigestService;
use App\Ops\Services\OpsWeeklyReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class OpsDigestController extends Controller
{
    public function __construct(
        private readonly OpsMorningDigestService $digest,
        private readonly OpsWeeklyReviewService $weekly,
    ) {}

    public function index(): View
    {
        try {
            $composed = $this->digest->compose();
            $text = $this->digest->render($composed);
        } catch (Throwable) {
            $composed = null;
            $text = '';
        }

        // Compose the weekly review preview the same way.
        try {
            $weeklyComposed = $this->weekly->compose();
            $weeklyText = $this->weekly->render($weeklyComposed);
        } catch (Throwable) {
            $weeklyComposed = null;
            $weeklyText = '';
        }

        return view('ops.digest', [
            'digest' => $composed,
            'text' => $text,
            'lastSent' => $this->digest->lastSent(),
            'enabled' => (bool) config('ops.digest.enabled'),

            'weekly' => $weeklyComposed,
            'weeklyText' => $weeklyText,
            'weeklyLastSent' => $this->weekly->lastSent(),
            'weeklyEnabled' => (bool) config('ops.weekly_review.enabled'),

            'weeklySnapshots' => $this->weekly->recentSnapshots(8),
        ]);
    }

    public function sendNow(Request $request): RedirectResponse
    {
        $result = $this->digest->send('manual');

        try {
            AdminAuditLog::record('ops.digest.sent', $request->user(), [
                'trigger' => 'manual',
                'sections' => (int) ($result['sections'] ?? 0),
            ]);
        } catch (Throwable) {
        }

        if (($result['sent'] ?? false) === true) {
            return redirect()
                ->route('ops.digest.index')
                ->with('success', 'Digest sent to the operational channel ('.$result['sections'].' sections).');
        }

        return redirect()
            ->route('ops.digest.index')
            ->with('error', 'The digest was composed but the webhook call failed — check the OPERATIONAL_ALERT_WEBHOOK configuration and the Laravel log.');
    }

    public function sendWeeklyNow(Request $request): RedirectResponse
    {
        $result = $this->weekly->send('manual');

        try {
            AdminAuditLog::record('ops.weekly_review.sent', $request->user(), [
                'trigger' => 'manual',
                'sections' => (int) ($result['sections'] ?? 0),
            ]);
        } catch (Throwable) {
        }

        if (($result['sent'] ?? false) === true) {
            return redirect()
                ->route('ops.digest.index')
                ->with('success', 'Weekly review sent to the operational channel ('.$result['sections'].' sections).');
        }

        return redirect()
            ->route('ops.digest.index')
            ->with('error', 'The weekly review was composed but the webhook call failed — check the OPERATIONAL_ALERT_WEBHOOK configuration and the Laravel log.');
    }
}
