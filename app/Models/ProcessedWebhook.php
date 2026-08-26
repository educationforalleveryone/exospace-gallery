<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\ProcessedWebhook
 *
 * ITERATION 4 — the 2Checkout IPN ledger. Historically this table was a
 * pure dedupe marker (message_id + message_type, delete-on-failure), which
 * made billing disputes unanswerable from our side: the raw payload was
 * never stored, so "what exactly did 2Checkout send us when they claimed
 * the refund?" required a support ticket to 2Checkout.
 *
 * Rows now carry the full payload (retention: 90 days, pruned by
 * exospace:cleanup-stale — GDPR proportionality for the PII inside) and a
 * status lifecycle:
 *
 *   'processing' → 'processed'   success
 *   'processing' → 'failed'      exception (payload kept; retry/replay can
 *                                 re-claim the row — replaces Iteration-1's
 *                                 delete-on-failure marker)
 *
 * The WebhookController still writes via DB::table (its ingress paths
 * predate the model and deliberately avoid model events); this model exists
 * for read paths (super-admin billing review) and as the audit-log target
 * for admin replays.
 */
class ProcessedWebhook extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'message_id',
        'message_type',
        'invoice_id',
        'payload',
        'status',
        'processed_at',
        'replay_count',
        'last_replayed_at',
        'updated_at',
    ];

    protected $casts = [
        'payload'         => 'array',
        'processed_at'    => 'datetime',
        'last_replayed_at'=> 'datetime',
        'updated_at'      => 'datetime',
    ];
}
