<?php

declare(strict_types=1);

namespace App\Ops\Models;

use Illuminate\Database\Eloquent\Model;

class OpsReviewSnapshot extends Model
{
    protected $table = 'ops_review_snapshots';

    // created_at only — snapshots are immutable point-in-time facts.
    public $timestamps = false;

    protected $fillable = [
        'week_start', 'week_end', 'trigger', 'metrics', 'created_at',
    ];

    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'metrics' => 'array',
        'created_at' => 'datetime',
    ];
}
