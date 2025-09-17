<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EngagementService
{
    private $baseUrl;

    public function __construct()
    {
        $this->baseUrl = env('ENGAGEMENT_SERVICE_URL', 'http://localhost:8003');
    }

    /**
     * Track video view analytics
     */
    public function trackVideoView($token, $userId, $videoId, $watchDuration = null)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ])->post($this->baseUrl . '/api/engagement/analytics/video-view', [
                'user_id' => $userId,
                'video_id' => $videoId,
                'watch_duration' => $watchDuration,
                'timestamp' => now()->toISOString()
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Failed to track video view in engagement service', [
                'user_id' => $userId,
                'video_id' => $videoId,
                'status' => $response->status()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Engagement service communication error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get user preferences for video recommendations
     */
    public function getUserPreferences($token, $userId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json'
            ])->get($this->baseUrl . '/api/engagement/users/' . $userId . '/preferences');

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Failed to get user preferences from engagement service', [
                'user_id' => $userId,
                'status' => $response->status()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Engagement service communication error: ' . $e->getMessage());
            return null;
        }
    }
}