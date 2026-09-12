<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('subscription_id')->nullable()->after('plan_expires_at');

            $table->string('subscription_status')->nullable()->after('subscription_id');

            $table->timestamp('subscription_cancelled_at')->nullable()->after('subscription_status');

            $table->timestamp('subscription_ends_at')->nullable()->after('subscription_cancelled_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'subscription_id',
                'subscription_status',
                'subscription_cancelled_at',
                'subscription_ends_at',
            ]);
        });
    }
};
