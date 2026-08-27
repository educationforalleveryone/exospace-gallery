<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OpsCenter — Iteration 5 — RBAC viewer access grants.
 *
 * Delegation without blast radius: a super-admin can grant a REGULAR,
 * verified, MFA-enabled user read-only access to /ops (overview,
 * applications, events, incidents, diagnostics RESULTS) — every write
 * surface (lifecycle actions, diagnostic runs, the Actions hub,
 * credentials, access management itself) stays super-admin-only at the
 * ROUTE level, not just in the UI.
 *
 * Table shape is a ledger, not a flag: every grant is a row with its
 * author and timestamp; revoking sets revoked_at (the history stays).
 * "Active" = revoked_at IS NULL. A user may hold at most ONE active
 * grant (enforced in OpsAccessService — portable across MySQL and
 * SQLite, which lack partial unique indexes).
 *
 * level currently allows only 'viewer' — the column exists so a future
 * 'operator' tier can be added without a schema change, but nothing
 * grants it today and the middleware treats every non-viewer level as
 * no access (fail-closed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_access_grants', function (Blueprint $table) {
            $table->id();
            // Who receives access.
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            // 'viewer' (read-only). Fail-closed for anything else.
            $table->string('level', 20)->default('viewer');
            // Who granted it (kept even if the granter's account is later
            // deleted — nullOnDelete, the row survives as history).
            $table->foreignId('granted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_access_grants');
    }
};
