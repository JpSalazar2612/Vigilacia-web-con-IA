<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro facial asociado a una Detection.
 *
 * Guarda recorte facial + hash del embedding para
 * re-identificar a quien brinque la defensa.
 * No guarda el vector crudo por privacidad, solo hash.
 */
class PersonFace extends Model
{
    use HasFactory;

    protected $fillable = [
        'detection_id',
        'camera_id',
        'track_id',
        'face_image_path',
        'face_encoding_hash',
        'confidence',
        'label',
        'notes',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:2',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function detection(): BelongsTo
    {
        return $this->belongsTo(Detection::class);
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    public function scopeForTrack($query, string $trackId)
    {
        return $query->where('track_id', $trackId);
    }
}
