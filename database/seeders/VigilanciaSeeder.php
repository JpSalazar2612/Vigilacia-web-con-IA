<?php

namespace Database\Seeders;

use App\Models\Camera;
use App\Models\Detection;
use App\Services\DetectionService;
use Illuminate\Database\Seeder;

class VigilanciaSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(DetectionService::class);
        $camera = Camera::where('zone', 'East Fence')->first() ?? Camera::factory()->create();

        // Guion del video: approaching -> climbing -> breach (track 8492)
        foreach ([Detection::EVENT_APPROACHING, Detection::EVENT_CLIMBING, Detection::EVENT_BREACH_CONFIRMED] as $event) {
            $detection = $service->ingest([
                'camera_id' => $camera->id,
                'track_id' => '8492',
                'event_type' => $event,
                'confidence' => $event === Detection::EVENT_BREACH_CONFIRMED ? 0.96 : 0.85,
            ]);

            if ($event === Detection::EVENT_BREACH_CONFIRMED) {
                $service->registerFace($detection, [
                    'face_image_path' => 'faces/demo-8492.jpg',
                    'face_encoding_hash' => hash('sha256', 'demo-8492'),
                    'confidence' => 0.91,
                    'label' => 'Desconocido-8492',
                ]);
            }
        }

        // Ruido de fondo
        Detection::factory()->count(10)->create();
    }
}
