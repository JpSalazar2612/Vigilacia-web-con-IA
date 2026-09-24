<?php

namespace Database\Factories;

use App\Models\Camera;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Camera>
 */
class CameraFactory extends Factory
{
    protected $model = Camera::class;

    public function definition(): array
    {
        return [
            'name' => 'CAM-' . fake()->unique()->numberBetween(100, 999),
            'location' => fake()->randomElement(['Perímetro Norte', 'Perímetro Sur', 'Entrada Principal', 'Almacén']),
            'zone' => fake()->randomElement(['East Fence', 'West Fence', 'North Fence', 'South Gate']),
            'stream_url' => 'rtsp://vigilancia.local:554/' . fake()->slug(2),
            'snapshot_url' => null,
            'fence_line' => [
                ['x' => 0.1, 'y' => 0.8],
                ['x' => 0.9, 'y' => 0.6],
            ],
            'status' => fake()->randomElement(['online', 'online', 'online', 'offline']),
            'is_active' => true,
            'ai_enabled' => true,
            'last_seen_at' => now()->subMinutes(fake()->numberBetween(0, 15)),
        ];
    }

    public function offline(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'offline',
            'last_seen_at' => now()->subHours(2),
        ]);
    }
}
