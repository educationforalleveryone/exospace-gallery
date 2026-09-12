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

class WebhookSubscriptionController extends Controller
{
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

        $eventCounts = Schema::hasTable('webhook_subscriptions')
            ? \DB::table('webhook_subscriptions')
                ->selectRaw('event_type, is_active, COUNT(*) AS cnt')
                ->groupBy('event_type', 'is_active')
                ->get()
            : collect();

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

