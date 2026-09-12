<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('billing_digest_recipients')) {
            return;
        }

        Schema::create('billing_digest_recipients', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255);
            $table->unsignedBigInteger('added_by')->nullable();

            $table->foreign('added_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->unique('email');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('billing_digest_recipients')) {
            Schema::drop('billing_digest_recipients');
        }
    }
};
