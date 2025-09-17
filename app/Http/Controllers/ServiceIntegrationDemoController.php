<?php

namespace App\Http\Controllers;

use App\Models\VideoContent;
use App\Models\MediaFile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ServiceIntegrationDemoController extends Controller
{
    /**
     * Demo endpoint showing what Content Service would receive
     * Bypasses auth for demonstration purposes
     */
    public function demoContentServiceCall(int $exerciseId): JsonResponse
    {
        Log::info('DEMO: Content Service requesting exercise videos', [
            'exercise_id' => $exerciseId,
            'caller_service' => 'fitnease-content',
            'demo_mode' => true
        ]);

        try {
            $videos = VideoContent::with('mediaFile')
                ->byExercise($exerciseId)
                ->whereHas('mediaFile', function ($query) {
                    $query->where('is_active', true)
                          ->where('upload_status', 'ready');
                })
                ->orderBy('video_type')
                ->orderBy('is_featured', 'desc')
                ->get();

            $responseData = $videos->map(function ($video) {
                return [
                    'video_id' => $video->video_id,
                    'media_file_id' => $video->media_file_id,
                    'title' => $video->video_title,
                    'description' => $video->video_description,
                    'duration' => $video->duration_formatted,
                    'type' => $video->video_type,
                    'quality' => $video->video_quality,
                    'instructor' => $video->instructor_name,
                    'difficulty' => $video->difficulty_level,
                    'view_count' => $video->view_count,
                    'rating' => $video->average_rating,
                    'is_featured' => $video->is_featured,
                    'thumbnail_url' => $video->mediaFile->thumbnail_url,
                    'streaming_url' => $video->mediaFile->streaming_url
                ];
            });

            return response()->json([
                'success' => true,
                'demo_communication' => 'Content Service → Media Service',
                'endpoint_called' => 'GET /media/videos/' . $exerciseId,
                'authentication' => 'Bypassed for demo (normally requires valid Bearer token)',
                'exercise_id' => $exerciseId,
                'videos_found' => $videos->count(),
                'data' => $responseData,
                'service_integration_pattern' => [
                    'caller' => 'fitnease-content',
                    'purpose' => 'Get instructional videos for exercises',
                    'expected_usage' => 'Content service calls this to display exercise videos in workout details',
                    'authentication_required' => true,
                    'response_format' => 'Video metadata with streaming URLs'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error in Content Service communication demo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Demo endpoint showing what ML Service would receive for recommendations
     */
    public function demoMLServiceCall(int $userId): JsonResponse
    {
        Log::info('DEMO: ML Service requesting personalized recommendations', [
            'target_user_id' => $userId,
            'caller_service' => 'fitnease-ml',
            'demo_mode' => true
        ]);

        try {
            $recommendedVideos = VideoContent::with('mediaFile')
                ->whereHas('mediaFile', function ($query) {
                    $query->where('is_active', true)
                          ->where('upload_status', 'ready');
                })
                ->where('is_featured', true)
                ->orderBy('view_count', 'desc')
                ->orderBy('average_rating', 'desc')
                ->limit(10)
                ->get();

            $responseData = $recommendedVideos->map(function ($video) {
                return [
                    'video_id' => $video->video_id,
                    'title' => $video->video_title,
                    'description' => $video->video_description,
                    'thumbnail_url' => $video->mediaFile->thumbnail_url,
                    'streaming_url' => $video->mediaFile->streaming_url,
                    'duration' => $video->duration_seconds,
                    'difficulty' => $video->difficulty_level,
                    'type' => $video->video_type,
                    'view_count' => $video->view_count,
                    'rating' => $video->average_rating,
                    'instructor' => $video->instructor_name,
                    'exercise_id' => $video->exercise_id
                ];
            });

            return response()->json([
                'success' => true,
                'demo_communication' => 'ML Service → Media Service',
                'endpoint_called' => 'GET /media/videos/recommendations/' . $userId,
                'authentication' => 'Bypassed for demo (normally requires valid Bearer token)',
                'target_user_id' => $userId,
                'recommendations_count' => $recommendedVideos->count(),
                'data' => $responseData,
                'service_integration_pattern' => [
                    'caller' => 'fitnease-ml',
                    'purpose' => 'Get personalized video recommendations based on ML algorithms',
                    'expected_usage' => 'ML service calls this to get video data for recommendation algorithms',
                    'authentication_required' => true,
                    'response_format' => 'Video recommendations with metadata and engagement metrics'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error in ML Service communication demo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Demo endpoint showing streaming for mobile app
     */
    public function demoMobileAppStreaming(int $videoId): JsonResponse
    {
        Log::info('DEMO: Mobile App requesting video stream', [
            'video_id' => $videoId,
            'caller_service' => 'mobile-app',
            'demo_mode' => true
        ]);

        try {
            $video = VideoContent::with('mediaFile')->find($videoId);

            if (!$video) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video not found'
                ], 404);
            }

            $streamingToken = base64_encode(json_encode([
                'video_id' => $videoId,
                'user_id' => 'demo_user',
                'expires_at' => now()->addHours(2)->toISOString()
            ]));

            return response()->json([
                'success' => true,
                'demo_communication' => 'Mobile App → Media Service',
                'endpoint_called' => 'GET /media/stream/' . $videoId,
                'authentication' => 'Bypassed for demo (normally requires valid Bearer token)',
                'video_id' => $videoId,
                'streaming_data' => [
                    'streaming_url' => env('CDN_BASE_URL') . '/stream/' . $videoId,
                    'streaming_token' => $streamingToken,
                    'expires_at' => now()->addHours(2)->toISOString(),
                    'available_qualities' => ['480p', '720p', '1080p'],
                    'recommended_quality' => '720p',
                    'video_info' => [
                        'title' => $video->video_title,
                        'duration' => $video->duration_seconds,
                        'thumbnail' => $video->mediaFile->thumbnail_url,
                        'instructor' => $video->instructor_name,
                        'type' => $video->video_type
                    ]
                ],
                'service_integration_pattern' => [
                    'caller' => 'mobile-app',
                    'purpose' => 'Direct media streaming to mobile applications',
                    'expected_usage' => 'Mobile app calls this to get streaming URLs and tokens for video playback',
                    'authentication_required' => true,
                    'response_format' => 'Streaming URLs with time-limited tokens and quality options'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error in Mobile App streaming demo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Overview of all service integrations with the media service
     */
    public function getServiceIntegrationOverview(): JsonResponse
    {
        $integrations = [
            'incoming_communications' => [
                'fitnease-content' => [
                    'purpose' => 'Retrieve instructional videos for exercises',
                    'endpoints_called' => [
                        'GET /media/videos/{exerciseId}',
                        'GET /media/video/details/{videoId}',
                        'GET /media/videos/by-difficulty/{level}',
                        'GET /media/videos/by-type/{type}'
                    ],
                    'authentication' => 'Bearer token required',
                    'response_format' => 'Video metadata with streaming URLs'
                ],
                'fitnease-ml' => [
                    'purpose' => 'Get video data for personalized recommendations',
                    'endpoints_called' => [
                        'GET /media/videos/recommendations/{userId}',
                        'GET /media/videos/featured'
                    ],
                    'authentication' => 'Bearer token required',
                    'response_format' => 'Video recommendations with engagement metrics'
                ],
                'mobile-app' => [
                    'purpose' => 'Direct media streaming to mobile devices',
                    'endpoints_called' => [
                        'GET /media/stream/{videoId}',
                        'GET /media/stream/audio/{audioId}',
                        'GET /media/thumbnail/{fileId}',
                        'GET /media/download/{fileId}'
                    ],
                    'authentication' => 'Bearer token required',
                    'response_format' => 'Streaming URLs with time-limited tokens'
                ]
            ],
            'outgoing_communications' => [
                'fitnease-auth' => [
                    'purpose' => 'Validate user authentication and permissions',
                    'endpoints_called' => [
                        'GET /auth/validate'
                    ],
                    'trigger' => 'Every authenticated request to media service',
                    'implementation' => 'ValidateApiToken middleware'
                ],
                'fitnease-engagement' => [
                    'purpose' => 'Track video viewing analytics',
                    'endpoints_called' => [
                        'POST /engagement/analytics/video-view'
                    ],
                    'trigger' => 'When videos are viewed',
                    'implementation' => 'EngagementService class'
                ],
                'fitnease-content' => [
                    'purpose' => 'Notify about media uploads for exercises',
                    'endpoints_called' => [
                        'POST /content/exercises/{exerciseId}/media'
                    ],
                    'trigger' => 'When media is uploaded for exercises',
                    'implementation' => 'ContentService class'
                ]
            ]
        ];

        return response()->json([
            'success' => true,
            'message' => 'FitNEase Media Service Integration Overview',
            'service_name' => 'fitnease-media',
            'architecture_pattern' => 'Microservice with API-based communication',
            'authentication_method' => 'Bearer token validation via Auth Service',
            'integrations' => $integrations,
            'demo_endpoints' => [
                'content_service_demo' => '/demo/content-service/videos/{exerciseId}',
                'ml_service_demo' => '/demo/ml-service/recommendations/{userId}',
                'mobile_app_demo' => '/demo/mobile-app/stream/{videoId}',
                'integration_overview' => '/demo/integrations'
            ]
        ]);
    }
}