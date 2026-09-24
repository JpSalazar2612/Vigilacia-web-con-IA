<?php

namespace Database\Factories;

use App\Models\Camera;
use App\Models\Detection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Detection>
 */
class DetectionFactory extends Factory
{
    protected $model = Detection::class;

    public function definition(): array
    {
        $event = fake()->randomElement(Detection::EVENTS);

        return [
            'camera_id' => Camera::factory(),
            'track_id' => (string) fake()->numberBetween(8000, 8999),
            'event_type' => $event,
            'confidence' => fake()->randomFloat(2, 0.65, 0.99),
            'bbox' => [
                'x' => fake()->randomFloat(3, 0, 0.8),
                'y' => fake()->randomFloat(3, 0, 0.8),
                'w' => fake()->randomFloat(3, 0.05, 0.3),
                'h' => fake()->randomFloat(3, 0.1, 0.5),
            ],
            'snapshot_path' => 'detections/' . fake()->uuid() . '.jpg',
            'meta' => [
                'distance_m' => fake()->randomFloat(1, 0.5, 15),
                'zone' => 'East Fence',
            ],
            'detected_at' => now()->subMinutes(fake()->numberBetween(0, 120)),
        ];
    }

    public function breach(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_type' => Detection::EVENT_BREACH_CONFIRMED,
            'confidence' => fake()->randomFloat(2, 0.9, 0.99),
        ]);
    }
}
