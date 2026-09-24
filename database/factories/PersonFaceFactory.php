<?php

namespace Database\Factories;

use App\Models\Camera;
use App\Models\Detection;
use App\Models\PersonFace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PersonFace>
 */
class PersonFaceFactory extends Factory
{
    protected $model = PersonFace::class;

    public function definition(): array
    {
        return [
            'detection_id' => Detection::factory(),
            'camera_id' => Camera::factory(),
            'track_id' => (string) fake()->numberBetween(8000, 8999),
            'face_image_path' => 'faces/' . fake()->uuid() . '.jpg',
            'face_encoding_hash' => hash('sha256', Str::random(32)),
            'confidence' => fake()->randomFloat(2, 0.7, 0.99),
            'label' => 'Desconocido-' . fake()->numberBetween(100, 999),
            'notes' => null,
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now(),
        ];
    }
}
