<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processed_webhooks', function (Blueprint $table) {
            $table->text('payload')->nullable();
            $table->string('status', 20)->default('processed')->index();
            $table->unsignedInteger('replay_count')->default(0);
            $table->timestamp('last_replayed_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        \DB::table('processed_webhooks')->whereNull('status')->update([
            'status'      => 'processed',
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        try {
            Schema::table('processed_webhooks', function (Blueprint $table) {
                $table->dropIndex('processed_webhooks_status_index');
            });
        } catch (\Throwable) {
        }

        Schema::table('processed_webhooks', function (Blueprint $table) {
            foreach (['payload', 'status', 'replay_count', 'last_replayed_at', 'updated_at'] as $column) {
                if (\Schema::hasColumn('processed_webhooks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
