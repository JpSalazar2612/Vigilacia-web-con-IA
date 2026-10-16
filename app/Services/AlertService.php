<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Detection;
use App\Repositories\AlertRepository;

class AlertService
{
    public function __construct(
        protected AlertRepository $alerts,
        protected BlynkService $blynk,
    ) {}
    public function fromDetection(Detection $detection): Alert
    {
        $level = match ($detection->event_type) {
            Detection::EVENT_BREACH_CONFIRMED => Alert::LEVEL_CRITICAL,
            Detection::EVENT_CLIMBING => Alert::LEVEL_WARNING,
            default => Alert::LEVEL_INFO,
        };

        $message = match ($detection->event_type) {
            Detection::EVENT_BREACH_CONFIRMED => "Brecha perimetral confirmada - Track {$detection->track_id}",
            Detection::EVENT_CLIMBING => "Persona escalando defensa - Track {$detection->track_id}",
            default => "Evento {$detection->event_type} - Track {$detection->track_id}",
        };

        $alert = $this->alerts->create([
            'camera_id' => $detection->camera_id,
            'detection_id' => $detection->id,
            'level' => $level,
            'message' => $message,
            'status' => Alert::STATUS_PENDING,
            'sent_at' => now(),
        ]);

        // --- Puente a Blynk (no rompe si Blynk está apagado o falla) ---
        try {
            $detection->loadMissing('camera');
            $zone = $detection->camera?->zone ?? 'tu casa';
            $pushMessage = $level === Alert::LEVEL_CRITICAL
                ? "¡Alguien entró a tu casa! ({$zone} - Track {$detection->track_id})"
                : "{$message} ({$zone})";

            $imageUrl = $detection->snapshot_path
                ? url('storage/'.ltrim($detection->snapshot_path, '/'))
                : null;

            if ($level === Alert::LEVEL_CRITICAL || $level === Alert::LEVEL_WARNING) {
                $this->blynk->pushIntrusion($pushMessage, $imageUrl, true);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Blynk push omitido: '.$e->getMessage());
        }

        return $alert;
    }

    public function acknowledge(Alert $alert): Alert
    {
        $updated = $this->alerts->update($alert, [
            'status' => Alert::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => now(),
        ]);

        try {
            $this->blynk->clearPresence();
        } catch (\Throwable $e) {
            // Silencioso: atender la alerta no debe fallar por Blynk.
        }

        return $updated;
    }
}
