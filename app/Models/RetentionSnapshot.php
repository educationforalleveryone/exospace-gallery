<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RetentionSnapshot extends Model
{
    public $timestamps = false;

    protected $table = 'retention_snapshots';

    protected $fillable = [
        'cohort_week_start',
        'week_index',
        'cohort_size',
        'active_count',
        'retained_pct',
        'captured_at',
    ];

    protected $casts = [
        'cohort_week_start' => 'date:Y-m-d',
        'week_index'        => 'integer',
        'cohort_size'       => 'integer',
        'active_count'      => 'integer',
        'retained_pct'      => 'float',
        'captured_at'       => 'datetime',
    ];

    public function scopeTrend(Builder $q, int $weekIndex, int $limit = 26): Builder
    {
        return $q->where('week_index', max(0, min(255, $weekIndex)))
            ->orderByDesc('captured_at')
            ->limit(max(1, min(156, $limit)));
    }
}
