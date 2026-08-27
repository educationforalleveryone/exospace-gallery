<?php

declare(strict_types=1);

namespace App\Ops\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Ops\Models\OpsCredential;
use App\Services\OperationalAlertService;
use Illuminate\Support\Collection;
use Throwable;

/**
 * OpsCenter — OpsCredentialInventoryService (Iteration 5).
 *
 * Turns the master manual's §15 credential-rotation checklist into a live,
 * in-product governance surface. Two halves:
 *
 *   1. The CATALOG (const below) — the platform's credential surfaces:
 *      which env vars each one lives in, whether it is CONFIGURED (a
 *      presence boolean read from config — NEVER the value), a
 *      recommended rotation cadence in days, and whether it was exposed
 *      at project kickoff (shared in chat → must be rotated before
 *      anything else). §15's nine items plus the OpsCenter-era optional
 *      tokens.
 *
 *   2. The LEDGER (ops_credentials table, via OpsCredential) — one row
 *      per catalog key, updated each time an operator records a
 *      rotation. Nothing else: the schema cannot hold a secret VALUE,
 *      the probes never read one past a boolean, and the UI asserts it.
 *
 * Status chips per credential:
 *   rotate_now — exposed at kickoff and no rotation recorded yet (§15
 *                made live: red until someone actually rotates it)
 *   overdue    — rotated once, but longer ago than the cadence
 *   due_soon   — within 14 days of the cadence limit
 *   ok         — rotated within cadence
 *   untracked  — not exposed, no rotation recorded: recording one starts
 *                the clock (an honest non-alarm — these are the optional
 *                OpsCenter tokens)
 *
 * Every recorded rotation is audited (ops.credential.rotated) and
 * announced on Slack (info) — the same paper trail as restarts and
 * access changes.
 */
class OpsCredentialInventoryService
{
    /**
     * days before a cadence expires that the chip turns "due soon".
     */
    public const DUE_SOON_WINDOW_DAYS = 14;

    /**
     * The static catalog. 'cadence' = recommended rotation interval in
     * days (null = policy-driven only, e.g. APP_KEY where rotation logs
     * everyone out). 'exposed' = value shared at project kickoff (§15).
     */
    public const CATALOG = [
        [
            'key' => 'db-password', 'name' => 'Database password', 'category' => 'Platform',
            'env' => ['DB_PASSWORD'], 'cadence' => 90, 'exposed' => true,
            'guidance' => 'DigitalOcean panel → rotate → update DB_PASSWORD in Coolify → redeploy. New connections pick it up on restart.',
        ],
        [
            'key' => 'app-key', 'name' => 'Application key (APP_KEY)', 'category' => 'Platform',
            'env' => ['APP_KEY'], 'cadence' => null, 'exposed' => true,
            'guidance' => 'Maintenance window ONLY — rotation invalidates all encrypted sessions/cookies and logs everyone out once.',
        ],
        [
            'key' => 'coolify-token', 'name' => 'Coolify API token', 'category' => 'Platform',
            'env' => ['COOLIFY_API_TOKEN'], 'cadence' => 90, 'exposed' => true,
            'guidance' => 'Coolify → Profile → API Tokens → create new → update env → redeploy. One update covers domain provisioning too.',
        ],
        [
            'key' => 'slack-webhooks', 'name' => 'Slack alert webhooks', 'category' => 'Alerting',
            'env' => ['OPERATIONAL_ALERT_WEBHOOK', 'OPERATIONAL_ALERT_CRITICAL_WEBHOOK', 'OPS_ESCALATION_WEBHOOK'], 'cadence' => 180, 'exposed' => true,
            'guidance' => 'Slack app settings → delete & recreate the webhooks → update the env vars (incl. OPS_ESCALATION_WEBHOOK if set) → redeploy.',
        ],
        [
            'key' => 'r2-keys', 'name' => 'Cloudflare R2 access keys', 'category' => 'Backups',
            'env' => ['R2_ACCESS_KEY_ID', 'R2_SECRET_ACCESS_KEY'], 'cadence' => 90, 'exposed' => true,
            'guidance' => 'Cloudflare dashboard → new keypair → update R2_* env vars → verify the next scheduled backup lands.',
        ],
        [
            'key' => 'backup-password', 'name' => 'Backup encryption password', 'category' => 'Backups',
            'env' => ['BACKUP_PASSWORD'], 'cadence' => 180, 'exposed' => true,
            'guidance' => 'Set a new BACKUP_PASSWORD → next backup re-encrypts forward. Keep the old password until old archives expire.',
        ],
        [
            'key' => 'twocheckout-secrets', 'name' => '2Checkout secret words', 'category' => 'Billing',
            'env' => ['TWOCHECKOUT_SECRET_WORD', 'TWOCHECKOUT_BUY_LINK_SECRET_WORD'], 'cadence' => 180, 'exposed' => true,
            'guidance' => '2Checkout dashboard → rotate → update BOTH secret words (they must match the dashboard) → place a test transaction.',
        ],
        [
            'key' => 'sentry-dsn', 'name' => 'Sentry DSN (error reporting)', 'category' => 'Monitoring',
            'env' => ['SENTRY_LARAVEL_DSN'], 'cadence' => 180, 'exposed' => true,
            'guidance' => 'sentry.io → Settings → Client Keys → regenerate → update env → confirm new events arrive.',
        ],
        [
            'key' => 'resend-key', 'name' => 'Resend API key (email)', 'category' => 'Email',
            'env' => ['RESEND_API_KEY'], 'cadence' => 90, 'exposed' => true,
            'guidance' => 'Resend dashboard → regenerate → update env → send a test email.',
        ],
        [
            'key' => 'metrics-webhook-tokens', 'name' => 'Metrics + outbound webhook secrets', 'category' => 'OpsCenter',
            'env' => ['METRICS_TOKEN', 'OUTBOUND_WEBHOOK_SECRET'], 'cadence' => 180, 'exposed' => true,
            'guidance' => 'Regenerate both → update env → verify /metrics answers and webhook signatures validate.',
        ],
        [
            'key' => 'ops-ingest-tokens', 'name' => 'OpsCenter ingest tokens', 'category' => 'OpsCenter',
            'env' => ['OPS_INGEST_TOKENS'], 'cadence' => 90, 'exposed' => false,
            'guidance' => 'openssl rand -hex 24 per slug → replace values in OPS_INGEST_TOKENS (same slugs) → redeploy → update reporters.',
        ],
        [
            'key' => 'sentry-api-token', 'name' => 'Sentry API token (read-only summary)', 'category' => 'OpsCenter',
            'env' => ['SENTRY_API_TOKEN'], 'cadence' => 90, 'exposed' => false,
            'guidance' => 'sentry.io → Auth Tokens → new token, same scopes (org:read + project:read) → update env → redeploy.',
        ],
    ];

