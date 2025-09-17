<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContentService
{
    private $baseUrl;

    public function __construct()
    {
        $this->baseUrl = env('CONTENT_SERVICE_URL', 'http://localhost:8002');
    }

    /**
     * Get exercise details from content service
     */
    public function getExercise($token, $exerciseId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json'
            ])->get($this->baseUrl . '/api/content/exercises/' . $exerciseId);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Failed to get exercise from content service', [
                'exercise_id' => $exerciseId,
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Content service communication error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Notify content service about media upload
     */
    public function notifyMediaUpload($token, $exerciseId, $mediaFileId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ])->post($this->baseUrl . '/api/content/exercises/' . $exerciseId . '/media', [
                'media_file_id' => $mediaFileId,
                'notification_type' => 'media_upload'
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Failed to notify content service about media upload', [
                'exercise_id' => $exerciseId,
                'media_file_id' => $mediaFileId,
                'status' => $response->status()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Content service communication error: ' . $e->getMessage());
            return null;
        }
    }
}