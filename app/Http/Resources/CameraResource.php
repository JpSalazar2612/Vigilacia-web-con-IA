<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Camera */
class CameraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'location' => $this->location,
            'zone' => $this->zone,
            'stream_url' => $this->stream_url,
            'status' => $this->status,
            'is_active' => $this->is_active,
            'ai_enabled' => $this->ai_enabled,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'detections_count' => $this->whenCounted('detections'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
