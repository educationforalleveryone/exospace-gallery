<?php

declare(strict_types=1);

namespace App\Ops\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpsApplication extends Model
{
    protected $table = 'ops_applications';

    protected $fillable = [
        'slug', 'name', 'provider', 'provider_uuid', 'kind', 'environment',
        'url', 'sentry_project_slug', 'status', 'health', 'status_checked_at', 'last_seen_at',
        'meta', 'is_self',
    ];

    protected $casts = [
        'meta' => 'array',
        'status_checked_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'is_self' => 'boolean',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(OpsEvent::class);
    }

    public static function deriveHealth(?string $status): string
    {
        $status = strtolower((string) $status);

        if ($status === '' || $status === 'unknown') {
            return 'unknown';
        }

        if (str_starts_with($status, 'running')) {
            return str_contains($status, 'unhealthy') ? 'degraded' : 'running';
        }

        if (in_array($status, ['restarting', 'starting', 'degrading'], true)) {
            return 'degraded';
        }

        if (str_starts_with($status, 'exited') || $status === 'stopped') {
            return 'stopped';
        }

        return 'unknown';
    }

    public function healthLabel(): string
    {
        return match ($this->health) {
            'running' => 'Running',
            'degraded' => 'Degraded',
            'stopped' => 'Stopped',
            default => 'Unknown',
        };
    }
}
