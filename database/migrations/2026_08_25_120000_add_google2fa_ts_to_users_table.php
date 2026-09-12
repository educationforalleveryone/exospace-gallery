<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'google2fa_ts')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('google2fa_ts')->nullable()->after('google2fa_secret');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'google2fa_ts')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('google2fa_ts');
            });
        }
    }
};
