<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OpsCenter — Iteration 8 (Feature A): the Coolify-app ↔ Sentry-project
 * mapping.
 *
 * This ONE nullable column is the prerequisite AD-9 deferred Iteration 7
 * for: per-application Sentry trends need to know WHICH Sentry project
 * each ops_application corresponds to, and that knowledge existed
 * nowhere in the codebase or config. The operator supplies it per app
 * from the Applications page (super-admin-only, audited); an unmapped
 * application simply renders no Sentry column data — never an error,
 * never a guess.
 *
 * Design notes:
 *   - NULL (the default) = unmapped. Every existing row stays unmapped
 *     until an operator explicitly maps it — the column adds zero
 *     day-one behavior change on its own.
 *   - A slug is a LABEL, not a secret (it appears in Sentry URLs), so
 *     audit payloads may carry the old→new values verbatim. The Sentry
 *     API TOKEN remains the only secret in this integration and keeps
 *     living in env, never in this table.
 *   - No index: ops_applications is a tiny table (dozens of rows), the
 *     column is only read alongside rows the page already loads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_applications', function (Blueprint $table) {
            $table->string('sentry_project_slug', 100)->nullable()
                ->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('ops_applications', function (Blueprint $table) {
            $table->dropColumn('sentry_project_slug');
        });
    }
};
