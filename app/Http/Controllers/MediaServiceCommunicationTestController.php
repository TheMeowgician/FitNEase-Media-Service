<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class MediaServiceCommunicationTestController extends Controller
{
    public function testServiceConnectivity(Request $request): JsonResponse
    {
        try {
            $token = $request->bearerToken();

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'No authentication token provided'
                ], 401);
            }

            $services = [
                'auth' => env('AUTH_SERVICE_URL', 'http://fitnease-auth'),
                'content' => env('CONTENT_SERVICE_URL', 'http://fitnease-content'),
                'engagement' => env('ENGAGEMENT_SERVICE_URL', 'http://fitnease-engagement'),
                'comms' => env('COMMS_SERVICE_URL', 'http://fitnease-comms'),
                'tracking' => env('TRACKING_SERVICE_URL', 'http://fitnease-tracking')
            ];

            $connectivity = [];

            foreach ($services as $serviceName => $serviceUrl) {
                try {
                    $response = Http::timeout(10)->withHeaders([
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/json'
                    ])->get($serviceUrl . '/api/health');

                    $connectivity[$serviceName] = [
                        'url' => $serviceUrl,
                        'status' => $response->successful() ? 'connected' : 'failed',
                        'response_code' => $response->status(),
                        'response_time' => $response->handlerStats()['total_time'] ?? 'unknown'
                    ];

                } catch (\Exception $e) {
                    $connectivity[$serviceName] = [
                        'url' => $serviceUrl,
                        'status' => 'error',
                        'error' => $e->getMessage()
                    ];
                }
            }

            $overallHealth = true;
            foreach ($connectivity as $service) {
                if ($service['status'] !== 'connected') {
                    $overallHealth = false;
                    break;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Service connectivity test completed',
                'overall_health' => $overallHealth ? 'healthy' : 'degraded',
                'services' => $connectivity,
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Service connectivity test failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function testMediaTokenValidation(Request $request): JsonResponse
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

            return response()->json([
                'success' => true,
                'message' => 'Token validation successful in media service',
                'media_service_status' => 'connected',
                'user_data' => $user,
                'token_info' => [
                    'token_preview' => substr($token, 0, 10) . '...',
                    'validated_at' => now()->toISOString()
                ],
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Media token validation test failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function testServiceIntegration(Request $request): JsonResponse
    {
        try {
            $token = $request->bearerToken();
            $user = $request->attributes->get('user');

            if (!$token || !$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }

            $userId = $user['user_id'];
            $testResults = [];

            // Test if other services can access media service endpoints
            $mediaServiceUrl = env('APP_URL', 'http://fitnease-media');
            try {
                $mediaResponse = Http::timeout(10)->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json'
                ])->get($mediaServiceUrl . '/api/media/videos/1');

                $testResults['media_service_access'] = [
                    'videos_accessible' => $mediaResponse->successful() ? 'success' : 'failed',
                    'response_code' => $mediaResponse->status(),
                    'service_response' => $mediaResponse->successful() ? 'accessible' : 'rejected'
                ];
            } catch (\Exception $e) {
                $testResults['media_service_access'] = [
                    'videos_accessible' => 'error',
                    'error' => $e->getMessage()
                ];
            }

            // Test auth service connectivity
            $authServiceUrl = env('AUTH_SERVICE_URL', 'http://fitnease-auth');
            try {
                $authResponse = Http::timeout(10)->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json'
                ])->get($authServiceUrl . '/api/auth/user-profile/' . $userId);

                $testResults['auth_service'] = [
                    'communication_accepted' => $authResponse->successful() ? 'success' : 'failed',
                    'response_code' => $authResponse->status(),
                    'service_response' => $authResponse->successful() ? 'accessible' : 'rejected'
                ];
            } catch (\Exception $e) {
                $testResults['auth_service'] = [
                    'communication_accepted' => 'error',
                    'error' => $e->getMessage()
                ];
            }

            // Test content service connectivity
            $contentServiceUrl = env('CONTENT_SERVICE_URL', 'http://fitnease-content');
            try {
                $contentResponse = Http::timeout(10)->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json'
                ])->get($contentServiceUrl . '/api/health');

                $testResults['content_service'] = [
                    'connectivity' => $contentResponse->successful() ? 'success' : 'failed',
                    'response_code' => $contentResponse->status()
                ];
            } catch (\Exception $e) {
                $testResults['content_service'] = [
                    'connectivity' => 'error',
                    'error' => $e->getMessage()
                ];
            }

            $overallSuccess = true;
            foreach ($testResults as $test) {
                foreach ($test as $status) {
                    if ($status === 'failed' || $status === 'error') {
                        $overallSuccess = false;
                        break 2;
                    }
                }
            }

            return response()->json([
                'success' => $overallSuccess,
                'message' => $overallSuccess ? 'Service integration test completed successfully' : 'Service integration test encountered issues',
                'test_results' => $testResults,
                'media_service_info' => [
                    'service' => 'fitnease-media',
                    'user_id' => $userId,
                    'token_valid' => true
                ],
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Service integration test failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}