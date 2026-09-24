<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Detección generada por IA desde una cámara.
 *
 * Flujo del video referencia:
 * approaching -> climbing -> breach_confirmed
 * + face_captured para registro facial.
 *
 * La inferencia IA vive en App\Services\DetectionService,
 * aquí solo persistencia.
 */
class Detection extends Model
{
    use HasFactory;

    public const EVENT_APPROACHING = 'approaching';
    public const EVENT_CLIMBING = 'climbing';
    public const EVENT_BREACH_CONFIRMED = 'breach_confirmed';
    public const EVENT_LOITERING = 'loitering';
    public const EVENT_FACE_CAPTURED = 'face_captured';

    public const EVENTS = [
        self::EVENT_APPROACHING,
        self::EVENT_CLIMBING,
        self::EVENT_BREACH_CONFIRMED,
        self::EVENT_LOITERING,
        self::EVENT_FACE_CAPTURED,
    ];

    protected $fillable = [
        'camera_id',
        'track_id',
        'event_type',
        'confidence',
        'bbox',
        'snapshot_path',
        'meta',
        'detected_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:2',
            'bbox' => 'array',
            'meta' => 'array',
            'detected_at' => 'datetime',
        ];
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    public function alert(): HasOne
    {
        return $this->hasOne(Alert::class);
    }

    public function scopeBreach($query)
    {
        return $query->where('event_type', self::EVENT_BREACH_CONFIRMED);
    }

    public function scopeForTrack($query, string $trackId)
    {
        return $query->where('track_id', $trackId);
    }
}
