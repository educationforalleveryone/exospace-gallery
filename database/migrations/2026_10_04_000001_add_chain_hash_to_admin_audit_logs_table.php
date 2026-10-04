<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_audit_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('admin_audit_logs', 'chain_hash')) {
                // HMAC chain over each row's immutable fields, keyed by APP_KEY.
                // Computed by the model on insert; never mass-assignable.
                $table->char('chain_hash', 64)->nullable()->after('ip');
            }
        });
    }

    public function down(): void
    {
        Schema::table('admin_audit_logs', function (Blueprint $table) {
            if (Schema::hasColumn('admin_audit_logs', 'chain_hash')) {
                $table->dropColumn('chain_hash');
            }
        });
    }
};
