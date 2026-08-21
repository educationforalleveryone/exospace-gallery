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
     * The Invoice generated for this transaction (if any).
     *
     * AUDIT-P0-1.6 FIX: Previously the billing portal queried
     * `Invoice::where('transaction_id', $tx->id)->first()` inside a foreach
     * loop — an N+1 query that fired once per row on the transactions table.
     * Defining this relationship lets the controller eager-load via
     * `$user->transactions()->with('invoice')->paginate(20)` and the view
     * read `$tx->invoice` directly.
     *
     * NOTE: `invoices.transaction_id` is NOT a database FK — the `transactions`
     * table is partitioned by month, and MySQL InnoDB forbids FKs targeting
     * partitioned tables (error 1506). Referential integrity is enforced at
     * the application layer (InvoiceGenerator creates the Invoice inside a
     * DB::transaction with `lockForUpdate` on the parent Transaction row).
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'transaction_id');
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
