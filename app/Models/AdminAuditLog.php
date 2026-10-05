<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        'admin_email',
        'target_email',
        'actor_admin_email',
        'previous_email',
        'new_email',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            $log->chain_hash = $log->computeChainHash();
        });
    }

    public static function record(string $action, Model $target, array $payload = [], ?int $actorId = null): void
    {
        if ($target->exists && $target->isDirty()) {
            $payload['_changed'] = static::scrubPii($target->getDirty());
        }

        $payload = static::scrubPii($payload);

        $log = DB::transaction(function () use ($action, $target, $payload, $actorId) {
            // Serialize concurrent audit writers on the chain tail so each row
            // links against the latest committed hash (locking reads always
            // see the newest committed row).
            static::query()->orderByDesc('id')->lockForUpdate()->first(['id']);

            return static::create([
                'actor_id' => $actorId ?? Auth::id(),
                'action' => $action,
                'target_type' => get_class($target),
                'target_id' => $target->getKey(),
                'payload' => $payload ?: null,
                'ip' => Request::ip(),
                'created_at' => now(),
            ]);
        });

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
            $data[$key] = 'pii:'.substr(hash('sha256', $appId.(string) $value), 0, 16);
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

    /**
     * Recompute the hash chain over all stored rows and return the number of
     * rows whose stored chain_hash does not match the recomputed value — i.e.
     * rows that were modified, forged, or reordered after insert. Rows predating
     * the chain (null chain_hash) are skipped; the chain restarts at the first
     * hashed row.
     */
    public static function verifyChain(): int
    {
        $broken = 0;
        $previousHash = null;

        static::query()
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$broken, &$previousHash) {
                foreach ($rows as $row) {
                    if ($row->chain_hash === null) {
                        continue;
                    }

                    $expected = static::expectedChainHash($row->getAttributes(), $previousHash);

                    if (! hash_equals($expected, $row->chain_hash)) {
                        $broken++;
                    }

                    // Successors chain from the stored hash regardless — a
                    // tampered row must not cascade-fail intact successors.
                    $previousHash = $row->chain_hash;
                }
            });

        return $broken;
    }

    private function computeChainHash(): string
    {
        $previousHash = static::query()->orderByDesc('id')->value('chain_hash');

        return static::expectedChainHash($this->getAttributes(), $previousHash);
    }

    private static function expectedChainHash(array $rawAttributes, ?string $previousHash): string
    {
        $canonical = implode('|', [
            $previousHash ?? 'genesis',
            (string) ($rawAttributes['actor_id'] ?? ''),
            (string) ($rawAttributes['action'] ?? ''),
            (string) ($rawAttributes['target_type'] ?? ''),
            (string) ($rawAttributes['target_id'] ?? ''),
            (string) ($rawAttributes['payload'] ?? ''),
            (string) ($rawAttributes['ip'] ?? ''),
            (string) ($rawAttributes['created_at'] ?? ''),
        ]);

        return hash_hmac('sha256', $canonical, (string) config('app.key'));
    }
}
