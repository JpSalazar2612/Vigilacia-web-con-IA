<?php

namespace App\Services;

use App\Models\Detection;
use App\Models\PersonFace;
use App\Repositories\DetectionRepository;

class DetectionService
{
    public function __construct(
        protected DetectionRepository $detections,
        protected AlertService $alerts,
    ) {}

    /**
     * Punto único de ingreso IA -> DB.
     * El Controller solo valida y llama aquí.
     */
    public function ingest(array $validated): Detection
    {
        $detection = $this->detections->create([
            'camera_id' => $validated['camera_id'],
            'track_id' => $validated['track_id'],
            'event_type' => $validated['event_type'],
            'confidence' => $validated['confidence'],
            'bbox' => $validated['bbox'] ?? null,
            'snapshot_path' => $validated['snapshot_path'] ?? null,
            'meta' => $validated['meta'] ?? null,
            'detected_at' => $validated['detected_at'] ?? now(),
        ]);

        if ($this->shouldAlert($detection)) {
            $this->alerts->fromDetection($detection);
        }

        return $detection->load('camera');
    }

    public function shouldAlert(Detection $detection): bool
    {
        if ($detection->event_type === Detection::EVENT_BREACH_CONFIRMED) {
            return true;
        }

        if ($detection->event_type === Detection::EVENT_CLIMBING && (float) $detection->confidence >= 0.8) {
            return true;
        }

        return false;
    }

    public function registerFace(Detection $detection, array $face): PersonFace
    {
        return PersonFace::create([
            'detection_id' => $detection->id,
            'camera_id' => $detection->camera_id,
            'track_id' => $detection->track_id,
            'face_image_path' => $face['face_image_path'] ?? null,
            'face_encoding_hash' => $face['face_encoding_hash'] ?? null,
            'confidence' => $face['confidence'] ?? $detection->confidence,
            'label' => $face['label'] ?? null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
