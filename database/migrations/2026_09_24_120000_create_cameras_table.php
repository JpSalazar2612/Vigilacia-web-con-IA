<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cameras', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('location')->nullable();
            $table->string('zone')->default('East Fence');
            $table->string('stream_url');
            $table->string('snapshot_url')->nullable();
            $table->json('fence_line')->nullable();
            $table->string('status')->default('online');
            $table->boolean('is_active')->default(true);
            $table->boolean('ai_enabled')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['zone', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cameras');
    }
};
