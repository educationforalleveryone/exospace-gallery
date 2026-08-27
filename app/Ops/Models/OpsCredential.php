<?php

declare(strict_types=1);

namespace App\Ops\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Ops\Models\OpsCredential
 *
 * A rotation-ledger row for ONE catalog credential (Iteration 5). Created
 * lazily — the row exists only after the first recorded rotation; before
 * that the inventory service reports "never rotated" from the catalog
 * alone.
 *
 * This model can never hold a secret VALUE by construction: the schema has
 * no column for one (key, last_rotated_at, rotated_by, notes only).
 */
class OpsCredential extends Model
{
    protected $table = 'ops_credentials';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = ['key', 'last_rotated_at', 'rotated_by', 'notes'];

    protected $casts = [
        'last_rotated_at' => 'datetime',
    ];

    public function rotatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rotated_by');
    }
}
