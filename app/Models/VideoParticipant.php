<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoParticipant extends Model
{
    protected $fillable = [
        'room_id',
        'user_id',
        'joined_at',
        'left_at',
        'duration_seconds'
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the room this participant belongs to
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(VideoRoom::class, 'room_id');
    }

    /**
     * Mark participant as left and calculate duration
     */
    public function markAsLeft(): void
    {
        $leftAt = now();
        $duration = $leftAt->diffInSeconds($this->joined_at);

        $this->update([
            'left_at' => $leftAt,
            'duration_seconds' => $duration
        ]);
    }

    /**
     * Check if participant is still in the room
     */
    public function isActive(): bool
    {
        return $this->left_at === null;
    }

    /**
     * Get formatted duration
     */
    public function getDurationFormattedAttribute(): string
    {
        if (!$this->duration_seconds) {
            return '0m 0s';
        }

        $minutes = floor($this->duration_seconds / 60);
        $seconds = $this->duration_seconds % 60;

        return "{$minutes}m {$seconds}s";
    }

    /**
     * Scope for active participants
     */
    public function scopeActive($query)
    {
        return $query->whereNull('left_at');
    }

    /**
     * Scope for participants who left
     */
    public function scopeLeft($query)
    {
        return $query->whereNotNull('left_at');
    }
}
