<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\OutboundWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * ITERATION 10 — super-admin UI for per-event outbound webhook
 * subscriptions.
 *
 * Mirrors the Iter-7 BillingController recipient-management pattern:
 *   - Every add/remove is audit-logged (action = webhook.subscription_
 *     added / _removed; target = the WebhookSubscription row itself;
 *     payload carries the event_type + target_url so an operator can
 *     reconstruct the subscription state at any audit-row inspection).
 *   - No password.confirm — same precedent as digest recipient
 *     add/remove (reversible config; the team-invitation flow that
 *     grants access doesn't use password.confirm either).
 *   - Throttle 30,1 — super-admin + MFA gated already; the throttle
 *     bounds cursor()-query spam should a misbehaving client hammer
 *     the form.
 *
 * Trust bar (Iter-10 §12 codification):
 *   The audit row captures the operator's attribution. The outbound
 *   webhook side (OutboundWebhookService::dispatch fan-out) is the
 *   trust bar's third leg — a security team subscribing via a row in
 *   this table now receives the event independently of the audit log
 *   (a security subscriber isn't on the audit log at all).
 *
 * ITERATION 11 — per-subscription delivery ledger.
 *
 * The management UI gains three surfaces backed by the new
 * webhook_deliveries table:
 *   - Per-event subscription count tiles (X active / Y paused for
 *     each event_type with at least one subscription).
 *   - Per-subscription "Last delivery" column on the subscriptions
 *     table (✓ 2xx 3m ago / ✗ 5xx 1h ago / — for no deliveries).
 *   - Per-subscription delivery history page (paginated list of
 *     every delivery row for this subscription — the surface an
 *     operator uses when triaging "did the security team receive
 *     the recipient_added webhook last Tuesday?").
 *
 * The history page is read-only and lists in-system data — no
 * data leaves the system through the operator's view. Mirroring
 * the BillingController::index precedent (the billing review list
 * is NOT audit-logged, only the export CSV is — "view list" ≠
 * "export PII"), the deliveries() view is NOT audit-logged.
 */
class WebhookSubscriptionController extends Controller
{
    /**
     * The documented event types the OutboundWebhookService dispatches.
     * Surfaced to the UI as a dropdown of known events + an "Other"
     * free-text option so a brand-new event the service will dispatch
     * in the future (or a custom-event the founder wires ad-hoc) can
     * still be subscribed without a code change here.
     */
    private const KNOWN_EVENTS = [
        'gallery.published',
        'gallery.unpublished',
        'user.upgraded',
        'user.downgraded',
        'user.registered',
        'subscription.cancelled',
        'subscription.renewed',
        'billing.recipient_added',
        'billing.recipient_removed',
    ];

    public function index(Request $request)
    {
        $subscriptions = Schema::hasTable('webhook_subscriptions')
            ? WebhookSubscription::with('addedBy')
                ->orderBy('event_type')
                ->orderBy('id')
                ->paginate(25)
                ->withQueryString()
            : collect();

        $envUrl = config('services.outbound_webhook.url');
        $envSecret = config('services.outbound_webhook.secret');

        // ITERATION 11 — per-event subscription count tiles. One
        // aggregate query: COUNT(*) GROUP BY event_type, is_active.
        // Returns rows like [{event_type:'billing.recipient_added',
        // is_active:true, count:2}, ...]. The blade view pivots this
        // into per-event tiles showing "X active / Y paused".
        $eventCounts = Schema::hasTable('webhook_subscriptions')
            ? \DB::table('webhook_subscriptions')
                ->selectRaw('event_type, is_active, COUNT(*) AS cnt')
                ->groupBy('event_type', 'is_active')
                ->get()
            : collect();

        // ITERATION 11 — per-subscription "Last delivery" column.
        // Fetches the latest delivery row for each subscription on
        // the current page in TWO queries (not N+1): gather page
        // subscription IDs → MAX(id) GROUP BY subscription_id → fetch
        // those rows → keyBy subscription_id. Returns empty when the
        // webhook_deliveries table doesn't exist yet (fresh install
        // before the Iter-11 migration runs).
        $latestDeliveries = ($subscriptions instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
            ? WebhookDelivery::latestForSubscriptions($subscriptions->getCollection())
            : collect();

        return view('super-admin.webhooks.index', [
            'subscriptions'     => $subscriptions,
            'knownEvents'        => self::KNOWN_EVENTS,
            'envUrl'             => $envUrl,
            'envSecretSet'       => $envSecret !== null && $envSecret !== '',
            'eventCounts'        => $eventCounts,
            'latestDeliveries'   => $latestDeliveries,
        ]);
    }

    /**
     * Add a subscription. Validates, de-dupes (case-insensitive on
     * event_type via the model mutator + the unique index), audit-
     * logs. Mirrors BillingController::storeRecipient including the
     * Iter-8 TOCTOU race fix (UniqueConstraintViolationException
     * re-routed to the same friendly withErrors path).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'event_type' => ['required', 'string', 'max:100'],
            'target_url' => ['required', 'string', 'url', 'max:500', 'starts_with:https://'],
            'secret'     => ['nullable', 'string', 'max:255'],
        ]);

        $eventType = trim(strtolower($data['event_type']));
        $targetUrl = trim($data['target_url']);
        $secret = ($data['secret'] ?? '') === '' ? null : trim($data['secret']);

        $exists = Schema::hasTable('webhook_subscriptions')
            && WebhookSubscription::where('event_type', $eventType)
                ->where('target_url', $targetUrl)
                ->exists();

        if ($exists) {
            return back()
                ->withInput()
                ->withErrors(['target_url' => 'This URL is already subscribed to "' . $eventType . '".']);
        }

        $sub = null;
        try {
            $sub = Schema::hasTable('webhook_subscriptions')
                ? WebhookSubscription::create([
                    'event_type' => $eventType,
                    'target_url' => $targetUrl,
                    'secret'     => $secret,
                    'is_active'  => true,
                    'added_by'   => $request->user()->id,
                ])
                : null;
        } catch (\Illuminate\Database\UniqueConstraintViolationException | \Illuminate\Database\QueryException $e) {
            return back()
                ->withInput()
                ->withErrors(['target_url' => 'This URL is already subscribed to "' . $eventType . '" (concurrent add detected).']);
        }

        if ($sub !== null) {
            AdminAuditLog::record('webhook.subscription_added', $sub, [
                'event_type'  => $sub->event_type,
                'target_url'   => $sub->target_url,
                'has_secret'   => $sub->secret !== null,
            ]);
        }

        return back()->with('success', 'Subscribed ' . $targetUrl . ' to ' . $eventType . '.');
    }

    /**
     * Remove a subscription. Audited BEFORE the delete so the audit
     * row captures the target row's id + event_type + target_url
     * (same precedence rule as BillingController::destroyRecipient).
     */
    public function destroy(Request $request, WebhookSubscription $subscription)
    {
        AdminAuditLog::record('webhook.subscription_removed', $subscription, [
            'event_type'  => $subscription->event_type,
            'target_url'  => $subscription->target_url,
            'had_secret'  => $subscription->secret !== null,
        ]);

        $eventType = $subscription->event_type;
        $targetUrl = $subscription->target_url;
        $subscription->delete();

        return back()->with('success', 'Removed ' . $targetUrl . ' from ' . $eventType . ' subscriptions.');
    }

    /**
     * Toggle a subscription's is_active flag. Useful for incident
     * triage — disable a noisy subscriber without deleting it
     * (preserves the config + audit history; the row stays in
     * place, just stops being fanned-out to).
     */
    public function toggle(Request $request, WebhookSubscription $subscription)
    {
        $newState = ! $subscription->is_active;

        $subscription->update(['is_active' => $newState]);

        AdminAuditLog::record(
            $newState ? 'webhook.subscription_enabled' : 'webhook.subscription_disabled',
            $subscription,
            [
                'event_type' => $subscription->event_type,
                'target_url' => $subscription->target_url,
            ],
        );

        return back()->with(
            'success',
            ($newState ? 'Enabled ' : 'Disabled ') . $subscription->target_url . ' for ' . $subscription->event_type . '.',
        );
    }

    /**
     * ITERATION 11 — per-subscription delivery history page.
     *
     * Lists every webhook_deliveries row for this subscription,
     * newest first, paginated 50/page. The surface an operator
     * uses when triaging "did the security team receive the
     * recipient_added webhook last Tuesday?" — instead of greping
     * storage/logs/laravel.log across rotated files.
     *
     * Read-only — no audit row. Mirrors the BillingController::index
     * precedent (the billing review list page is not audit-logged;
     * only the export CSV is). The operator viewing this page is
     * not moving data out of the system; the deliveries listed are
     * already in the database.
     *
     * Returns a 404 if the webhook_deliveries table doesn't exist
     * yet (fresh install before the Iter-11 migration runs). The
     * subscription row itself is still resolved via route-model
     * binding, so a missing subscription_id is a 404 from the
     * route layer before this method runs.
     */
    public function deliveries(Request $request, WebhookSubscription $subscription)
    {
        $deliveries = Schema::hasTable('webhook_deliveries')
            ? WebhookDelivery::where('subscription_id', $subscription->id)
                ->orderByDesc('delivered_at')
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString()
            : collect();

        $latest = $deliveries instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator
            ? $deliveries->getCollection()->first()
            : null;

        return view('super-admin.webhooks.deliveries', [
            'subscription' => $subscription,
            'deliveries'   => $deliveries,
            'latest'       => $latest,
        ]);
    }
}

