<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FileMetadata extends Model
{
    protected $table = 'file_metadata';
    protected $primaryKey = 'metadata_id';
    public $timestamps = false;

    protected $fillable = [
        'media_file_id',
        'metadata_key',
        'metadata_value',
        'metadata_type'
    ];

    protected $casts = [
        'media_file_id' => 'integer',
        'created_at' => 'datetime'
    ];

    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_file_id', 'media_file_id');
    }

    public function getValueAttribute()
    {
        return match ($this->metadata_type) {
            'integer' => (int) $this->metadata_value,
            'decimal' => (float) $this->metadata_value,
            'boolean' => filter_var($this->metadata_value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->metadata_value, true),
            default => $this->metadata_value,
        };
    }

    public function setValueAttribute($value)
    {
        $this->metadata_value = match ($this->metadata_type) {
            'json' => json_encode($value),
            'boolean' => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    public function scopeByKey($query, $key)
    {
        return $query->where('metadata_key', $key);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('metadata_type', $type);
    }
}
