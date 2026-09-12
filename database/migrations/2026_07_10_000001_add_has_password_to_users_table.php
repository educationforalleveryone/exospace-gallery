<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('has_password')->default(true)->after('password');

            $table->timestamp('password_set_at')->nullable()->after('has_password');
        });

        DB::table('users')
            ->whereNotNull('password')
            ->update([
                'has_password'    => true,
                'password_set_at' => DB::raw('created_at'),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['has_password', 'password_set_at']);
        });
    }
};
