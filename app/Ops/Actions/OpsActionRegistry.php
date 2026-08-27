<?php

declare(strict_types=1);

namespace App\Ops\Actions;

/**
 * OpsCenter — OpsActionRegistry (Iteration 3).
 *
 * THE allow-list for every operation that changes state outside OpsCenter's
 * own records. If an action is not declared here, it does not exist — there
 * is no free-form action surface, no command passthrough, no SQL, no docker
 * exec. Ever.
 *
 * Risk tiers (the brief's ACTION MODEL, made explicit):
 *   none      — no external state change beyond a refresh (still throttled
 *               and audited): platform.sync.
 *   elevated  — changes infrastructure or re-applies an external event:
 *               requires password + typed confirmation phrase + audit +
 *               Slack announcement: app.restart, webhook.replay,
 *               queue.retry, queue.forget (Iteration 10).
 *
 * DANGEROUS operations (stop application, drop database, run migrations,
 * delete backups, wipe queues) are DELIBERATELY NOT on this list. They stay
 * in Coolify / the deploy pipeline where their full context lives. This is
 * a conscious scope decision documented in the master manual. The queue
 * actions are the scoped exception to "wipe queues": ONE job at a time,
 * never a bulk "retry all" or "flush" — the mass variants stay out exactly
 * because their blast radius is the whole queue.
 */
final class OpsActionRegistry
{
    public const RISK_NONE = 'none';

    public const RISK_ELEVATED = 'elevated';

