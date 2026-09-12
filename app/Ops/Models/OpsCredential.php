<?php

declare(strict_types=1);

namespace App\Ops\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
