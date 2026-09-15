<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('team_user')) {
            return;
        }

        // A stale active-team pointer (no matching membership row) would make
        // the composite membership FK below invalid, so release those first.
        if (Schema::hasColumn('users', 'current_team_id')) {
            DB::table('users')
                ->whereNotNull('current_team_id')
                ->whereNotExists(function ($query) {
                    $query->selectRaw(1)
                        ->from('team_user')
                        ->whereColumn('team_user.team_id', 'users.current_team_id')
                        ->whereColumn('team_user.user_id', 'users.id');
                })
                ->update(['current_team_id' => null]);
        }

        // Provider-assigned identifiers must resolve to exactly one account:
        // recurring-payment routing looks users up by subscription_id, and
        // social login resolves accounts by provider id. Keep the newest row
        // for subscription ids (the reassignment case) and the earliest row
        // for provider ids (the genuinely first-linked account).
        $this->releaseDuplicateValues('subscription_id', 'newest');
        $this->releaseDuplicateValues('google_id', 'oldest');
        $this->releaseDuplicateValues('github_id', 'oldest');

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasIndex('users', 'users_subscription_id_unique')) {
                $table->unique('subscription_id', 'users_subscription_id_unique');
            }
        });

        foreach (['google_id' => 'users_google_id_unique', 'github_id' => 'users_github_id_unique'] as $column => $uniqueName) {
            Schema::table('users', function (Blueprint $table) use ($column, $uniqueName) {
                if (! Schema::hasIndex('users', $uniqueName)) {
                    $table->unique($column, $uniqueName);
                }
            });

            // The plain lookup index is superseded by the unique index.
            $lookupIndex = "users_{$column}_index";
            if (Schema::hasIndex('users', $lookupIndex) && Schema::hasIndex('users', $uniqueName)) {
                Schema::table('users', function (Blueprint $table) use ($lookupIndex) {
                    $table->dropIndex($lookupIndex);
                });
            }
        }

        // The active-team pointer must always reference a team the user is a
        // member of: users(current_team_id, id) has to exist as
        // team_user(team_id, user_id). Rows with a NULL pointer are unchecked
        // (MATCH SIMPLE), which is the legitimate "no active team" state.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            if (! $this->hasMembershipForeignKey()) {
                if (Schema::hasIndex('users', 'users_current_team_id_index')) {
                    Schema::table('users', function (Blueprint $table) {
                        $table->index(['current_team_id', 'id'], 'users_current_team_membership_index');
                    });

                    // The composite covers every lookup the single-column
                    // index served, including the existing FK to teams.id.
                    Schema::table('users', function (Blueprint $table) {
                        $table->dropIndex('users_current_team_id_index');
                    });
                } elseif (! Schema::hasIndex('users', 'users_current_team_membership_index')) {
                    Schema::table('users', function (Blueprint $table) {
                        $table->index(['current_team_id', 'id'], 'users_current_team_membership_index');
                    });
                }

                Schema::table('users', function (Blueprint $table) {
                    $table->foreign(['current_team_id', 'id'])
                        ->references(['team_id', 'user_id'])
                        ->on('team_user')
                        ->restrictOnDelete();
                });
            }
        }

        if (Schema::hasTable('gallery_images')) {
            Schema::table('gallery_images', function (Blueprint $table) {
                if (! Schema::hasIndex('gallery_images', 'gallery_images_gallery_position_index')) {
                    $table->index(['gallery_id', 'position_order'], 'gallery_images_gallery_position_index');
                }
            });

            // Both single-column indexes are superseded: gallery lookups use
            // the composite leftmost prefix, and position_order is only ever
            // ordered within a gallery scope.
            foreach (['gallery_images_gallery_id_index', 'gallery_images_position_order_index'] as $indexName) {
                if (Schema::hasIndex('gallery_images', $indexName)) {
                    Schema::table('gallery_images', function (Blueprint $table) use ($indexName) {
                        $table->dropIndex($indexName);
                    });
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && $this->hasMembershipForeignKey()) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['current_team_id', 'id']);
            });
        }

        if (Schema::hasTable('users')) {
            // Restore the original single-column index before removing the
            // composite so the FK to teams.id keeps a usable index.
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasIndex('users', 'users_current_team_id_index')) {
                    $table->index('current_team_id', 'users_current_team_id_index');
                }
            });

            if (Schema::hasIndex('users', 'users_current_team_membership_index')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->dropIndex('users_current_team_membership_index');
                });
            }
        }

        foreach (['users_subscription_id_unique', 'users_google_id_unique', 'users_github_id_unique'] as $uniqueName) {
            if (Schema::hasIndex('users', $uniqueName)) {
                Schema::table('users', function (Blueprint $table) use ($uniqueName) {
                    $table->dropUnique($uniqueName);
                });
            }
        }

        Schema::table('users', function (Blueprint $table) {
            foreach (['google_id' => 'users_google_id_index', 'github_id' => 'users_github_id_index'] as $column => $indexName) {
                if (Schema::hasColumn('users', $column) && ! Schema::hasIndex('users', $indexName)) {
                    $table->index($column, $indexName);
                }
            }
        });

        if (Schema::hasTable('gallery_images')) {
            // Restore the single-column indexes first: the gallery_id FK
            // needs a usable index at every point in time, so the composite
            // can only be dropped once a replacement exists.
            Schema::table('gallery_images', function (Blueprint $table) {
                foreach (['gallery_id' => 'gallery_images_gallery_id_index', 'position_order' => 'gallery_images_position_order_index'] as $column => $indexName) {
                    if (! Schema::hasIndex('gallery_images', $indexName)) {
                        $table->index($column, $indexName);
                    }
                }
            });

            if (Schema::hasIndex('gallery_images', 'gallery_images_gallery_position_index')) {
                Schema::table('gallery_images', function (Blueprint $table) {
                    $table->dropIndex('gallery_images_gallery_position_index');
                });
            }
        }
    }

    private function hasMembershipForeignKey(): bool
    {
        return collect(DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            ['users', 'team_user', 'current_team_id']
        ))->isNotEmpty();
    }

    /**
     * Null duplicated identifier values, keeping one canonical row per value.
     * Only rows carrying a non-null identifier are fetched, so the scan cost
     * is bounded by the number of linked accounts, not the users table size.
     */
    private function releaseDuplicateValues(string $column, string $keep): void
    {
        if (! Schema::hasColumn('users', $column)) {
            return;
        }

        $rows = DB::table('users')
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->orderBy($column)
            ->orderBy('updated_at', $keep === 'newest' ? 'desc' : 'asc')
            ->orderBy('id', $keep === 'newest' ? 'desc' : 'asc')
            ->get(['id', $column]);

        $seen = [];
        $duplicates = [];
        foreach ($rows as $row) {
            if (isset($seen[$row->{$column}])) {
                $duplicates[] = $row->id;
            } else {
                $seen[$row->{$column}] = $row->id;
            }
        }

        if ($duplicates === []) {
            return;
        }

        foreach (array_chunk($duplicates, 500) as $ids) {
            DB::table('users')->whereIn('id', $ids)->update([$column => null]);
        }
    }
};
