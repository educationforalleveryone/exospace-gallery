<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_audit_logs', function (Blueprint $table) {
            // Drop the existing cascade FK
            $table->dropForeign(['actor_id']);
        });

        Schema::table('admin_audit_logs', function (Blueprint $table) {
            // Make actor_id nullable (was NOT NULL for the FK constraint)
            $table->foreignId('actor_id')
                  ->nullable()
                  ->change();

            // Re-add with nullOnDelete
            $table->foreign('actor_id')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('admin_audit_logs')) {
            return;
        }
        Schema::table('admin_audit_logs', function (Blueprint $table) {
            $table->dropForeign(['actor_id']);
        });

        Schema::table('admin_audit_logs', function (Blueprint $table) {
            $table->foreignId('actor_id')
                  ->nullable(false)
                  ->change();

            $table->foreign('actor_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
        });
    }
};
