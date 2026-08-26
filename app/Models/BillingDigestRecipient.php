<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ITERATION 7 — one row per recipient of the weekly billing digest.
 *
 * Recipients are managed on Master Control → Billing Review. DB rows
 * take precedence over BILLING_EXPORT_EMAIL when non-empty; an empty
 * list falls back to the env var (see SendBillingExport::resolveRecipients
 * + config('services.billing_export.email')).
 */
class BillingDigestRecipient extends Model
{
    protected $table = 'billing_digest_recipients';

    protected $fillable = ['email', 'added_by'];

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /**
     * Normalize emails on write so duplicates can't slip past a
     * case-insensitive comparison (MySQL is ci by default, SQLite is
     * case-sensitive — the unique index would behave differently
     * across drivers without this normalization).
     */
    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = trim(strtolower($value));
    }
}
