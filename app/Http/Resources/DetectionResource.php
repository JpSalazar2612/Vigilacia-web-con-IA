<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Detection */
class DetectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'track_id' => $this->track_id,
            'event_type' => $this->event_type,
            'confidence' => $this->confidence,
            'bbox' => $this->bbox,
            'snapshot_path' => $this->snapshot_path,
            'camera' => [
                'id' => $this->camera?->id,
                'name' => $this->camera?->name,
                'zone' => $this->camera?->zone,
            ],
            'detected_at' => $this->detected_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
