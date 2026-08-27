<?php

declare(strict_types=1);

namespace App\Ops\Support;

use App\Models\User;
use App\Ops\Models\OpsAccessGrant;

/**
 * OpsCenter — OpsAccessContext (Iteration 6).
 *
 * Resolves "what can the current user do in /ops" for the VIEW layer:
 * one lightweight per-request cache (one grant lookup per request, not
 * one per template line), fail-closed by construction.
 *
 * The memo lives as a CONTAINER-BOUND instance, not a PHP static: under
 * classic PHP-FPM the container dies with the request, so the cache is
 * request-scoped by construction (and under the test runner it resets
 * with each app rebuild — no cross-test leakage).
 *
 * Levels returned:
 *   'super_admin' — is_super_admin on the account (full control).
 *   'operator'    — active operator grant: read + RUN read-only diagnostics.
 *   'viewer'      — active viewer grant: read-only.
 *   null          — no access at all (the middleware would have 403'd
 *                   already; templates still render defensively).
 *
 * Why a helper at all: Iteration 6 splits "who may RUN a diagnostic"
 * (super-admins + operators) from "who may touch infrastructure"
 * (super-admins only). Templates that previously asked a single
 * is_super_admin question now need the tier distinction, and the answer
 * must be identical everywhere — one resolver, one cache, one truth.
 *
 * Middleware remains the enforcement (route level); this class is only
 * for RENDERING the right buttons to the right people.
 */
class OpsAccessContext
{
    /** Container binding for the per-request memo: user id → level. */
    private const MEMO = 'ops.access.context.memo';

    /**
     * The user's effective OpsCenter tier for rendering decisions.
     */
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

    /**
     * May this user RUN read-only diagnostics (the POST
     * /ops/diagnostics/run surface)? Super-admins and operator-tier
     * grantees — mirrors EnsureOpsOperator exactly.
     */
    public static function canRunDiagnostics(?User $user): bool
    {
        $level = self::level($user);

        return $level === 'super_admin' || $level === OpsAccessGrant::LEVEL_OPERATOR;
    }

    /**
     * May this user touch infrastructure / governance surfaces (Actions
     * hub, credentials, access management, incident lifecycle, sync,
     * restarts)? Super-admins only — mirrors the nested 'super_admin'
     * route group exactly.
     */
    public static function isOperator(?User $user): bool
    {
        return self::level($user) === 'super_admin';
    }

    /**
     * Reset the per-request memo (explicit invalidation — e.g. tests, or
     * a grant change rendered later in the same request).
     */
    public static function flush(): void
    {
        if (app()->bound(self::MEMO)) {
            app()->forgetInstance(self::MEMO);
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, ?string>
     */
    private static function memo(): \Illuminate\Support\Collection
    {
        if (! app()->bound(self::MEMO)) {
            app()->instance(self::MEMO, collect());
        }

        /** @var \Illuminate\Support\Collection<int, ?string> */
        return app(self::MEMO);
    }
}
