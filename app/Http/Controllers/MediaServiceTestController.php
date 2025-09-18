<?php

namespace App\Http\Controllers;

use App\Services\AuthService;
use App\Services\ContentService;
use App\Services\EngagementService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MediaServiceTestController extends Controller
{
    protected AuthService $authService;
    protected ContentService $contentService;
    protected EngagementService $engagementService;

    public function __construct(
        AuthService $authService,
        ContentService $contentService,
        EngagementService $engagementService
    ) {
        $this->authService = $authService;
        $this->contentService = $contentService;
        $this->engagementService = $engagementService;
    }

    public function testAuthService(Request $request): JsonResponse
    {
        try {
            $token = $request->bearerToken();

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'No authentication token provided'
                ], 401);
            }

            $user = $request->attributes->get('user');
            $userId = $user['user_id'] ?? 1;

            $tests = [
                'user_profile' => $this->authService->getUserProfile($userId, $token),
                'user_access_validation' => $this->authService->validateUserAccess($userId, $token)
            ];

            return response()->json([
                'success' => true,
                'message' => 'Auth service test completed',
                'service' => 'auth',
                'results' => $tests,
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Auth service test failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function testContentService(Request $request): JsonResponse
    {
        try {
            $token = $request->bearerToken();

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'No authentication token provided'
                ], 401);
            }

            $exerciseId = 1; // Test exercise ID

            $tests = [
                'get_exercise' => $this->contentService->getExercise($token, $exerciseId),
                'notify_media_upload' => $this->contentService->notifyMediaUpload($token, $exerciseId, 999)
            ];

            return response()->json([
                'success' => true,
                'message' => 'Content service test completed',
                'service' => 'content',
                'results' => $tests,
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Content service test failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function testEngagementService(Request $request): JsonResponse
    {
        try {
            $token = $request->bearerToken();

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'No authentication token provided'
                ], 401);
            }

            $user = $request->attributes->get('user');
            $userId = $user['user_id'] ?? 1;
            $videoId = 1; // Test video ID

            $tests = [
                'track_video_view' => $this->engagementService->trackVideoView($token, $userId, $videoId, 120),
                'get_user_preferences' => $this->engagementService->getUserPreferences($token, $userId)
            ];

            return response()->json([
                'success' => true,
                'message' => 'Engagement service test completed',
                'service' => 'engagement',
                'results' => $tests,
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Engagement service test failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function testAllServices(Request $request): JsonResponse
    {
        try {
            $token = $request->bearerToken();

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'No authentication token provided'
                ], 401);
            }

            $allTests = [
                'auth_service' => $this->testAuthService($request)->getData(),
                'content_service' => $this->testContentService($request)->getData(),
                'engagement_service' => $this->testEngagementService($request)->getData()
            ];

            $overallSuccess = true;
            foreach ($allTests as $test) {
                if (!$test->success) {
                    $overallSuccess = false;
                    break;
                }
            }

            return response()->json([
                'success' => $overallSuccess,
                'message' => $overallSuccess ? 'All service tests completed successfully' : 'Some service tests failed',
                'results' => $allTests,
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Service testing failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}