<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoRecording extends Model
{
    protected $fillable = [
        'room_id',
        'hms_recording_id',
        'recording_url',
        'duration_seconds',
        'size_mb',
        'status'
    ];

    protected $casts = [
        'size_mb' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the room this recording belongs to
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(VideoRoom::class, 'room_id');
    }

    /**
     * Get formatted duration
     */
    public function getDurationFormattedAttribute(): string
    {
        if (!$this->duration_seconds) {
            return '0m 0s';
        }

        $hours = floor($this->duration_seconds / 3600);
        $minutes = floor(($this->duration_seconds % 3600) / 60);
        $seconds = $this->duration_seconds % 60;

        if ($hours > 0) {
            return "{$hours}h {$minutes}m {$seconds}s";
        }

        return "{$minutes}m {$seconds}s";
    }

    /**
     * Check if recording is ready
     */
    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    /**
     * Check if recording is being processed
     */
    public function isProcessing(): bool
    {
        return in_array($this->status, ['recording', 'processing']);
    }

    /**
     * Mark recording as ready
     */
    public function markAsReady(string $url, int $duration, float $size): void
    {
        $this->update([
            'recording_url' => $url,
            'duration_seconds' => $duration,
            'size_mb' => $size,
            'status' => 'ready'
        ]);
    }

    /**
     * Mark recording as failed
     */
    public function markAsFailed(): void
    {
        $this->update(['status' => 'failed']);
    }

    /**
     * Scope for ready recordings
     */
    public function scopeReady($query)
    {
        return $query->where('status', 'ready');
    }

    /**
     * Scope for processing recordings
     */
    public function scopeProcessing($query)
    {
        return $query->whereIn('status', ['recording', 'processing']);
    }
}
