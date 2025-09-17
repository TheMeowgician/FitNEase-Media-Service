<?php

namespace App\Listeners;

use App\Events\MediaProcessingCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

class NotifyUserOfProcessingCompletion implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct()
    {
        //
    }

    public function handle(MediaProcessingCompleted $event): void
    {
        $mediaFile = $event->mediaFile;

        try {
            // Log the completion
            Log::info('Media processing completed', [
                'media_file_id' => $mediaFile->media_file_id,
                'file_name' => $mediaFile->original_file_name,
                'file_type' => $mediaFile->file_type,
                'uploaded_by' => $mediaFile->uploaded_by
            ]);

            // Send notification to user via auth service
            $this->notifyUser($mediaFile);

            // Update any related content records
            $this->updateContentRecords($mediaFile);

            // Track analytics event
            $this->trackProcessingCompletion($mediaFile);

        } catch (\Exception $e) {
            Log::error('Failed to handle media processing completion', [
                'media_file_id' => $mediaFile->media_file_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function notifyUser($mediaFile): void
    {
        try {
            $client = new Client();

            $notificationData = [
                'user_id' => $mediaFile->uploaded_by,
                'type' => 'media_processing_completed',
                'title' => 'Media Processing Complete',
                'message' => "Your {$mediaFile->file_type} '{$mediaFile->original_file_name}' has been processed and is ready for streaming.",
                'data' => [
                    'media_file_id' => $mediaFile->media_file_id,
                    'file_type' => $mediaFile->file_type,
                    'streaming_url' => $mediaFile->streaming_url,
                    'thumbnail_url' => $mediaFile->thumbnail_url
                ]
            ];

            $response = $client->post(env('AUTH_SERVICE_URL') . '/notifications', [
                'json' => $notificationData,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . env('INTERNAL_SERVICE_TOKEN')
                ]
            ]);

            Log::info('User notification sent successfully', [
                'media_file_id' => $mediaFile->media_file_id,
                'user_id' => $mediaFile->uploaded_by
            ]);

        } catch (\Exception $e) {
            Log::warning('Failed to send user notification', [
                'media_file_id' => $mediaFile->media_file_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function updateContentRecords($mediaFile): void
    {
        try {
            // Auto-create video/audio content records if they don't exist
            if ($mediaFile->file_type === 'video' && !$mediaFile->videoContent) {
                $mediaFile->videoContent()->create([
                    'video_title' => $mediaFile->original_file_name,
                    'video_description' => 'Auto-generated from uploaded file',
                    'video_type' => 'instruction',
                    'difficulty_level' => 'beginner'
                ]);
            }

            if ($mediaFile->file_type === 'audio' && !$mediaFile->audioContent) {
                $mediaFile->audioContent()->create([
                    'audio_title' => $mediaFile->original_file_name,
                    'audio_description' => 'Auto-generated from uploaded file',
                    'audio_type' => 'music',
                    'genre' => 'fitness'
                ]);
            }

        } catch (\Exception $e) {
            Log::warning('Failed to create content records', [
                'media_file_id' => $mediaFile->media_file_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function trackProcessingCompletion($mediaFile): void
    {
        try {
            app('App\Services\ContentAnalyticsService')->trackContentUsage(
                $mediaFile->media_file_id,
                $mediaFile->uploaded_by,
                'processing_completed',
                [
                    'file_type' => $mediaFile->file_type,
                    'file_size' => $mediaFile->file_size_bytes,
                    'processing_duration' => now()->diffInSeconds($mediaFile->uploaded_at)
                ]
            );

        } catch (\Exception $e) {
            Log::warning('Failed to track processing completion', [
                'media_file_id' => $mediaFile->media_file_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function failed(MediaProcessingCompleted $event, $exception): void
    {
        Log::error('Failed to process MediaProcessingCompleted event', [
            'media_file_id' => $event->mediaFile->media_file_id,
            'exception' => $exception->getMessage()
        ]);
    }
}
