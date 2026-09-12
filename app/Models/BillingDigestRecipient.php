<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingDigestRecipient extends Model
{
    protected $table = 'billing_digest_recipients';

    protected $fillable = ['email', 'added_by'];

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = trim(strtolower($value));
    }
}
