<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('galleries')) {
            return;
        }
        if (Schema::hasColumn('galleries', 'custom_domain_verification_token')) {
            // Columns already provided by the consolidated schema.
            return;
        }
        Schema::table('galleries', function (Blueprint $table) {
            $table->string('custom_domain_verification_token', 64)
                  ->nullable()
                  ->after('custom_domain');

            // Timestamp of successful DNS verification. NULL = pending.
            $table->timestamp('custom_domain_verified_at')
                  ->nullable()
                  ->after('custom_domain_verification_token');

            $table->index('custom_domain_verified_at', 'galleries_pending_domain_idx');
        });
    }

    public function down(): void
    {
        try {
            Schema::table('galleries', function (Blueprint $table) {
                $table->dropIndex('galleries_pending_domain_idx');
            });
        } catch (\Throwable) {
            // Index already absent (consolidated-migration path).
        }
        if (! Schema::hasTable('galleries')
            || ! Schema::hasColumn('galleries', 'custom_domain_verification_token')) {
            return; // nothing this migration added
        }
        Schema::table('galleries', function (Blueprint $table) {
            $table->dropColumn(['custom_domain_verified_at', 'custom_domain_verification_token']);
        });
    }
};
