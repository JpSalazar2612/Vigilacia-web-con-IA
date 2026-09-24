<?php

namespace App\Http\Requests;

use App\Models\Detection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDetectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'camera_id' => ['required', 'exists:cameras,id'],
            'track_id' => ['required', 'string', 'max:50'],
            'event_type' => ['required', Rule::in(Detection::EVENTS)],
            'confidence' => ['required', 'numeric', 'min:0', 'max:1'],
            'bbox' => ['nullable', 'array'],
            'bbox.x' => ['nullable', 'numeric'],
            'bbox.y' => ['nullable', 'numeric'],
            'bbox.w' => ['nullable', 'numeric'],
            'bbox.h' => ['nullable', 'numeric'],
            'snapshot_path' => ['nullable', 'string', 'max:500'],
            'meta' => ['nullable', 'array'],
            'detected_at' => ['nullable', 'date'],
        ];
    }
}
