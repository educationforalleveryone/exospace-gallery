<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_signups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_id')->constrained()->onDelete('cascade');
            $table->string('email', 255);
            $table->string('name', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('referrer', 255)->nullable();
            $table->timestamp('signed_up_at')->useCurrent();
            $table->timestamps();

            // One signup per email per gallery
            $table->unique(['gallery_id', 'email']);
            $table->index('email');
            $table->index('signed_up_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_signups');
    }
};
