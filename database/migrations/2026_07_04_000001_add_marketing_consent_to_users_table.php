<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0-3 FIX (audit): Add marketing_consent column to users.
 *
 * CAN-SPAM §316.4 and GDPR Art. 6(1)(a) require lawful basis (consent)
 * for marketing emails. The abandoned-cart and inactive-nudge emails are
 * commercial in nature — they encourage the user to make a purchase or
 * engage with the product. Without a marketing_consent flag, these emails
 * are unlawful.
 *
 * The column defaults to false. Users must explicitly opt in at
 * registration (checkbox on the register form). The unsubscribe route
 * sets this to false.
 *
 * Existing users (created before this migration) default to false — they
 * did not consent, so they will NOT receive marketing emails until they
 * explicitly opt in via their profile settings (future feature) or
 * re-register.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('marketing_consent')->default(false)->after('lifecycle_nudged_at');
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
            $table->dropColumn('marketing_consent');
        });
    }
};
