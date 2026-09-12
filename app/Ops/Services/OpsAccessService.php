<?php

declare(strict_types=1);

namespace App\Ops\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Ops\Models\OpsAccessGrant;
use App\Services\OperationalAlertService;
use Throwable;

class OpsAccessService
{
    public function __construct(
        private readonly OperationalAlertService $alerts,
    ) {}

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
