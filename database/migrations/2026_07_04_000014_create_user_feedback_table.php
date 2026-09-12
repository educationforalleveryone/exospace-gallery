<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('category')->default('other'); // bug, feature_request, praise, other
            $table->text('message');
            $table->string('page_url')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('status')->default('new'); // new, reviewed, resolved
            $table->timestamps();

            $table->index('status');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_feedback');
    }
};
