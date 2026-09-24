<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCameraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:cameras,name'],
            'location' => ['nullable', 'string', 'max:255'],
            'zone' => ['required', 'string', 'max:100'],
            'stream_url' => ['required', 'string', 'max:500'],
            'snapshot_url' => ['nullable', 'string', 'max:500'],
            'fence_line' => ['nullable', 'array'],
            'status' => ['sometimes', 'in:online,offline,maintenance'],
            'is_active' => ['sometimes', 'boolean'],
            'ai_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
