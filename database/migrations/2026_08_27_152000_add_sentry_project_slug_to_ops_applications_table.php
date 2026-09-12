<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
