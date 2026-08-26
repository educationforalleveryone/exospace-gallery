<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ITERATION-1 FIX (GDPR deletion consistency): make transactions.user_id
 * nullable.
 *
 * Context: since the partition migration dropped the transactions.user_id
 * FK on MySQL (partitioned InnoDB tables cannot be FK targets), deleting a
 * user leaves their transactions with a DANGLING user_id — a value pointing
 * at a row that no longer exists. UserDeletionService::deleteUser now NULLs
 * user_id while anonymizing PII (G-2), which requires the column to be
 * nullable. Semantically this is strictly better than a dangling reference:
 * "no linked user" is expressed as NULL rather than a ghost id, and joins
 behave identically.
 *
 * On SQLite the FK drop no-ops, so the column keeps its constraint — but
 * this migration's change() still makes the column nullable there, which is
 * what allows the deletion service to detach rows before the user delete
 * fires the (still active) cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        // invoices.user_id is nullOnDelete on MySQL (G-1), but that FK
        // change never applied on SQLite — keep the column semantics in
        // sync so the GDPR deletion flow can detach rows on every driver.
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        // event_rsvps.name is NOT NULL, but AnonymizeRsvpPii nulls it when
        // scrubbing old rows (GDPR) — the anonymization job crashed with an
        // integrity violation on every run. Nullable by intent.
        Schema::table('event_rsvps', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Re-attaching NULL rows to a user is impossible — only tighten the
        // column when no NULLs are present, otherwise fail loudly.
        $hasNulls = DB::table('transactions')->whereNull('user_id')->exists();
        if ($hasNulls) {
            throw new \RuntimeException('Cannot reverse: transactions with NULL user_id exist.');
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
