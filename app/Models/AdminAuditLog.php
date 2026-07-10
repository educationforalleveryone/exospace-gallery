<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * App\Models\AdminAuditLog
 *
 * Iteration-010 (audit G-6): The `payload._changed` array now has PII keys
 * (email, customer_email, customer_name, ban_reason, etc.) filtered through
 * a hash before storage. The hash is one-way (sha256 + APP_KEY prefix) —
 * the original PII is gone, but the audit log still records that the field
 * changed (and to a different value than before, since two different emails
 * hash to different values).
 *
 * Why filter at write time:
 *   - Once PII lands in the audit log, it's there forever (audit logs are
 *     append-only for legal reasons). Anonymizing after the fact is messy
 *     and runs into the "we already violated GDPR by storing it" problem.
 *   - Filter-at-write guarantees no new PII enters the audit log.
 *
 * Why hash instead of null:
 *   - A null value loses the "field changed" signal — you can't tell
 *     whether the email was actually changed or just absent.
 *   - A hash preserves the "changed to a new value" signal (two different
 *     emails produce two different hashes) while removing the PII.
 *   - Hashing is one-way with APP_KEY as salt — without APP_KEY, the hash
 *     is irreversible; with APP_KEY, it's still SHA-256 (computationally
 *     infeasible to reverse).
 *
 * The list of PII keys is conservative — we'd rather over-hash than leak.
 * Non-PII fields (plan, max_galleries, is_super_admin, etc.) are stored
 * unchanged.
 *
 * For PII that's ALREADY in old audit log rows (pre-Iter-010), a scheduled
 * scrub command (AnonymizeAuditLogPii, added in Iter-010) hashes them
 * retroactively. Run it once after deploy.
 */
class AdminAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['actor_id', 'action', 'target_type', 'target_id', 'payload', 'ip', 'created_at'];

    protected $casts = ['payload' => 'array', 'created_at' => 'datetime'];

    /**
     * Iter-010 (audit G-6): Fields considered PII in the _changed payload.
     * Values in these keys are replaced with 'pii:' + sha256 hash before
     * storage. Add new keys here as new PII-bearing columns are introduced.
     */
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
        // TD-19 FIX: Auto-capture the target model's changed (dirty) attributes
        // and merge them with the caller-supplied payload. This ensures the
        // audit log always records WHAT changed, even if the developer forgot
        // to pass it explicitly.
        //
        // For example, when SystemController::updatePlan calls:
        //   AdminAuditLog::record('plan_changed', $user, ['from' => $oldPlan, 'to' => $plan]);
        //
        // The auto-captured dirty attributes would include:
        //   { "plan": "pro", "max_galleries": 5, "max_images": 100, "plan_started_at": "..." }
        //
        // These are stored under the `_changed` key in the payload, so they're
        // clearly separated from the caller-supplied context. If the model
        // has no dirty attributes (e.g. a "view" action), `_changed` is omitted.
        //
        // Iter-010 FIX (G-6): PII keys in _changed are hashed (not stored
        // raw). See class docblock for rationale.
        if ($target->exists && $target->isDirty()) {
            $payload['_changed'] = static::scrubPii($target->getDirty());
        }

        // Iter-010 FIX (G-6): Also scrub PII from the caller-supplied payload
        // (some callers pass things like ['email' => $newEmail] explicitly).
        // We do NOT scrub the entire payload — only known PII keys. Other
        // context (e.g. 'from' => 'free', 'to' => 'pro') is preserved as-is.
        $payload = static::scrubPii($payload);

        $log = static::create([
            'actor_id'    => Auth::id(),
            'action'      => $action,
            'target_type' => get_class($target),
            'target_id'   => $target->getKey(),
            'payload'     => $payload ?: null,
            // TD-26 FIX: Use Illuminate\Http\Request::ip() which respects
            // the configured trust proxy / proxy_cidrs. When TRUSTED_PROXIES
            // is configured (set in .env, consumed by TrustProxies middleware),
            // Request::ip() returns the real client IP from the
            // X-Forwarded-For header. When TRUSTED_PROXIES is empty/null
            // (the SEC-1 fail-closed default), Request::ip() returns the
            // direct TCP peer IP, which for a Cloudflare/Coolify deployment
            // is the proxy IP, not the client IP.
            'ip'          => Request::ip(),
            'created_at'  => now(),
        ]);

        // (Task H52 / audit H18) — fire the alert listener for destructive
        // actions. The listener sends an email to all other super-admins.
        event(new \App\Events\AdminAuditLogged($log));
    }

    /**
     * Iter-010 (audit G-6): Scrub PII from an associative array.
     *
     * For each key in PII_KEYS, if the array has that key:
     *   - Replace the value with 'pii:' + substr(sha256(app_key . value), 0, 16)
     *   - If the value is null, leave it null (we don't hash nulls).
     *
     * Non-PII keys are passed through unchanged.
     *
     * This is applied to BOTH the auto-captured `_changed` array AND the
     * caller-supplied payload. The scrubbing is shallow (one level deep)
     * — if a future caller nests PII in a sub-array, this won't catch it.
     * The audit log shape is always flat (top-level keys), so this is fine.
     *
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
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
            // Hash the value. Cast to string in case it's a non-string scalar.
            $data[$key] = 'pii:' . substr(hash('sha256', $appId . (string) $value), 0, 16);
        }

        return $data;
    }

    /**
     * Iter-010 (audit G-6): Check if a given key is on the PII list.
     * Used by the AnonymizeAuditLogPii command to find old audit log rows
     * that need retroactive scrubbing.
     */
    public static function isPiiKey(string $key): bool
    {
        return in_array($key, self::PII_KEYS, true);
    }

    /**
     * Iter-010 (audit G-6): Return the list of PII keys. Used by the
     * AnonymizeAuditLogPii command.
     *
     * @return list<string>
     */
    public static function piiKeys(): array
    {
        return self::PII_KEYS;
    }
}
