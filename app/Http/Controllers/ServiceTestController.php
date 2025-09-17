<?php

namespace App\Http\Controllers;

use App\Services\ContentService;
use App\Services\EngagementService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ServiceTestController extends Controller
{
    public function testServiceCommunication(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        $userId = $request->attributes->get('user_id');

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'No authentication token provided'
            ], 401);
        }

        $results = [];

        // Test Content Service Communication
        try {
            $contentService = new ContentService();
            $exercise = $contentService->getExercise($token, 1); // Test with exercise ID 1

            $results['content_service'] = [
                'status' => $exercise ? 'success' : 'no_data',
                'response' => $exercise,
                'endpoint_tested' => 'GET /api/content/exercises/1'
            ];
        } catch (\Exception $e) {
            $results['content_service'] = [
                'status' => 'error',
                'error' => $e->getMessage(),
                'endpoint_tested' => 'GET /api/content/exercises/1'
            ];
        }

        // Test Engagement Service Communication
        if ($userId) {
            try {
                $engagementService = new EngagementService();
                $preferences = $engagementService->getUserPreferences($token, $userId);

                $results['engagement_service'] = [
                    'status' => $preferences ? 'success' : 'no_data',
                    'response' => $preferences,
                    'endpoint_tested' => "GET /api/engagement/users/{$userId}/preferences"
                ];
            } catch (\Exception $e) {
                $results['engagement_service'] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'endpoint_tested' => "GET /api/engagement/users/{$userId}/preferences"
                ];
            }
        }

        // Test tracking video view
        try {
            $engagementService = new EngagementService();
            $trackResult = $engagementService->trackVideoView($token, $userId ?: 1, 999, 120);

            $results['engagement_tracking'] = [
                'status' => $trackResult ? 'success' : 'no_response',
                'response' => $trackResult,
                'endpoint_tested' => 'POST /api/engagement/analytics/video-view'
            ];
        } catch (\Exception $e) {
            $results['engagement_tracking'] = [
                'status' => 'error',
                'error' => $e->getMessage(),
                'endpoint_tested' => 'POST /api/engagement/analytics/video-view'
            ];
        }

        return response()->json([
            'success' => true,
            'message' => 'Service communication test completed',
            'authenticated_user_id' => $userId,
            'token_provided' => $token ? 'yes' : 'no',
            'service_urls' => [
                'content' => env('CONTENT_SERVICE_URL'),
                'engagement' => env('ENGAGEMENT_SERVICE_URL')
            ],
            'test_results' => $results
        ]);
    }
}