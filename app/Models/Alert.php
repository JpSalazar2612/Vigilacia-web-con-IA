<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alerta generada cuando una Detection lo requiere
 * (ej: breach_confirmed o climbing con alta confianza).
 *
 * La creación/envío vive en App\Services\AlertService.
 */
class Alert extends Model
{
    use HasFactory;

    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_CRITICAL = 'critical';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'camera_id',
        'detection_id',
        'level',
        'message',
        'status',
        'sent_at',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    public function detection(): BelongsTo
    {
        return $this->belongsTo(Detection::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeCritical($query)
    {
        return $query->where('level', self::LEVEL_CRITICAL);
    }
}
