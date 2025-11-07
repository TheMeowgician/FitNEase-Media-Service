<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoRoom extends Model
{
    protected $fillable = [
        'session_id',
        'hms_room_id',
        'status',
        'closed_at'
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get all participants in this room
     */
    public function participants(): HasMany
    {
        return $this->hasMany(VideoParticipant::class, 'room_id');
    }

    /**
     * Get active participants (still in the room)
     */
    public function activeParticipants(): HasMany
    {
        return $this->participants()->whereNull('left_at');
    }

    /**
     * Get recordings for this room
     */
    public function recordings(): HasMany
    {
        return $this->hasMany(VideoRecording::class, 'room_id');
    }

    /**
     * Close the room
     */
    public function close(): void
    {
        $this->update([
            'status' => 'closed',
            'closed_at' => now()
        ]);

        // Mark all active participants as left
        $this->activeParticipants()->update([
            'left_at' => now()
        ]);
    }

    /**
     * Check if room is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Scope for active rooms
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for closed rooms
     */
    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }
}
