<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_faces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('detection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('camera_id')->nullable()->constrained()->nullOnDelete();
            $table->string('track_id')->nullable();
            $table->string('face_image_path')->nullable();
            $table->string('face_encoding_hash', 64)->nullable();
            $table->decimal('confidence', 4, 2)->default(0);
            $table->string('label')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['track_id', 'face_encoding_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_faces');
    }
};
