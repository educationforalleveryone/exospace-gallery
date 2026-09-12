<?php

declare(strict_types=1);

namespace App\Ops\Support;

use App\Models\User;
use App\Ops\Models\OpsAccessGrant;

class OpsAccessContext
{
    private const MEMO = 'ops.access.context.memo';

    public static function level(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $memo = self::memo();
        $userId = (int) $user->id;

        if ($memo->has($userId)) {
            return $memo->get($userId);
        }

        if ($user->is_super_admin) {
            return $memo->put($userId, 'super_admin')->get($userId);
        }

        $level = OpsAccessGrant::activeLevelFor($user);

        return $memo->put($userId, $level)->get($userId);
    }

    public static function canRunDiagnostics(?User $user): bool
    {
        $level = self::level($user);

        return $level === 'super_admin' || $level === OpsAccessGrant::LEVEL_OPERATOR;
    }

    public static function isOperator(?User $user): bool
    {
        return self::level($user) === 'super_admin';
    }

    public static function flush(): void
    {
        if (app()->bound(self::MEMO)) {
            app()->forgetInstance(self::MEMO);
        }
    }

    private static function memo(): \Illuminate\Support\Collection
    {
        if (! app()->bound(self::MEMO)) {
            app()->instance(self::MEMO, collect());
        }

        /**
 * @var \Illuminate\Support\Collection<int, ?string>
 */
        return app(self::MEMO);
    }
}
