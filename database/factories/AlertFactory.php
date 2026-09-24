<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\Camera;
use App\Models\Detection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    protected $model = Alert::class;

    public function definition(): array
    {
        return [
            'camera_id' => Camera::factory(),
            'detection_id' => Detection::factory(),
            'level' => fake()->randomElement([Alert::LEVEL_INFO, Alert::LEVEL_WARNING, Alert::LEVEL_CRITICAL]),
            'message' => fake()->randomElement([
                'Persona acercándose al perímetro',
                'Persona escalando defensa - East Fence',
                'Brecha perimetral confirmada',
            ]),
            'status' => Alert::STATUS_PENDING,
            'sent_at' => now(),
            'acknowledged_at' => null,
        ];
    }

    public function critical(): static
    {
        return $this->state(fn (array $attributes) => [
            'level' => Alert::LEVEL_CRITICAL,
            'message' => 'Brecha perimetral confirmada',
        ]);
    }
}
