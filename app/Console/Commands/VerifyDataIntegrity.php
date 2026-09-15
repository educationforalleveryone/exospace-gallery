<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only audit of relational invariants the schema cannot fully express
 * (morph references, retention-side effects, state coherence across columns).
 *
 * Exit code 1 when a hard invariant (severity "fail") is violated, so the
 * command can gate deployments or run from monitoring cron jobs. Warnings
 * flag anomalies worth reviewing without failing the run.
 */
class VerifyDataIntegrity extends Command
{
    protected $signature = 'exospace:verify-data-integrity
                            {--format=text : Output format: text or json}';

    protected $description = 'Check persistent data for orphaned references, duplicate identifiers, and impossible states.';

    /**
     * @return array<string, array{severity: string, description: string, query: callable(): int}>
     */
    private function checks(): array
    {
        return [
            'users.active_team_without_membership' => [
                'severity' => 'fail',
                'description' => 'Users whose current_team_id points to a team they have no membership row for.',
                'query' => fn (): int => DB::table('users')
                    ->whereNotNull('current_team_id')
                    ->whereNotExists(fn ($q) => $q->selectRaw(1)
                        ->from('team_user')
                        ->whereColumn('team_user.team_id', 'users.current_team_id')
                        ->whereColumn('team_user.user_id', 'users.id'))
                    ->count(),
            ],

            'users.duplicate_subscription_id' => [
                'severity' => 'fail',
                'description' => 'Users sharing one billing subscription id — recurring webhooks would route to an arbitrary account.',
                'query' => fn (): int => $this->duplicateCount('users', 'subscription_id'),
            ],

            'users.duplicate_google_id' => [
                'severity' => 'fail',
                'description' => 'Users sharing one Google account link.',
                'query' => fn (): int => $this->duplicateCount('users', 'google_id'),
            ],

            'users.duplicate_github_id' => [
                'severity' => 'fail',
                'description' => 'Users sharing one GitHub account link.',
                'query' => fn (): int => $this->duplicateCount('users', 'github_id'),
            ],

            'teams.owner_membership_missing' => [
                'severity' => 'fail',
                'description' => 'Teams without exactly one owner-role membership row.',
                'query' => function (): int {
                    $ownerless = DB::table('teams')
                        ->whereNotExists(fn ($q) => $q->selectRaw(1)
                            ->from('team_user')
                            ->whereColumn('team_user.team_id', 'teams.id')
                            ->where('team_user.role', 'owner'))
                        ->count();

                    $multiOwner = DB::table('team_user')
                        ->select('team_id')
                        ->where('role', 'owner')
                        ->groupBy('team_id')
                        ->havingRaw('COUNT(*) > 1')
                        ->count();

                    return $ownerless + $multiOwner;
                },
            ],

            'galleries.active_without_published_at' => [
                'severity' => 'warn',
                'description' => 'Live exhibitions without a published_at stamp.',
                'query' => fn (): int => DB::table('galleries')
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->whereNull('published_at')
                    ->count(),
            ],

            'galleries.closes_before_opens' => [
                'severity' => 'warn',
                'description' => 'Exhibitions whose schedule ends before it starts.',
                'query' => fn (): int => DB::table('galleries')
                    ->whereNotNull('opens_at')
                    ->whereNotNull('closes_at')
                    ->whereColumn('closes_at', '<', 'opens_at')
                    ->count(),
            ],

            'gallery_schedule_events.ends_before_starts' => [
                'severity' => 'warn',
                'description' => 'Schedule events whose end precedes their start.',
                'query' => fn (): int => DB::table('gallery_schedule_events')
                    ->whereNotNull('ends_at')
                    ->whereColumn('ends_at', '<', 'starts_at')
                    ->count(),
            ],

            'gallery_images.for_sale_without_price' => [
                'severity' => 'warn',
                'description' => 'Artworks marked for sale without a price.',
                'query' => fn (): int => DB::table('gallery_images')
                    ->whereNull('deleted_at')
                    ->where('for_sale', true)
                    ->whereNull('price')
                    ->count(),
            ],

            'invoices.dangling_transaction_reference' => [
                'severity' => 'warn',
                'description' => 'Invoices referencing a transaction id that no longer exists (expected after retention pruning).',
                'query' => fn (): int => DB::table('invoices')
                    ->whereNotNull('transaction_id')
                    ->whereNotExists(fn ($q) => $q->selectRaw(1)
                        ->from('transactions')
                        ->whereColumn('transactions.id', 'invoices.transaction_id'))
                    ->count(),
            ],

            'pending_upgrades.dangling_transaction_reference' => [
                'severity' => 'warn',
                'description' => 'Converted upgrades referencing a transaction id that no longer exists (expected after retention pruning).',
                'query' => fn (): int => DB::table('pending_upgrades')
                    ->whereNotNull('transaction_id')
                    ->whereNotExists(fn ($q) => $q->selectRaw(1)
                        ->from('transactions')
                        ->whereColumn('transactions.id', 'pending_upgrades.transaction_id'))
                    ->count(),
            ],

            'seo_profiles.orphaned_subject' => [
                'severity' => 'warn',
                'description' => 'SEO profiles whose subject row no longer exists.',
                'query' => function (): int {
                    $orphaned = 0;

                    DB::table('seo_profiles')
                        ->select('subject_type')
                        ->distinct()
                        ->pluck('subject_type')
                        ->each(function ($type) use (&$orphaned) {
                            if (! class_exists($type)) {
                                $orphaned += DB::table('seo_profiles')->where('subject_type', $type)->count();

                                return;
                            }

                            $model = new $type;
                            $orphaned += DB::table('seo_profiles')
                                ->where('subject_type', $type)
                                ->whereNotExists(fn ($q) => $q->selectRaw(1)
                                    ->from($model->getTable())
                                    ->whereColumn($model->getTable().'.'.$model->getKeyName(), 'seo_profiles.subject_id'))
                                ->count();
                        });

                    return $orphaned;
                },
            ],

            'personal_access_tokens.orphaned_tokenable' => [
                'severity' => 'warn',
                'description' => 'API tokens whose user account no longer exists.',
                'query' => fn (): int => DB::table('personal_access_tokens')
                    ->where('tokenable_type', 'App\\Models\\User')
                    ->whereNotExists(fn ($q) => $q->selectRaw(1)
                        ->from('users')
                        ->whereColumn('users.id', 'personal_access_tokens.tokenable_id'))
                    ->count(),
            ],

            'media.orphaned_model' => [
                'severity' => 'warn',
                'description' => 'Media records whose owning model no longer exists.',
                'query' => function (): int {
                    $orphaned = 0;

                    DB::table('media')
                        ->select('model_type')
                        ->distinct()
                        ->pluck('model_type')
                        ->each(function ($type) use (&$orphaned) {
                            if (! class_exists($type)) {
                                $orphaned += DB::table('media')->where('model_type', $type)->count();

                                return;
                            }

                            $model = new $type;
                            $orphaned += DB::table('media')
                                ->where('model_type', $type)
                                ->whereNotExists(fn ($q) => $q->selectRaw(1)
                                    ->from($model->getTable())
                                    ->whereColumn($model->getTable().'.'.$model->getKeyName(), 'media.model_id'))
                                ->count();
                        });

                    return $orphaned;
                },
            ],
        ];
    }

