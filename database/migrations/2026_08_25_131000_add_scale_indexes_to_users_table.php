<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
