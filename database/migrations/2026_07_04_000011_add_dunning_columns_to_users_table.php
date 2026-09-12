<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->tinyInteger('dunning_step')->nullable()->after('subscription_ends_at');

            $table->timestamp('dunning_last_sent_at')->nullable()->after('dunning_step');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['dunning_step', 'dunning_last_sent_at']);
        });
    }
};
