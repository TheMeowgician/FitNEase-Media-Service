<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudioContent extends Model
{
    protected $table = 'audio_content';
    protected $primaryKey = 'audio_id';

    protected $fillable = [
        'media_file_id',
        'audio_title',
        'audio_description',
        'duration_seconds',
        'audio_type',
        'audio_quality',
        'genre',
        'is_playlist_eligible',
        'play_count'
    ];

    protected $casts = [
        'media_file_id' => 'integer',
        'duration_seconds' => 'integer',
        'is_playlist_eligible' => 'boolean',
        'play_count' => 'integer'
    ];

    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_file_id', 'media_file_id');
    }

    public function scopeByType($query, $type)
    {
        return $query->where('audio_type', $type);
    }

    public function scopeByGenre($query, $genre)
    {
        return $query->where('genre', $genre);
    }

    public function scopePlaylistEligible($query)
    {
        return $query->where('is_playlist_eligible', true);
    }

    public function scopePopular($query, $limit = 10)
    {
        return $query->orderBy('play_count', 'desc')->limit($limit);
    }

    public function getDurationFormattedAttribute()
    {
        if (!$this->duration_seconds) {
            return null;
        }

        $hours = floor($this->duration_seconds / 3600);
        $minutes = floor(($this->duration_seconds % 3600) / 60);
        $seconds = $this->duration_seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    public function incrementPlayCount()
    {
        $this->increment('play_count');
    }
}
