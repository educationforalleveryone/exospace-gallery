<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_upgrades', function (Blueprint $table) {
            if (! Schema::hasIndex('pending_upgrades', 'pending_upgrades_transaction_id_index')) {
                $table->index('transaction_id');
            }
        });

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

        Schema::table('analytics_events', function (Blueprint $table) {
            if (! Schema::hasIndex('analytics_events', 'analytics_events_gallery_id_session_token_event_created_at_index')) {
                $table->index(['gallery_id', 'session_token', 'event', 'created_at'], 'analytics_dwell_index');
            }
        });

        Schema::table('team_user', function (Blueprint $table) {
            if (! Schema::hasIndex('team_user', 'team_user_user_id_index')) {
                $table->index('user_id');
            }
        });
    }

    public function down(): void
    {
        // Drop team_user user_id index
        Schema::table('team_user', function (Blueprint $table) {
            if (Schema::hasIndex('team_user', 'team_user_user_id_index')) {
                $table->dropIndex('team_user_user_id_index');
            }
        });

        // Drop analytics_events composite index
        Schema::table('analytics_events', function (Blueprint $table) {
            if (Schema::hasIndex('analytics_events', 'analytics_dwell_index')) {
                $table->dropIndex('analytics_dwell_index');
            }
        });

        // Drop soft deletes
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

        // Drop users.current_team_id FK + index
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

        // Drop pending_upgrades.transaction_id index
        Schema::table('pending_upgrades', function (Blueprint $table) {
            if (Schema::hasIndex('pending_upgrades', 'pending_upgrades_transaction_id_index')) {
                $table->dropIndex('pending_upgrades_transaction_id_index');
            }
        });
    }
};