    public function handle(): int
    {
        $format = (string) $this->option('format');
        $results = [];

        foreach ($this->checks() as $name => $check) {
            try {
                $count = (int) ($check['query'])();
                $status = $count === 0 ? 'ok' : $check['severity'];
            } catch (\Throwable $e) {
                $count = null;
                $status = 'error';
                $check['description'] .= ' ['.$e->getMessage().']';
            }

            $results[] = [
                'check' => $name,
                'status' => $status,
                'violations' => $count,
                'description' => $check['description'],
            ];
        }

        $failed = array_filter($results, fn ($r) => $r['status'] === 'fail' || $r['status'] === 'error');

        if ($format === 'json') {
            $this->line(json_encode([
                'passed' => $failed === [],
                'checks' => $results,
            ], JSON_PRETTY_PRINT));
        } else {
            foreach ($results as $result) {
                $line = sprintf(
                    '  [%s] %-55s %s',
                    strtoupper($result['status']),
                    $result['check'],
                    $result['violations'] === null ? 'n/a' : $result['violations'].' violation(s)'
                );
                $this->{$result['status'] === 'ok' ? 'line' : 'warn'}($line);
            }

            $this->newLine();
            $failed === []
                ? $this->info('Data integrity checks passed.')
                : $this->error(sprintf('%d hard integrity check(s) failed — resolve before relying on affected data.', count($failed)));
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    private function duplicateCount(string $table, string $column): int
    {
        return DB::table($table)
            ->select($column)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->count();
    }
}
