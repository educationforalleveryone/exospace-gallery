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

        // target_id backs a BIGINT morph column. Models with string primary
        // keys (e.g. OpsCredential keyed by 'db-password') cannot go in there:
        // MySQL strict mode rejects the value outright, which lost the audit
        // row entirely. Keep the identifying key in the payload instead.
        $targetKey = $target->getKey();
        if (! is_numeric($targetKey)) {
            $payload['_target_key'] = (string) $targetKey;
            $targetKey = null;
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
                'target_id' => $targetKey,
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

                    // Rows written before the canonical-payload scheme verify
                    // against the raw stored payload string; on MySQL a JSON
                    // column re-serialises that string on storage, so those
                    // rows are only verifiable through the legacy scheme.
                    if (! hash_equals($expected, $row->chain_hash)
                        && ! hash_equals(static::legacyExpectedChainHash($row->getAttributes(), $previousHash), $row->chain_hash)) {
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

    /**
     * The row hash. The payload is hashed in a canonical (key-sorted) JSON
     * form: MySQL re-serialises JSON columns on storage (reordering keys and
     * normalising spacing), so hashing the raw stored string would mark every
     * payload row as tampered on every fresh MySQL database.
     */
    private static function expectedChainHash(array $rawAttributes, ?string $previousHash): string
    {
        $canonical = implode('|', [
            $previousHash ?? 'genesis',
            (string) ($rawAttributes['actor_id'] ?? ''),
            (string) ($rawAttributes['action'] ?? ''),
            (string) ($rawAttributes['target_type'] ?? ''),
            (string) ($rawAttributes['target_id'] ?? ''),
            static::canonicalPayload((string) ($rawAttributes['payload'] ?? '')),
            (string) ($rawAttributes['ip'] ?? ''),
            (string) ($rawAttributes['created_at'] ?? ''),
        ]);

        return hash_hmac('sha256', $canonical, (string) config('app.key'));
    }

    /**
     * The pre-canonicalisation scheme: the payload member was the raw stored
     * string, exactly as the engine returned it. Kept so rows written before
     * the canonical scheme (on engines that store JSON verbatim) still verify.
     */
    private static function legacyExpectedChainHash(array $rawAttributes, ?string $previousHash): string
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

    private static function canonicalPayload(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return $raw; // not JSON (legacy/edge values) — hash as-is
        }

        return (string) json_encode(
            json_canonical($decoded),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