    /**
     * @var array<string, array{
     *     label: string, group: string, risk: string, description: string,
     *     will_do: string[], wont_do: string[], consequence: string,
     *     confirmation_phrase: ?string, requires_password: bool
     * }>
     */
    private const ACTIONS = [
        'platform.sync' => [
            'label' => 'Refresh platform data now',
            'group' => 'Read-refresh',
            'risk' => self::RISK_NONE,
            'description' => 'Runs the same 5-minute Coolify sync immediately: applications, databases, services, servers and recent deployments.',
            'will_do' => [
                'Pull the current platform state from the Coolify API',
                'Create events for new failures found (deduplicated as usual)',
            ],
            'wont_do' => [
                'Change any container, application or configuration',
                'Restart anything',
            ],
            'consequence' => 'None — this is the scheduled sync on demand.',
            'confirmation_phrase' => null,
            'requires_password' => false,
        ],

        'app.restart' => [
            'label' => 'Restart application container',
            'group' => 'Infrastructure',
            'risk' => self::RISK_ELEVATED,
            'description' => 'Stop the current container and start a new one from the SAME image via the Coolify API. No rebuild, no code change.',
            'will_do' => [
                'Ask Coolify to stop the current container of the selected application',
                'Start a fresh container from the same image and configuration',
                'Cause a brief service interruption (typically well under a minute) while the container cycles',
                'Record an audit-log entry and announce the action in the ops Slack channel',
                'Create a control-plane event so the restart appears in timelines',
            ],
            'wont_do' => [
                'Rebuild the image or deploy new code (that is Coolify\'s deploy job — this uses the same image)',
                'Run migrations or change the database',
                'Change environment variables or configuration',
                'Fix the underlying cause if the application crashes again after restart',
            ],
            'consequence' => 'Brief downtime for this application while the container restarts. In-flight requests may fail. If the container is crash-looping because of a code/config problem, it will come back just as broken — the restart buys a clean process, not a fix.',
            'confirmation_phrase' => 'RESTART',
            'requires_password' => true,
        ],

        'webhook.replay' => [
            'label' => 'Replay billing webhook',
            'group' => 'Billing',
            'risk' => self::RISK_ELEVATED,
            'description' => 'Re-dispatch one stored 2Checkout IPN payload through the exact same processing pipeline the live ingress uses (the Master Control replay path — reused, not duplicated).',
            'will_do' => [
                'Re-run the stored webhook payload through the live processing pipeline',
                'Apply the billing effect the webhook carries (activate, downgrade, refund…) if it was not applied',
                'Increment the ledger row\'s replay counter and mark it processed/failed by the outcome',
                'Record an audit-log entry and announce the action in the ops Slack channel',
            ],
            'wont_do' => [
                'Re-verify the signature (the stored bytes were verified at ingress — replay trusts the ledger)',
                'Re-run dedupe (the replay is deliberate; the handlers\' own idempotency guards apply)',
                'Fetch anything new from 2Checkout — it processes only the stored payload',
            ],
            'consequence' => 'The billing effect is re-attempted. Handlers are idempotent per invoice, but if the original application partially succeeded, replay can double-apply the non-idempotent edge of an effect. Replay the specific webhook only when its event genuinely failed.',
            'confirmation_phrase' => 'REPLAY',
            'requires_password' => true,
        ],

        // ── Iteration 10 — the queue lifecycle ───────────────────────────
        // The failed-jobs diagnostic used to end its guidance with "retry
        // deliberately (php artisan queue:retry from a terminal)" — the
        // last workflow in the platform that pointed at a terminal. These
        // two actions close it: one job at a time, through the same
        // four-layer security model as every other elevated action.

        'queue.retry' => [
            'label' => 'Retry a failed queue job',
            'group' => 'Queue',
            'risk' => self::RISK_ELEVATED,
            'description' => 'Push ONE failed job back onto its queue (Laravel\'s own queue:retry path — reused, not duplicated). The job runs again with its retry counter reset.',
            'will_do' => [
                'Push the stored payload back onto the queue and connection the job originally failed on',
                'Remove the row from the failed-jobs table (it is no longer failed — it is pending again)',
                'Record an audit-log entry, announce the action in the ops Slack channel, and add a queue event to the timeline',
            ],
            'wont_do' => [
                'Fix the reason the job failed — if the cause is still present, it will fail again (and land back in this list)',
                'Retry any other failed job — this is strictly one job per confirmation',
                'Bump the attempt limit or change queue configuration',
            ],
            'consequence' => 'The job runs again immediately on the live queue. If its failure cause is still present (a down dependency, a changed payload shape, a bug), it will burn its attempts and fail back into the list. Fix the cause FIRST — the Retry button is for after the fix, or for transient failures you have judged safe to re-run.',
            'confirmation_phrase' => 'RETRY',
            'requires_password' => true,
        ],

        'queue.forget' => [
            'label' => 'Delete a failed queue job',
            'group' => 'Queue',
            'risk' => self::RISK_ELEVATED,
            'description' => 'Delete ONE row from the failed-jobs table (Laravel\'s own queue:forget path). The job will NOT run again — its payload is discarded.',
            'will_do' => [
                'Permanently delete the failed-jobs row, payload and exception included',
                'Remove it from the failed-jobs count the digest and diagnostics report',
                'Record an audit-log entry, announce the action in the ops Slack channel, and add a queue event to the timeline',
            ],
            'wont_do' => [
                'Run the job — forgetting is permanent disposal, the opposite of retry',
                'Archive the payload anywhere (the failed-jobs row is the ONLY copy — after this it is gone)',
                'Touch any other failed job',
            ],
            'consequence' => 'The payload and its exception trace are deleted FOREVER — there is no archive, no undo, no second copy. Use this only for jobs you have deliberately judged junk (duplicate work, test noise, payloads for removed code). If there is any chance the work still matters, export what you need from the job page BEFORE confirming.',
            'confirmation_phrase' => 'FORGET',
            'requires_password' => true,
        ],
    ];

    public static function has(string $id): bool
    {
        return isset(self::ACTIONS[$id]);
    }

    /**
     * @return array{label: string, group: string, risk: string, description: string, will_do: string[], wont_do: string[], consequence: string, confirmation_phrase: ?string, requires_password: bool}|null
     */
    public static function get(string $id): ?array
    {
        return self::ACTIONS[$id] ?? null;
    }

    /**
     * @return array<string, array{label: string, group: string, risk: string, description: string, will_do: string[], wont_do: string[], consequence: string, confirmation_phrase: ?string, requires_password: bool}>
     */
    public static function all(): array
    {
        return self::ACTIONS;
    }

    public static function label(string $id): string
    {
        return self::ACTIONS[$id]['label'] ?? $id;
    }
}
