<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public function pendingUpgrade(): HasOne
    {
        return $this->hasOne(PendingUpgrade::class, 'transaction_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'transaction_id');
    }

    public function formattedAmount(): string
    {
        return number_format((float) $this->amount, 2) . ' ' . $this->currency;
    }
}
