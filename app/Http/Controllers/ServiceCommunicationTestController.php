<?php

namespace App\Http\Controllers;

use App\Models\VideoContent;
use App\Models\MediaFile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ServiceCommunicationTestController extends Controller
{
    /**
     * Test endpoint for Content Service to get exercise videos
     * Pattern: GET /media/videos/{exerciseId}
     */
    public function getExerciseVideosForContentService(Request $request, int $exerciseId): JsonResponse
    {
        Log::info('Content Service requesting exercise videos', [
            'exercise_id' => $exerciseId,
            'caller_service' => 'fitnease-content',
            'user_id' => $request->attributes->get('user_id'),
            'timestamp' => now()
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
                'service_communication' => 'Content Service → Media Service',
                'endpoint' => 'GET /media/videos/' . $exerciseId,
                'exercise_id' => $exerciseId,
                'videos_found' => $videos->count(),
                'data' => $responseData
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to serve exercise videos to Content Service', [
                'exercise_id' => $exerciseId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving exercise videos for Content Service',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test endpoint for ML Service to get personalized recommendations
     * Pattern: GET /media/videos/recommendations/{userId}
     */
    public function getPersonalizedRecommendationsForMLService(Request $request, int $userId): JsonResponse
    {
        Log::info('ML Service requesting personalized recommendations', [
            'target_user_id' => $userId,
            'caller_service' => 'fitnease-ml',
            'requesting_user_id' => $request->attributes->get('user_id'),
            'timestamp' => now()
        ]);

        try {
            // Simulate ML-powered recommendations based on user preferences
            $recommendedVideos = VideoContent::with('mediaFile')
                ->whereHas('mediaFile', function ($query) {
                    $query->where('is_active', true)
                          ->where('upload_status', 'ready');
                })
                ->where('is_featured', true) // Start with featured content
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
                    'instructor' => $video->instructor_name
                ];
            });

            return response()->json([
                'success' => true,
                'service_communication' => 'ML Service → Media Service',
                'endpoint' => 'GET /media/videos/recommendations/' . $userId,
                'target_user_id' => $userId,
                'recommendations_count' => $recommendedVideos->count(),
                'algorithm_note' => 'Using featured content and popularity metrics for demo',
                'data' => $responseData
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to serve recommendations to ML Service', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error generating personalized recommendations for ML Service',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test endpoint for mobile app streaming
     * Pattern: GET /media/stream/{videoId}
     */
    public function streamVideoForMobileApp(Request $request, int $videoId): JsonResponse
    {
        Log::info('Mobile App requesting video stream', [
            'video_id' => $videoId,
            'caller_service' => 'mobile-app',
            'user_id' => $request->attributes->get('user_id'),
            'user_agent' => $request->header('User-Agent'),
            'timestamp' => now()
        ]);

        try {
            $video = VideoContent::with('mediaFile')->find($videoId);

            if (!$video) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video not found'
                ], 404);
            }

            // Generate streaming token (demo implementation)
            $streamingToken = base64_encode(json_encode([
                'video_id' => $videoId,
                'user_id' => $request->attributes->get('user_id'),
                'expires_at' => now()->addHours(2)->toISOString()
            ]));

            return response()->json([
                'success' => true,
                'service_communication' => 'Mobile App → Media Service',
                'endpoint' => 'GET /media/stream/' . $videoId,
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
                        'thumbnail' => $video->mediaFile->thumbnail_url
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to serve video stream to Mobile App', [
                'video_id' => $videoId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error generating video stream for Mobile App',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Monitor all incoming service communications
     */
    public function getServiceCommunicationLogs(Request $request): JsonResponse
    {
        // This would typically read from actual logs, but for demo we'll return sample data
        $logs = [
            [
                'timestamp' => now()->subMinutes(5),
                'caller_service' => 'fitnease-content',
                'endpoint' => 'GET /media/videos/123',
                'status' => 'success',
                'response_time_ms' => 45
            ],
            [
                'timestamp' => now()->subMinutes(3),
                'caller_service' => 'fitnease-ml',
                'endpoint' => 'GET /media/videos/recommendations/456',
                'status' => 'success',
                'response_time_ms' => 120
            ],
            [
                'timestamp' => now()->subMinutes(1),
                'caller_service' => 'mobile-app',
                'endpoint' => 'GET /media/stream/789',
                'status' => 'success',
                'response_time_ms' => 89
            ]
        ];

        return response()->json([
            'success' => true,
            'message' => 'Service communication monitoring dashboard',
            'active_integrations' => [
                'fitnease-content' => 'Exercise video delivery',
                'fitnease-ml' => 'Personalized recommendations',
                'mobile-app' => 'Direct media streaming',
                'fitnease-auth' => 'User validation (outgoing)'
            ],
            'recent_communications' => $logs,
            'health_status' => [
                'content_service_calls' => 'healthy',
                'ml_service_calls' => 'healthy',
                'mobile_app_streaming' => 'healthy',
                'auth_service_validation' => 'healthy'
            ]
        ]);
    }
}