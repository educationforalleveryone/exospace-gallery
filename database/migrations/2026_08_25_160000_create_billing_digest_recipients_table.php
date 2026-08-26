<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ITERATION 7 — DB-backed recipient list for the weekly billing digest.
 *
 * Until Iteration 6 the digest's recipients came only from the
 * BILLING_EXPORT_EMAIL env var (comma-separated). That works for an
 * initial deploy but finance teams change hands, addresses retire,
 * and "ask the operator to edit a Coolify env var and redeploy" is
 * the wrong trust bar for a recurring financial email — every change
 * should be attributable to an admin and visible from inside the app.
 *
 * This table follows the team_invitations precedent: one row per
 * recipient, unique email, added_by attribution, soft ownership of
 * who put it there and when. Precedence in SendBillingExport is
 * "DB list non-empty → DB only; DB empty → env fallback" so a fresh
 * deploy with no rows yet still works exactly as before, and the
 * first recipient an admin adds silently takes over from the env
 * (no surprise: the Billing Review card surfaces the effective list
 * and the env fallback state explicitly).
 *
 * Not a settings table: spatie/laravel-settings isn't installed, and
 * the existing Setting model has zero consumers — a dedicated table
 * gives unique email + audit-friendly attribution that a key/value
 * JSON blob can't.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('billing_digest_recipients')) {
            return;
        }

        Schema::create('billing_digest_recipients', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255);
            $table->unsignedBigInteger('added_by')->nullable();

            $table->foreign('added_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->unique('email');
        });
    }

    public function down(): void
    {
        // Guard the drop so a rolling deploy running migrate:rollback
        // survives either state.
        if (Schema::hasTable('billing_digest_recipients')) {
            Schema::drop('billing_digest_recipients');
        }
    }
};
