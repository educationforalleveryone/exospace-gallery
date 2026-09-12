<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OnboardingSnapshot extends Model
{
    public $timestamps = false;

    protected $table = 'onboarding_snapshots';

    protected $fillable = [
        'window_days',
        'registered',
        'created_gallery',
        'uploaded_image',
        'published',
        'got_views',
        'ttfg_min',
        'ttfg_avg',
        'ttfg_max',
        'ttfe_min',
        'ttfe_avg',
        'ttfe_max',
        'captured_at',
    ];

    protected $casts = [
        'window_days'     => 'integer',
        'registered'      => 'integer',
        'created_gallery' => 'integer',
        'uploaded_image'  => 'integer',
        'published'       => 'integer',
        'got_views'       => 'integer',
        'ttfg_min'        => 'float',
        'ttfg_avg'        => 'float',
        'ttfg_max'        => 'float',
        'ttfe_min'        => 'float',
        'ttfe_avg'        => 'float',
        'ttfe_max'        => 'float',
        'captured_at'     => 'datetime',
    ];

    public function scopeTrend(Builder $q, int $windowDays, int $limit = 26): Builder
    {
        return $q->where('window_days', $windowDays)
            ->orderBy('captured_at')
            ->limit(max(1, min(156, $limit)));
    }
}