    public function __construct(
        private readonly OperationalAlertService $alerts,
    ) {}

    /**
     * Full inventory: catalog + live configured-presence + ledger state.
     *
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     counts: array{rotate_now: int, overdue: int, due_soon: int, ok: int, untracked: int, configured: int}
     * }
     */
    public function inventory(): array
    {
        $ledger = $this->ledgerRows();

        $items = [];
        $counts = ['rotate_now' => 0, 'overdue' => 0, 'due_soon' => 0, 'ok' => 0, 'untracked' => 0, 'configured' => 0];

        foreach (self::CATALOG as $entry) {
            $row = $ledger->firstWhere('key', $entry['key']);

            $lastRotated = $row?->last_rotated_at;
            // Carbon's diffInDays() is fractional (169.99 for "170 days
            // minus a second"): the EXACT float drives the cadence math,
            // the floor drives the "x days ago" display.
            $daysSince = $lastRotated !== null ? (float) $lastRotated->diffInDays(now()) : null;

            $item = array_merge($entry, [
                'configured' => $this->probeConfigured($entry['key']),
                'last_rotated_at' => $lastRotated,
                'days_since' => $daysSince !== null ? (int) floor($daysSince) : null,
                'rotated_by' => $row?->rotatedBy?->name,
                'notes' => $row?->notes,
                'status' => $this->statusFor($entry, $lastRotated, $daysSince),
            ]);

            if ($item['configured']) {
                $counts['configured']++;
            }
            $counts[$item['status']] = ($counts[$item['status']] ?? 0) + 1;

            $items[] = $item;
        }

        // §15 order first: exposed-never-rotated at the top, then overdue,
        // then the rest in catalog order.
        usort($items, fn ($a, $b) => $this->statusRank($a['status']) <=> $this->statusRank($b['status']));

        return ['items' => $items, 'counts' => $counts];
    }

