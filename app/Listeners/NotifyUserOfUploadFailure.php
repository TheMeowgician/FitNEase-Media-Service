<?php

namespace App\Listeners;

use App\Events\MediaUploadFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

class NotifyUserOfUploadFailure implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct()
    {
        //
    }

    public function handle(MediaUploadFailed $event): void
    {
        $mediaFile = $event->mediaFile;
        $errorMessage = $event->errorMessage;

        try {
            // Log the failure
            Log::error('Media upload failed', [
                'media_file_id' => $mediaFile->media_file_id,
                'file_name' => $mediaFile->original_file_name,
                'file_type' => $mediaFile->file_type,
                'uploaded_by' => $mediaFile->uploaded_by,
                'error_message' => $errorMessage
            ]);

            // Send notification to user
            $this->notifyUser($mediaFile, $errorMessage);

            // Track analytics event
            $this->trackUploadFailure($mediaFile, $errorMessage);

            // Clean up failed upload files
            $this->cleanupFailedUpload($mediaFile);

        } catch (\Exception $e) {
            Log::error('Failed to handle media upload failure', [
                'media_file_id' => $mediaFile->media_file_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function notifyUser($mediaFile, string $errorMessage): void
    {
        try {
            $client = new Client();

            $notificationData = [
                'user_id' => $mediaFile->uploaded_by,
                'type' => 'media_upload_failed',
                'title' => 'Media Upload Failed',
                'message' => "Failed to process your {$mediaFile->file_type} '{$mediaFile->original_file_name}'. Please try uploading again.",
                'data' => [
                    'media_file_id' => $mediaFile->media_file_id,
                    'file_type' => $mediaFile->file_type,
                    'error_message' => $errorMessage,
                    'suggestions' => $this->getUploadSuggestions($mediaFile, $errorMessage)
                ]
            ];

            $response = $client->post(env('AUTH_SERVICE_URL') . '/notifications', [
                'json' => $notificationData,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . env('INTERNAL_SERVICE_TOKEN')
                ]
            ]);

            Log::info('User failure notification sent successfully', [
                'media_file_id' => $mediaFile->media_file_id,
                'user_id' => $mediaFile->uploaded_by
            ]);

        } catch (\Exception $e) {
            Log::warning('Failed to send user failure notification', [
                'media_file_id' => $mediaFile->media_file_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function trackUploadFailure($mediaFile, string $errorMessage): void
    {
        try {
            app('App\Services\ContentAnalyticsService')->trackContentUsage(
                $mediaFile->media_file_id,
                $mediaFile->uploaded_by,
                'upload_failed',
                [
                    'file_type' => $mediaFile->file_type,
                    'file_size' => $mediaFile->file_size_bytes,
                    'error_message' => $errorMessage,
                    'upload_duration' => now()->diffInSeconds($mediaFile->uploaded_at)
                ]
            );

        } catch (\Exception $e) {
            Log::warning('Failed to track upload failure', [
                'media_file_id' => $mediaFile->media_file_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function cleanupFailedUpload($mediaFile): void
    {
        try {
            // Remove the failed upload file if it exists
            if (\Storage::exists($mediaFile->file_path)) {
                \Storage::delete($mediaFile->file_path);
            }

            // Remove any partial thumbnail files
            if ($mediaFile->thumbnail_path && \Storage::exists($mediaFile->thumbnail_path)) {
                \Storage::delete($mediaFile->thumbnail_path);
            }

            // Remove any processing directories
            $processingDir = 'processed/' . $mediaFile->media_file_id;
            if (\Storage::exists($processingDir)) {
                \Storage::deleteDirectory($processingDir);
            }

            // Remove the database record after a delay to allow for debugging
            $this->delay(now()->addHours(24))->release();

        } catch (\Exception $e) {
            Log::warning('Failed to cleanup failed upload', [
                'media_file_id' => $mediaFile->media_file_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function getUploadSuggestions($mediaFile, string $errorMessage): array
    {
        $suggestions = [];

        if (str_contains($errorMessage, 'size')) {
            $suggestions[] = 'Try uploading a smaller file (under 500MB for videos, 100MB for audio)';
        }

        if (str_contains($errorMessage, 'format') || str_contains($errorMessage, 'type')) {
            if ($mediaFile->file_type === 'video') {
                $suggestions[] = 'Use supported video formats: MP4, AVI, MOV, WMV';
            } elseif ($mediaFile->file_type === 'audio') {
                $suggestions[] = 'Use supported audio formats: MP3, WAV, AAC, OGG';
            }
        }

        if (str_contains($errorMessage, 'duration')) {
            $suggestions[] = 'Videos should be under 1 hour in length';
        }

        if (str_contains($errorMessage, 'network') || str_contains($errorMessage, 'timeout')) {
            $suggestions[] = 'Check your internet connection and try again';
            $suggestions[] = 'If the problem persists, try uploading during off-peak hours';
        }

        if (empty($suggestions)) {
            $suggestions[] = 'Please contact support if the problem persists';
        }

        return $suggestions;
    }

    public function failed(MediaUploadFailed $event, $exception): void
    {
        Log::error('Failed to process MediaUploadFailed event', [
            'media_file_id' => $event->mediaFile->media_file_id,
            'exception' => $exception->getMessage()
        ]);
    }
}
