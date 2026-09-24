<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('camera_id')->constrained()->cascadeOnDelete();
            $table->string('track_id');
            $table->string('event_type');
            $table->decimal('confidence', 4, 2)->default(0);
            $table->json('bbox')->nullable();
            $table->string('snapshot_path')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('detected_at')->useCurrent();
            $table->timestamps();

            $table->index(['track_id', 'event_type', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detections');
    }
};
