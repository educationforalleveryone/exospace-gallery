<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add DNS-verification columns to the galleries table.
     *
     * Task C06 — closes the custom-domain first-claim squatting attack
     * where a Studio user could claim an arbitrary domain (including
     * domains they don't own, like gallery.competitor.com) just by
     * typing it into the admin UI. The unique index on custom_domain
     * then prevented the legitimate domain owner from ever claiming
     * their own domain via Exospace.
     *
     * With verification:
     *   1. User enters custom_domain → we store it + generate a random
     *      verification_token. custom_domain_verified_at stays NULL.
     *   2. We show the user a TXT record to add to their DNS:
     *        _exospace.<their-domain>.  TXT  "exospace-verify=<token>"
     *   3. User clicks "Verify now" (or a scheduled job retries hourly).
     *      We do dns_get_record() looking for that TXT record.
     *   4. On match, we set custom_domain_verified_at = now() and call
     *      CoolifyDomainManager::addDomain() to register with Traefik.
     *   5. DetectCustomDomain middleware only routes galleries whose
     *      custom_domain_verified_at is set. Unverified custom domains
     *      get a 404 (or a "pending verification" page) so they can't
     *      be served to visitors.
     *
     * The legitimate domain owner can ALWAYS claim their domain because
     * only they can add the TXT record. If a squatter previously claimed
     * the domain without verifying it, the unique index doesn't block
     * the legitimate owner — they can request the admin delete the
     * unverified row (or we can auto-delete unverified rows after N
     * days — future enhancement).
     */
    public function up(): void
    {
        // ITERATION-1 FIX (consolidated-migration coexistence): the
        // consolidated galleries migration (runs earlier in this same batch
        // on fresh installs — 100000 < 150000 is FALSE, it runs FIRST) may
        // not have run yet; guard the whole additive block for the case
        // where the consolidated table already contains these columns.
        if (! Schema::hasTable('galleries')) {
            // Consolidated migration hasn't created the table yet — this
            // migration is a no-op on fresh installs (columns come from the
            // consolidated schema).
            return;
        }
        if (Schema::hasColumn('galleries', 'custom_domain_verification_token')) {
            // Columns already provided by the consolidated schema.
            return;
        }
        Schema::table('galleries', function (Blueprint $table) {
            // Random 32-char token used in the TXT record. Nullable because
            // galleries with no custom_domain don't need a token.
            $table->string('custom_domain_verification_token', 64)
                  ->nullable()
                  ->after('custom_domain');

            // Timestamp of successful DNS verification. NULL = pending.
            $table->timestamp('custom_domain_verified_at')
                  ->nullable()
                  ->after('custom_domain_verification_token');

            // Index for the scheduled verification-retry job to find pending
            // galleries efficiently.
            $table->index('custom_domain_verified_at', 'galleries_pending_domain_idx');
        });
    }

    public function down(): void
    {
        // ITERATION-1 FIX (portable rollback): when this migration runs after
        // a `migrate:fresh` that already created the index via the
        // consolidated galleries migration, the named index may or may not
        // exist under this name. Drop columns first (cascades the index on
        // both drivers), tolerating a missing index.
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
