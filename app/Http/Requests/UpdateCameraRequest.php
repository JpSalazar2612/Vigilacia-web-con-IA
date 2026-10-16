<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCameraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $cameraId = $this->route('camera');

        return [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('cameras', 'name')->ignore($cameraId)],
            'location' => ['nullable', 'string', 'max:255'],
            'zone' => ['sometimes', 'string', 'max:100'],
            'stream_url' => ['sometimes', 'string', 'max:500'],
            'snapshot_url' => ['nullable', 'string', 'max:500'],
            'fence_line' => ['nullable', 'array'],
            'status' => ['sometimes', 'in:online,offline,maintenance'],
            'is_active' => ['sometimes', 'boolean'],
            'ai_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
