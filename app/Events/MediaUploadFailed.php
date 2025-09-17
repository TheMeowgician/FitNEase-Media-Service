<?php

namespace App\Events;

use App\Models\MediaFile;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MediaUploadFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public MediaFile $mediaFile;
    public string $errorMessage;

    public function __construct(MediaFile $mediaFile, string $errorMessage = '')
    {
        $this->mediaFile = $mediaFile;
        $this->errorMessage = $errorMessage;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('media.processing.' . $this->mediaFile->uploaded_by),
            new PrivateChannel('media.file.' . $this->mediaFile->media_file_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'media.upload.failed';
    }

    public function broadcastWith(): array
    {
        return [
            'media_file_id' => $this->mediaFile->media_file_id,
            'file_name' => $this->mediaFile->original_file_name,
            'file_type' => $this->mediaFile->file_type,
            'upload_status' => $this->mediaFile->upload_status,
            'error_message' => $this->errorMessage,
            'failed_at' => now()->toISOString()
        ];
    }
}
