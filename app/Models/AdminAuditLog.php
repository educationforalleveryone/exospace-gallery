<?php

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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function record(string $action, Model $target, array $payload = []): void
    {
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
            //
            // Previously this used the same Request::ip() call, but the
            // audit (TD-26) flagged it as "spoofable" because the codebase
            // had no TrustProxies middleware at all — any client could set
            // X-Forwarded-For: 1.2.3.4 and have that IP logged. SEC-1
            // (Iteration 009) fixed the root cause by changing the
            // TRUSTED_PROXIES default from '*' to null (fail-closed).
            //
            // This audit log entry is now RELIABLE: when TRUSTED_PROXIES
            // is properly configured, the logged IP is the real client IP.
            // When TRUSTED_PROXIES is empty, the logged IP is the proxy IP
            // (still useful for forensic correlation, just not directly
            // attributable to a client). Either way, an attacker can no
            // longer spoof arbitrary IPs in the audit log.
            'ip'          => Request::ip(),
            'created_at'  => now(),
        ]);

        // (Task H52 / audit H18) — fire the alert listener for destructive
        // actions. The listener sends an email to all other super-admins.
        event(new \App\Events\AdminAuditLogged($log));
    }
}