    /**
     * Record a rotation. Validates the key, upserts the ledger row (one
     * row per key — re-rotating updates it), audits and announces.
     *
     * @return array{ok: bool, message: string}
     */
    public function markRotated(string $key, User $actor, ?string $note = null): array
    {
        $entry = $this->entry($key);

        if ($entry === null) {
            return ['ok' => false, 'message' => 'Unknown credential key — not in the catalog.'];
        }

        $note = $note !== null && $note !== '' ? mb_substr($note, 0, 250) : null;

        try {
            $credential = OpsCredential::updateOrCreate(
                ['key' => $key],
                [
                    'last_rotated_at' => now(),
                    'rotated_by' => $actor->id,
                    'notes' => $note,
                ],
            );
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'The rotation ledger could not be updated.'];
        }

        try {
            AdminAuditLog::record('ops.credential.rotated', $credential, [
                'credential' => $key,
                'note' => $note,
                // Values NEVER enter the payload — the key name is the
                // whole story ("coolify-token rotated by operator #4").
            ]);
        } catch (Throwable) {
            // The ledger must never take the flow down.
        }

        try {
            $this->alerts->alert(
                'Credential rotation recorded: '.$entry['name'],
                sprintf(
                    "operator #%d recorded a rotation of `%s` (env: %s).%s",
                    $actor->id,
                    $key,
                    implode(', ', $entry['env']),
                    $note !== null ? ' Note: '.mb_substr($note, 0, 120) : '',
                ),
                'info',
            );
        } catch (Throwable) {
            // Alerting must never take the flow down.
        }

        return ['ok' => true, 'message' => 'Rotation recorded for '.$entry['name'].'.'];
    }

    /**
     * @return array{key: string, name: string, category: string, env: string[], cadence: ?int, exposed: bool, guidance: string}|null
     */
    public function entry(string $key): ?array
    {
        foreach (self::CATALOG as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, OpsCredential>
     */
    private function ledgerRows(): Collection
    {
        try {
            return OpsCredential::query()->with('rotatedBy')->get();
        } catch (Throwable) {
            // Table absent (pre-migration window) — every credential
            // reports "never rotated" honestly.
            return collect();
        }
    }

    /**
     * @param  array{cadence: ?int, exposed: bool}  $entry
     */
    private function statusFor(array $entry, ?\Illuminate\Support\Carbon $lastRotated, ?float $daysSince): string
    {
        if ($lastRotated === null) {
            // Never rotated: alarming only for kickoff-exposed values.
            return $entry['exposed'] ? 'rotate_now' : 'untracked';
        }

        $cadence = $entry['cadence'];

        if ($cadence === null) {
            return 'ok'; // policy-driven only — a recorded rotation satisfies it
        }

        if ($daysSince > $cadence) {
            return 'overdue';
        }

        if ($daysSince > $cadence - self::DUE_SOON_WINDOW_DAYS) {
            return 'due_soon';
        }

        return 'ok';
    }

    private function statusRank(string $status): int
    {
        return match ($status) {
            'rotate_now' => 0,
            'overdue' => 1,
            'due_soon' => 2,
            'untracked' => 3,
            default => 4, // ok
        };
    }

    /**
     * Configured-presence probe per credential. Reads CONFIG (config:cache
     * safe), returns a BOOLEAN only — the value never leaves this method.
     */
    private function probeConfigured(string $key): bool
    {
        return match ($key) {
            'db-password' => $this->nonEmpty(config('database.connections.'.config('database.default').'.password')),
            'app-key' => $this->nonEmpty(config('app.key')),
            'coolify-token' => $this->nonEmpty(config('services.coolify.api_token')),
            'slack-webhooks' => $this->nonEmpty(config('services.operational_alerts.webhook_url'))
                || $this->nonEmpty(config('services.operational_alerts.critical_webhook_url'))
                || $this->nonEmpty(config('services.operational_alerts.escalation_webhook_url')),
            'r2-keys' => $this->nonEmpty(config('filesystems.disks.r2.key')),
            'backup-password' => $this->nonEmpty(config('backup.backup.password')),
            'twocheckout-secrets' => $this->nonEmpty(config('services.2checkout.secret_word'))
                || $this->nonEmpty(config('services.2checkout.buy_link_secret_word')),
            'sentry-dsn' => $this->nonEmpty(config('sentry.dsn')),
            'resend-key' => $this->nonEmpty(config('services.resend.key')),
            'metrics-webhook-tokens' => $this->nonEmpty(config('app.metrics_token'))
                || $this->nonEmpty(config('services.outbound_webhook.secret')),
            'ops-ingest-tokens' => $this->nonEmpty(config('ops.ingest.tokens')),
            'sentry-api-token' => $this->nonEmpty(config('ops.sentry.api_token')),
            default => false,
        };
    }

    private function nonEmpty(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }
}
