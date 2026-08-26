<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ITERATION 4 — users table scale indexes.
 *
 * The Master Control dashboard (SystemController::index) runs, on every page
 * load: three `WHERE plan = ?` counts, a banned/unverified count pair, and a
 * `ORDER BY created_at DESC` user pagination. None of those predicates had
 * an index (the consolidated users migration only indexed current_team_id /
 * google_id / github_id / trial_ends_at / is_super_admin / acquisition
 * columns) — at 100k+ users each page load becomes a set of full scans.
 *
 * Iteration 4 also caches those counts (Cache::flexible), but indexes are
 * the durable fix: the pagination sort runs on every page load regardless
 * of caching, and cache refreshes hit the same predicates.
 *
 * Uses Schema::getIndexes() (portable across SQLite/MySQL — the Iteration-1
 * lesson: raw SHOW INDEX DDL is MySQL-only and breaks SQLite CI) plus
 * try/catch guards so concurrent deploys / pre-existing indexes never abort
 * the migration. Verified up/down/up on SQLite.
 */
return new class extends Migration
{
    /**
     * @return list<array{name: string}>
     */
    private function indexNames(string $table): array
    {
        try {
            return array_map(
                fn (array $index) => ['name' => $index['name'] ?? ''],
                Schema::getIndexes($table),
            );
        } catch (\Throwable) {
            return []; // Introspection unavailable — let the CREATE run guarded by try/catch.
        }
    }

    public function up(): void
    {
        $existing = collect($this->indexNames('users'))->map(fn ($i) => $i['name'])->all();

        foreach ([
            ['users_created_at_index', 'created_at'],
            ['users_plan_index', 'plan'],
        ] as [$indexName, $column]) {
            if (in_array($indexName, $existing, true)) {
                continue;
            }
            try {
                Schema::table('users', function (Blueprint $table) use ($column, $indexName) {
                    $table->index($column, $indexName);
                });
            } catch (\Throwable $e) {
                // Already present under a different name, or concurrent
                // deploy raced us — non-fatal for an additive index.
                report($e);
            }
        }
    }

    public function down(): void
    {
        $existing = collect($this->indexNames('users'))->map(fn ($i) => $i['name'])->all();

        foreach (['users_created_at_index', 'users_plan_index'] as $indexName) {
            if (! in_array($indexName, $existing, true)) {
                continue;
            }
            try {
                Schema::table('users', function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            } catch (\Throwable $e) {
                // Absent index on rollback must never hard-fail.
            }
        }
    }
};
