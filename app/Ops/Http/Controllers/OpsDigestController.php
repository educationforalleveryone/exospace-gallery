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

/**
 * OpsCenter — OpsDigestController (Iteration 7; weekly review in 8).
 *
 * The morning-briefing surface:
 *
 *   GET  /ops/digest       the PREVIEW — the exact message Slack will
 *                          receive, rendered from the same compose() +
 *                          render() pair the scheduled command uses, so
 *                          the preview can never drift from the real
 *                          thing. Read-only → viewer-visible. Iteration
 *                          8: the page also previews the WEEKLY review
 *                          (same preview-is-the-message rule) beneath
 *                          the daily digest.
 *
 *   POST /ops/digest/send  "Send now" — fire the digest immediately.
 *                          Super-admin only (it is an outbound message
 *                          on the operational channel), throttled, and
 *                          audited as ops.digest.sent. Deliberately NOT
 *                          dedup-suppressed: a test send that silently
 *                          disappeared would look exactly like a broken
 *                          webhook. The kill switch does not gate it —
 *                          the switch stops the robot, not the human.
 *
 *   POST /ops/digest/weekly/send — the weekly review's "Send now",
 *                          same contract (super-admin, throttle 5/min,
 *                          audited ops.weekly_review.sent, never
 *                          dedup-suppressed).
 */
class OpsDigestController extends Controller
{
    public function __construct(
        private readonly OpsMorningDigestService $digest,
        private readonly OpsWeeklyReviewService $weekly,
    ) {}

    /**
     * GET /ops/digest — the briefing preview.
     */
    public function index(): View
    {
        // compose() is fail-soft per section by construction; this net
        // only catches a catastrophic total failure (the page then says
        // so honestly instead of 500ing).
        try {
            $composed = $this->digest->compose();
            $text = $this->digest->render($composed);
        } catch (Throwable) {
            $composed = null;
            $text = '';
        }

        // Iteration 8: compose the weekly review preview the same way.
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

            // Iteration 8: the weekly review preview — same fail-soft
            // net, same preview-is-the-message rule as the daily block.
            'weekly' => $weeklyComposed,
            'weeklyText' => $weeklyText,
            'weeklyLastSent' => $this->weekly->lastSent(),
            'weeklyEnabled' => (bool) config('ops.weekly_review.enabled'),
        ]);
    }

    /**
     * POST /ops/digest/send — deliver the digest right now.
     */
    public function sendNow(Request $request): RedirectResponse
    {
        $result = $this->digest->send('manual');

        // Belt-and-braces: send() never throws, but the audit must not
        // die on the (impossible) path where it somehow did.
        try {
            AdminAuditLog::record('ops.digest.sent', $request->user(), [
                'trigger' => 'manual',
                'sections' => (int) ($result['sections'] ?? 0),
            ]);
        } catch (Throwable) {
            // The send already happened; a failed audit row must not
            // turn a successful manual send into an error page.
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

    /**
     * POST /ops/digest/weekly/send — deliver the weekly review right
     * now (Iteration 8). Identical contract to sendNow(): audited as
     * ops.weekly_review.sent, never dedup-suppressed (a vanished test
     * send would look like a broken webhook), never fatal.
     */
    public function sendWeeklyNow(Request $request): RedirectResponse
    {
        $result = $this->weekly->send('manual');

        try {
            AdminAuditLog::record('ops.weekly_review.sent', $request->user(), [
                'trigger' => 'manual',
                'sections' => (int) ($result['sections'] ?? 0),
            ]);
        } catch (Throwable) {
            // The send already happened; a failed audit row must not
            // turn a successful manual send into an error page.
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
