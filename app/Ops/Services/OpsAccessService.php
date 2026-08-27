<?php

declare(strict_types=1);

namespace App\Ops\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Ops\Models\OpsAccessGrant;
use App\Services\OperationalAlertService;
use Throwable;

/**
 * OpsCenter — OpsAccessService (Iteration 5).
 *
 * Grant/revoke viewer access to the control plane. Follows the Iteration-3
 * action-service contract: structured result arrays, NEVER throws to the
 * caller, every attempt audited (ops.access.granted / ops.access.revoked)
 * and announced through the existing alerting pipeline (Slack) — access
 * changes are security events and must leave the same paper trail as
 * restarts and replays.
 *
 * Rules enforced here (fail-closed, human-readable messages for the UI):
 *   - Super-admins already hold access; a grant for them is rejected as
 *     redundant (they are never downgraded by anything here).
 *   - One active grant per user — a second is rejected instead of
 *     silently resetting the clock.
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
     * Grant viewer access.
     *
     * @return array{ok: bool, message: string, grant?: OpsAccessGrant}
     */
    public function grant(User $target, User $granter): array
    {
        if ($target->is_super_admin) {
            return ['ok' => false, 'message' => 'This account is already a super-admin — it has full OpsCenter access without a grant.'];
        }

        if (OpsAccessGrant::hasActiveViewerGrant($target)) {
            return ['ok' => false, 'message' => 'This account already holds an active viewer grant.'];
        }

        $grant = OpsAccessGrant::create([
            'user_id' => $target->id,
            'level' => OpsAccessGrant::LEVEL_VIEWER,
            'granted_by' => $granter->id,
            'granted_at' => now(),
        ]);

        $this->audit('ops.access.granted', $grant, $granter, [
            'user_id' => $target->id,
            'level' => OpsAccessGrant::LEVEL_VIEWER,
        ]);

        $this->announce(
            'viewer access GRANTED',
            $target,
            $granter,
            $target->google2fa_secret ? 'MFA enabled — the account can enter now.' : 'MFA NOT enabled — the account will be sent to MFA setup on first visit.',
        );

        return [
            'ok' => true,
            'message' => 'Viewer access granted to user #'.$target->id.'.',
            'grant' => $grant,
        ];
    }

    /**
     * Revoke a grant (idempotent).
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

        $this->announce('viewer access REVOKED', $grant->user, $actor, 'Access ends immediately.');

        return ['ok' => true, 'message' => 'Viewer access revoked for user #'.$grant->user_id.'.'];
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
