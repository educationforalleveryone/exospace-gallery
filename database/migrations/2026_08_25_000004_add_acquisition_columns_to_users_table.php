<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO Operating System (Iteration 7) — organic acquisition attribution.
 *
 * Captures HOW each user ARRIVED (referrer + landing page + channel), at
 * signup time, from the session context recorded by
 * CaptureAcquisitionContext on their first page view. This powers the
 * organic-acquisition report (signups by channel, galleries created by
 * organically-acquired users) WITHOUT any third-party tracking.
 *
 * All columns nullable: existing users have no acquisition data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('acquisition_channel', 40)->nullable()->after('banned_at')
                  ->comment('organic|social|referral|campaign|direct');
            $table->string('acquisition_referrer', 500)->nullable()->after('acquisition_channel');
            $table->string('acquisition_landing_page', 500)->nullable()->after('acquisition_referrer');
            $table->json('acquisition_utm')->nullable()->after('acquisition_landing_page');
            $table->timestamp('acquisition_captured_at')->nullable()->after('acquisition_utm');

            $table->index(['acquisition_channel']);
        });
    }

    public function down(): void
    {
        // ITERATION-1 FIX (consolidated-migration coexistence): rollback
        // runs additive migrations' down() in reverse batch order — the
        // target table may already be gone (owned by the consolidated
        // migration that runs later in the same batch on fresh installs).
        if (! Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            // ITERATION-1 FIX (portable rollback): SQLite can't drop a
            // column that an index still references — drop the index first.
            $table->dropIndex(['acquisition_channel']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'acquisition_channel', 'acquisition_referrer',
                'acquisition_landing_page', 'acquisition_utm', 'acquisition_captured_at',
            ]);
        });
    }
};
