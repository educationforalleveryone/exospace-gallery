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
            'ip'          => Request::ip(),
            'created_at'  => now(),
        ]);

        // (Task H52 / audit H18) — fire the alert listener for destructive
        // actions. The listener sends an email to all other super-admins.
        event(new \App\Events\AdminAuditLogged($log));
    }
}
