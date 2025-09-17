<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoContent extends Model
{
    protected $table = 'video_content';
    protected $primaryKey = 'video_id';

    protected $fillable = [
        'media_file_id',
        'exercise_id',
        'video_title',
        'video_description',
        'duration_seconds',
        'video_type',
        'video_quality',
        'instructor_name',
        'difficulty_level',
        'view_count',
        'average_rating',
        'is_featured'
    ];

    protected $casts = [
        'media_file_id' => 'integer',
        'exercise_id' => 'integer',
        'duration_seconds' => 'integer',
        'view_count' => 'integer',
        'average_rating' => 'decimal:2',
        'is_featured' => 'boolean'
    ];

    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_file_id', 'media_file_id');
    }

    public function scopeByExercise($query, $exerciseId)
    {
        return $query->where('exercise_id', $exerciseId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('video_type', $type);
    }

    public function scopeByDifficulty($query, $difficulty)
    {
        return $query->where('difficulty_level', $difficulty);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopePopular($query, $limit = 10)
    {
        return $query->orderBy('view_count', 'desc')->limit($limit);
    }

    public function scopeHighRated($query, $minRating = 4.0)
    {
        return $query->where('average_rating', '>=', $minRating);
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

    public function incrementViewCount()
    {
        $this->increment('view_count');
    }

    public function updateRating($newRating, $totalRatings)
    {
        $this->update(['average_rating' => $newRating]);
    }
}
