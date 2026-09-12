<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use HasFactory;

    /**
 * @var class-string<\Database\Factories\InvoiceFactory>
 */
    protected static string $factory = \Database\Factories\InvoiceFactory::class;

    protected $fillable = [
        'user_id',
        'transaction_id',
        'invoice_number',
        'amount',
        'tax_amount',
        'tax_rate',
        'currency',
        'plan',
        'billing_type',
        'customer_name',
        'customer_email',
        'billing_address',
        'customer_vat_number',
        'supplier_vat_number',
        'tax_country_code',
        'reverse_charge',
        'pdf_path',
        'issued_at',
    ];

    protected $casts = [
        'amount'          => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'tax_rate'        => 'decimal:2',
        'reverse_charge'  => 'boolean',
        'issued_at'       => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function formattedSubtotal(): string
    {
        return number_format((float) $this->amount - (float) $this->tax_amount, 2) . ' ' . $this->currency;
    }

    public function formattedTax(): string
    {
        return number_format((float) $this->tax_amount, 2) . ' ' . $this->currency;
    }

    public function formattedTotal(): string
    {
        return number_format((float) $this->amount, 2) . ' ' . $this->currency;
    }

    public function hasTax(): bool
    {
        return (float) $this->tax_amount > 0 || (bool) $this->reverse_charge;
    }
}
