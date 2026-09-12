<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AdminAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['actor_id', 'action', 'target_type', 'target_id', 'payload', 'ip', 'created_at'];

    protected $casts = ['payload' => 'array', 'created_at' => 'datetime'];

    private const PII_KEYS = [
        'email',
        'customer_email',
        'customer_name',
        'billing_address',
        'ban_reason',
        'name',
        'avatar_url',
        'google2fa_secret',
        'mfa_backup_codes',
        'password',
        'remember_token',
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'trial_ends_at',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function record(string $action, Model $target, array $payload = []): void
    {
        if ($target->exists && $target->isDirty()) {
            $payload['_changed'] = static::scrubPii($target->getDirty());
        }

        $payload = static::scrubPii($payload);

        $log = static::create([
            'actor_id'    => Auth::id(),
            'action'      => $action,
            'target_type' => get_class($target),
            'target_id'   => $target->getKey(),
            'payload'     => $payload ?: null,
            'ip'          => Request::ip(),
            'created_at'  => now(),
        ]);

        event(new \App\Events\AdminAuditLogged($log));
    }

    public static function scrubPii(array $data): array
    {
        $appId = config('app.key');

        foreach (self::PII_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if ($value === null || $value === '') {
                // Keep null/empty as-is — no PII to scrub.
                continue;
            }
            if (is_string($value) && str_starts_with($value, 'pii:')) {
                continue;
            }
            // Hash the value. Cast to string in case it's a non-string scalar.
            $data[$key] = 'pii:' . substr(hash('sha256', $appId . (string) $value), 0, 16);
        }

        return $data;
    }

    public static function isPiiKey(string $key): bool
    {
        return in_array($key, self::PII_KEYS, true);
    }

    public static function piiKeys(): array
    {
        return self::PII_KEYS;
    }
}
