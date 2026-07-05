<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * App\Models\Transaction
 *
 * Records a successful 2Checkout payment. Created by
 * WebhookController::handle2Checkout on ORDER_CREATED, updated by
 * applyRefund / applyChargeback on the corresponding INS message types.
 *
 * The `invoice_id` column has a unique index — 2Checkout invoice IDs
 * are globally unique and used as the idempotency key for the webhook.
 */
class Transaction extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'invoice_id',
        'sale_id',
        'product_id',
        'plan',
        'amount',
        'currency',
        'customer_email',
        'customer_name',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The pending_upgrade that was converted by this transaction.
     *
     * P2-10 FIX: Previously defined as belongsTo(PendingUpgrade::class),
     * which expected a `transactions.pending_upgrade_id` column that
     * doesn't exist. The FK is on `pending_upgrades.transaction_id`
     * (the inverse direction), so this should be hasOne.
     */
    public function pendingUpgrade(): HasOne
    {
        return $this->hasOne(PendingUpgrade::class, 'transaction_id');
    }

    /**
     * Format the amount with currency for display.
     * E.g. "29.00 USD", "99.00 USD".
     */
    public function formattedAmount(): string
    {
        return number_format((float) $this->amount, 2) . ' ' . $this->currency;
    }
}
