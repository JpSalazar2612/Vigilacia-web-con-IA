<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Detection;
use App\Repositories\AlertRepository;

class AlertService
{
    public function __construct(protected AlertRepository $alerts) {}
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

        return $this->alerts->create([
            'camera_id' => $detection->camera_id,
            'detection_id' => $detection->id,
            'level' => $level,
            'message' => $message,
            'status' => Alert::STATUS_PENDING,
            'sent_at' => now(),
        ]);
    }

    public function acknowledge(Alert $alert): Alert
    {
        return $this->alerts->update($alert, [
            'status' => Alert::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => now(),
        ]);
    }
}
