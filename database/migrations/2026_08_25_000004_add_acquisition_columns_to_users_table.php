<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
        if (! Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
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
