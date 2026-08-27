<?php

declare(strict_types=1);

namespace App\Ops\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Ops\Models\OpsAccessGrant;
use App\Services\OperationalAlertService;
use Throwable;

/**
 * OpsCenter — OpsAccessService (Iteration 5; levels in Iteration 6).
 *
 * Grant/revoke access to the control plane. Follows the Iteration-3
 * action-service contract: structured result arrays, NEVER throws to the
 * caller, every attempt audited (ops.access.granted / ops.access.revoked)
 * and announced through the existing alerting pipeline (Slack) — access
 * changes are security events and must leave the same paper trail as
 * restarts and replays.
 *
 * Two tiers since Iteration 6:
 *   viewer   — read-only (Iteration 5 behavior, unchanged).
 *   operator — read + run read-only diagnostics (the POST
 *              /ops/diagnostics/run surface, guarded by EnsureOpsOperator
 *              at the ROUTE level — never the Actions hub, credentials
 *              or access management).
 *
 * Rules enforced here (fail-closed, human-readable messages for the UI):
 *   - Super-admins already hold access; a grant for them is rejected as
 *     redundant (they are never downgraded by anything here).
 *   - One active grant per user. Granting the SAME level again is
 *     rejected; granting a DIFFERENT level is a LEVEL CHANGE: the old
 *     grant is revoked and a new one created in one operation — the
 *     ledger keeps both rows, so "who had what, when" is always
 *     answerable and the transition never leaves the account without
 *     access OR with two grants.
 *   - Revoke is idempotent: revoking an already-revoked grant reports
 *     "nothing to do" instead of erroring.
 *   - Grants never modify the USER row (no flags, no role columns) —
 *     deleting the grant row's active window is the whole mechanism.
 */
class OpsAccessService
{
    public function __construct(
        private readonly OperationalAlertService $alerts,
    ) {}

    /**
     * Grant access at a level ('viewer' default — the Iteration-5 call
     * signature keeps working untouched).
     *
     * @param  string  $level  OpsAccessGrant::LEVEL_*. An active grant at a
     *                         different level triggers the atomic level-change
     *                         path (revoke old + grant new, both audited).
     * @return array{ok: bool, message: string, grant?: OpsAccessGrant}
     */
    public function grant(User $target, User $granter, string $level = OpsAccessGrant::LEVEL_VIEWER): array
    {
        if (! in_array($level, OpsAccessGrant::LEVELS, true)) {
            return ['ok' => false, 'message' => 'Unknown access level — the tier is not enabled on this deployment.'];
        }

        if ($target->is_super_admin) {
            return ['ok' => false, 'message' => 'This account is already a super-admin — it has full OpsCenter access without a grant.'];
        }

        $existing = OpsAccessGrant::query()
            ->active()
            ->whereIn('level', OpsAccessGrant::LEVELS)
            ->where('user_id', $target->id)
            ->latest('id')
            ->first();

        if ($existing !== null && $existing->level === $level) {
            return ['ok' => false, 'message' => 'This account already holds an active '.$level.' grant.'];
        }

        // Level change: close the old grant first (its own audit row keeps
        // the "why" — the payload records the tier transition), then grant
        // the new one. Two ledger rows, zero windows with 0 or 2 grants.
        if ($existing !== null) {
            $existing->forceFill(['revoked_at' => now()])->save();

            $this->audit('ops.access.revoked', $existing, $granter, [
                'user_id' => $target->id,
                'reason' => 'level_change',
                'from_level' => $existing->level,
                'to_level' => $level,
            ]);
        }

        $grant = OpsAccessGrant::create([
            'user_id' => $target->id,
            'level' => $level,
            'granted_by' => $granter->id,
            'granted_at' => now(),
        ]);

        $this->audit('ops.access.granted', $grant, $granter, [
            'user_id' => $target->id,
            'level' => $level,
        ]);

        if ($existing !== null) {
            $this->announce(
                'access level CHANGED',
                $target,
                $granter,
                sprintf(
                    '%s → %s. %s',
                    $existing->level,
                    $level,
                    $level === OpsAccessGrant::LEVEL_OPERATOR
                        ? 'The account can now also run read-only diagnostics.'
                        : 'The account is read-only again (diagnostics runs removed).',
                ),
            );
        } else {
            $this->announce(
                $level.' access GRANTED',
                $target,
                $granter,
                $target->google2fa_secret
                    ? 'MFA enabled — the account can enter now.'
                    : 'MFA NOT enabled — the account will be sent to MFA setup on first visit.',
            );
        }

        $message = $existing !== null
            ? sprintf('Access level changed to %s for user #%d.', $level, $target->id)
            : sprintf('%s access granted to user #%d.', ucfirst($level), $target->id);

        return [
            'ok' => true,
            'message' => $message,
            'grant' => $grant,
        ];
    }

    /**
     * Revoke a grant (idempotent) — any level.
     *
     * @return array{ok: bool, message: string}
     */
    public function revoke(OpsAccessGrant $grant, User $actor): array
    {
        if ($grant->revoked_at !== null) {
            return ['ok' => false, 'message' => 'This grant was already revoked.'];
        }

        $grant->forceFill(['revoked_at' => now()])->save();

        $this->audit('ops.access.revoked', $grant, $actor, [
            'user_id' => $grant->user_id,
        ]);

        $this->announce($grant->level.' access REVOKED', $grant->user, $actor, 'Access ends immediately.');

        return ['ok' => true, 'message' => ucfirst($grant->level).' access revoked for user #'.$grant->user_id.'.'];
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * Audit with the grant as target. Payload deliberately carries user
     * IDs only — no emails or names (AdminAuditLog hashes PII keys, and
     * ops_events-style surfaces have no scrubber at all; IDs identify
     * precisely without disclosing anything).
     *
     * @param  array<string, mixed>  $payload
     */
    private function audit(string $action, OpsAccessGrant $grant, User $actor, array $payload): void
    {
        try {
            AdminAuditLog::record($action, $grant, $payload);
        } catch (Throwable) {
            // The ledger must never take the management flow down.
        }
    }

    private function announce(string $change, ?User $target, User $actor, string $detail): void
    {
        try {
            $this->alerts->alert(
                'OpsCenter access change: '.$change,
                sprintf(
                    "operator #%d %s for user #%s — %s",
                    $actor->id,
                    strtolower($change),
                    $target?->id ?? '?',
                    $detail,
                ),
                'info',
            );
        } catch (Throwable) {
            // Alerting must never take the management flow down.
        }
    }
}
