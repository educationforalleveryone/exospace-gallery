<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2-1: Add FK constraint on pending_upgrades.transaction_id
 * P2-2: Add FK constraint + index on users.current_team_id
 * P2-3: Add SoftDeletes (deleted_at) to galleries + gallery_images
 * P2-7: Add composite index on analytics_events for dwell-update query
 * P2-8: Add user_id index on team_user pivot
 *
 * All changes are additive — no existing columns are dropped or renamed.
 * The migration is reversible (down() drops the added columns/indexes/constraints).
 *
 * NOTE: All existence checks use the Schema facade directly (Schema::getForeignKeys(),
 * Schema::hasIndex()) rather than $table->getConnection()->getSchemaBuilder() — Blueprint
 * has no getConnection() method, and Laravel 11+'s getForeignKeys() returns plain arrays
 * (with a 'columns' key), not objects with a getLocalColumns() method.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── P2-1: FK on pending_upgrades.transaction_id ──────────────────
        // Previously, transaction_id was a bare foreignId with no constraint.
        // A pending_upgrade could point to a non-existent transaction.
        Schema::table('pending_upgrades', function (Blueprint $table) {
            $hasFk = collect(Schema::getForeignKeys('pending_upgrades'))
                ->contains(fn($fk) => in_array('transaction_id', $fk['columns']));

            if (! $hasFk) {
                $table->foreign('transaction_id')
                      ->references('id')
                      ->on('transactions')
                      ->nullOnDelete();
            }
        });

        // ── P2-2: FK + index on users.current_team_id ────────────────────
        // Previously, current_team_id was a plain unsignedBigInteger with no
        // FK and no index. A team could be deleted while a user still had it
        // as their current_team_id → User::currentTeam() returns null silently.
        // The CheckBanned + PlanDowngradeService code already nulls
        // current_team_id before team deletion, but the FK is defense-in-depth.
        Schema::table('users', function (Blueprint $table) {
            $hasFk = collect(Schema::getForeignKeys('users'))
                ->contains(fn($fk) => in_array('current_team_id', $fk['columns']));

            if (! $hasFk) {
                $table->foreign('current_team_id')
                      ->references('id')
                      ->on('teams')
                      ->nullOnDelete();
            }

            if (! Schema::hasIndex('users', 'users_current_team_id_index')) {
                $table->index('current_team_id');
            }
        });

        // ── P2-3: SoftDeletes on galleries + gallery_images ──────────────
        // Previously, deleting a gallery or image was a hard delete —
        // cascading to analytics, RSVPs, newsletter signups, and deleting
        // image files on disk. No recovery path. SoftDeletes give a
        // 30-day window to restore accidental deletions.
        Schema::table('galleries', function (Blueprint $table) {
            if (! Schema::hasColumn('galleries', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }
        });

        Schema::table('gallery_images', function (Blueprint $table) {
            if (! Schema::hasColumn('gallery_images', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }
        });

        // ── P2-7: Composite index on analytics_events for dwell-update ───
        // AnalyticsController::track() runs:
        //   WHERE gallery_id = ? AND session_token = ? AND event = 'view'
        //   ORDER BY created_at DESC LIMIT 1
        // The existing indexes are [gallery_id, event] and [gallery_id, created_at].
        // Neither is optimal for this 4-column lookup. The composite index
        // (gallery_id, session_token, event, created_at) lets MySQL use a
        // single index scan.
        Schema::table('analytics_events', function (Blueprint $table) {
            if (! Schema::hasIndex('analytics_events', 'analytics_events_gallery_id_session_token_event_created_at_index')) {
                $table->index(['gallery_id', 'session_token', 'event', 'created_at'], 'analytics_dwell_index');
            }
        });

        // ── P2-8: user_id index on team_user pivot ───────────────────────
        // The pivot has unique(['team_id', 'user_id']) with team_id as the
        // leftmost column. Queries like "find all teams for user X"
        // (WHERE user_id = ?) do a full table scan. Adding a standalone
        // user_id index makes this O(log N).
        Schema::table('team_user', function (Blueprint $table) {
            if (! Schema::hasIndex('team_user', 'team_user_user_id_index')) {
                $table->index('user_id');
            }
        });
    }

    public function down(): void
    {
        // P2-8: Drop team_user user_id index
        Schema::table('team_user', function (Blueprint $table) {
            if (Schema::hasIndex('team_user', 'team_user_user_id_index')) {
                $table->dropIndex('team_user_user_id_index');
            }
        });

        // P2-7: Drop analytics_events composite index
        Schema::table('analytics_events', function (Blueprint $table) {
            if (Schema::hasIndex('analytics_events', 'analytics_dwell_index')) {
                $table->dropIndex('analytics_dwell_index');
            }
        });

        // P2-3: Drop soft deletes
        Schema::table('gallery_images', function (Blueprint $table) {
            if (Schema::hasColumn('gallery_images', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });

        Schema::table('galleries', function (Blueprint $table) {
            if (Schema::hasColumn('galleries', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });

        // P2-2: Drop users.current_team_id FK + index
        Schema::table('users', function (Blueprint $table) {
            $hasFk = collect(Schema::getForeignKeys('users'))
                ->contains(fn($fk) => in_array('current_team_id', $fk['columns']));
            if ($hasFk) {
                $table->dropForeign(['current_team_id']);
            }
            if (Schema::hasIndex('users', 'users_current_team_id_index')) {
                $table->dropIndex('users_current_team_id_index');
            }
        });

        // P2-1: Drop pending_upgrades.transaction_id FK
        Schema::table('pending_upgrades', function (Blueprint $table) {
            $hasFk = collect(Schema::getForeignKeys('pending_upgrades'))
                ->contains(fn($fk) => in_array('transaction_id', $fk['columns']));
            if ($hasFk) {
                $table->dropForeign(['transaction_id']);
            }
        });
    }
};