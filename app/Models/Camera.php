<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cámara perimetral con IA (Fence-Line Intrusion Detection).
 *
 * Representa un punto de vigilancia físico (ej: "East Fence").
 * No contiene lógica de IA: solo datos + relaciones.
 */
class Camera extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'location',
        'zone',
        'stream_url',
        'snapshot_url',
        'fence_line',
        'status',
        'is_active',
        'ai_enabled',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'fence_line' => 'array',
            'is_active' => 'boolean',
            'ai_enabled' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return HasMany<Detection> */
    public function detections(): HasMany
    {
        return $this->hasMany(Detection::class);
    }

    /** @return HasMany<Alert> */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOnline($query)
    {
        return $query->where('status', 'online');
    }
}
