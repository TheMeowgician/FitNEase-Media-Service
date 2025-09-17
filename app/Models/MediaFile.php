<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MediaFile extends Model
{
    protected $table = 'media_files';
    protected $primaryKey = 'media_file_id';

    protected $fillable = [
        'file_name',
        'original_file_name',
        'file_path',
        'file_type',
        'file_size_bytes',
        'mime_type',
        'uploaded_by',
        'entity_type',
        'entity_id',
        'is_public',
        'is_active',
        'cdn_url',
        'thumbnail_path',
        'upload_status',
        'uploaded_at'
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'is_active' => 'boolean',
        'file_size_bytes' => 'integer',
        'uploaded_by' => 'integer',
        'entity_id' => 'integer',
        'uploaded_at' => 'datetime'
    ];

    public function videoContent(): HasOne
    {
        return $this->hasOne(VideoContent::class, 'media_file_id', 'media_file_id');
    }

    public function audioContent(): HasOne
    {
        return $this->hasOne(AudioContent::class, 'media_file_id', 'media_file_id');
    }

    public function metadata(): HasMany
    {
        return $this->hasMany(FileMetadata::class, 'media_file_id', 'media_file_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('file_type', $type);
    }

    public function scopeByEntity($query, $entityType, $entityId = null)
    {
        $query = $query->where('entity_type', $entityType);

        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }

        return $query;
    }

    public function scopeReady($query)
    {
        return $query->where('upload_status', 'ready');
    }

    public function getFileSizeHumanAttribute()
    {
        $bytes = $this->file_size_bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function getThumbnailUrlAttribute()
    {
        if ($this->thumbnail_path) {
            return $this->cdn_url ?
                str_replace($this->file_path, $this->thumbnail_path, $this->cdn_url) :
                asset('storage/' . $this->thumbnail_path);
        }

        return null;
    }

    public function getStreamingUrlAttribute()
    {
        if ($this->file_type === 'video' && $this->upload_status === 'ready') {
            return route('media.stream.video', ['videoId' => $this->videoContent?->video_id]);
        }

        if ($this->file_type === 'audio' && $this->upload_status === 'ready') {
            return route('media.stream.audio', ['audioId' => $this->audioContent?->audio_id]);
        }

        return null;
    }
}
