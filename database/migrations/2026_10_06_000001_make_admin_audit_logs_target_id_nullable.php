<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * admin_audit_logs.target_id backs a BIGINT morph column, but audit
     * targets are not always integer-keyed models (OpsCredential is keyed by
     * strings like 'db-password'). MySQL strict mode rejects that value
     * outright — losing the audit row entirely — so the audit writer now
     * stores string-keyed targets with a NULL target_id and the identifying
     * key inside the payload ('_target_key'). The column must allow NULL.
     */
    public function up(): void
    {
        if (! Schema::hasTable('admin_audit_logs') || ! Schema::hasColumn('admin_audit_logs', 'target_id')) {
            return;
        }

        Schema::table('admin_audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('target_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('admin_audit_logs') || ! Schema::hasColumn('admin_audit_logs', 'target_id')) {
            return;
        }

        Schema::table('admin_audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('target_id')->nullable(false)->change();
        });
    }
};
