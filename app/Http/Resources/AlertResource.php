<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Alert */
class AlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'level' => $this->level,
            'message' => $this->message,
            'status' => $this->status,
            'camera' => [
                'id' => $this->camera?->id,
                'name' => $this->camera?->name,
                'zone' => $this->camera?->zone,
            ],
            'detection' => [
                'id' => $this->detection?->id,
                'track_id' => $this->detection?->track_id,
                'event_type' => $this->detection?->event_type,
                'confidence' => $this->detection?->confidence,
            ],
            'sent_at' => $this->sent_at?->toIso8601String(),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
        ];
    }
}
