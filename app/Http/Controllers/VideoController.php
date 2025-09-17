<?php

namespace App\Http\Controllers;

use App\Models\VideoContent;
use App\Models\MediaFile;
use App\Services\EngagementService;
use App\Services\ContentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use GuzzleHttp\Client;

class VideoController extends Controller
{
    public function getByExercise(int $exerciseId): JsonResponse
    {
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

            return response()->json([
                'success' => true,
                'data' => $videos->map(function ($video) {
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
                })
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving exercise videos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, int $videoId): JsonResponse
    {
        try {
            $video = VideoContent::with(['mediaFile', 'mediaFile.metadata'])
                ->where('video_id', $videoId)
                ->first();

            if (!$video) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video not found'
                ], 404);
            }

            $video->incrementViewCount();

            // Track video view in engagement service
            $userId = $request->attributes->get('user_id');
            $token = $request->bearerToken();

            if ($userId && $token) {
                $engagementService = new EngagementService();
                $engagementService->trackVideoView($token, $userId, $videoId);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'video_id' => $video->video_id,
                    'media_file_id' => $video->media_file_id,
                    'exercise_id' => $video->exercise_id,
                    'title' => $video->video_title,
                    'description' => $video->video_description,
                    'duration' => $video->duration_formatted,
                    'duration_seconds' => $video->duration_seconds,
                    'type' => $video->video_type,
                    'quality' => $video->video_quality,
                    'instructor' => $video->instructor_name,
                    'difficulty' => $video->difficulty_level,
                    'view_count' => $video->view_count,
                    'rating' => $video->average_rating,
                    'is_featured' => $video->is_featured,
                    'file_info' => [
                        'file_name' => $video->mediaFile->original_file_name,
                        'file_size' => $video->mediaFile->file_size_human,
                        'mime_type' => $video->mediaFile->mime_type,
                        'thumbnail_url' => $video->mediaFile->thumbnail_url,
                        'streaming_url' => $video->mediaFile->streaming_url
                    ],
                    'metadata' => $video->mediaFile->metadata->pluck('value', 'metadata_key'),
                    'created_at' => $video->created_at,
                    'updated_at' => $video->updated_at
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving video details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function rate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'video_id' => 'required|integer|exists:video_content,video_id',
            'rating' => 'required|numeric|min:1|max:5'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $video = VideoContent::find($request->video_id);
            $newRating = $request->rating;

            $currentRating = $video->average_rating;
            $viewCount = $video->view_count;

            $totalRatingPoints = $currentRating * $viewCount;
            $newTotalPoints = $totalRatingPoints + $newRating;
            $newAverageRating = $newTotalPoints / ($viewCount + 1);

            $video->update(['average_rating' => round($newAverageRating, 2)]);

            return response()->json([
                'success' => true,
                'message' => 'Video rated successfully',
                'data' => [
                    'video_id' => $video->video_id,
                    'new_rating' => $video->average_rating,
                    'total_ratings' => $viewCount + 1
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Rating failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getFeatured(): JsonResponse
    {
        try {
            $videos = VideoContent::with('mediaFile')
                ->featured()
                ->whereHas('mediaFile', function ($query) {
                    $query->where('is_active', true)
                          ->where('upload_status', 'ready');
                })
                ->orderBy('average_rating', 'desc')
                ->orderBy('view_count', 'desc')
                ->limit(10)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $videos->map(function ($video) {
                    return [
                        'video_id' => $video->video_id,
                        'title' => $video->video_title,
                        'description' => $video->video_description,
                        'duration' => $video->duration_formatted,
                        'type' => $video->video_type,
                        'instructor' => $video->instructor_name,
                        'difficulty' => $video->difficulty_level,
                        'view_count' => $video->view_count,
                        'rating' => $video->average_rating,
                        'thumbnail_url' => $video->mediaFile->thumbnail_url,
                        'streaming_url' => $video->mediaFile->streaming_url
                    ];
                })
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving featured videos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getByDifficulty(string $level): JsonResponse
    {
        $validLevels = ['beginner', 'medium', 'expert'];

        if (!in_array($level, $validLevels)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid difficulty level. Must be: ' . implode(', ', $validLevels)
            ], 400);
        }

        try {
            $videos = VideoContent::with('mediaFile')
                ->byDifficulty($level)
                ->whereHas('mediaFile', function ($query) {
                    $query->where('is_active', true)
                          ->where('upload_status', 'ready');
                })
                ->orderBy('average_rating', 'desc')
                ->orderBy('view_count', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $videos->map(function ($video) {
                    return [
                        'video_id' => $video->video_id,
                        'title' => $video->video_title,
                        'description' => $video->video_description,
                        'duration' => $video->duration_formatted,
                        'type' => $video->video_type,
                        'instructor' => $video->instructor_name,
                        'difficulty' => $video->difficulty_level,
                        'view_count' => $video->view_count,
                        'rating' => $video->average_rating,
                        'thumbnail_url' => $video->mediaFile->thumbnail_url,
                        'streaming_url' => $video->mediaFile->streaming_url
                    ];
                })
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving videos by difficulty',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getByType(string $type): JsonResponse
    {
        $validTypes = ['instruction', 'form_guide', 'demonstration', 'tips', 'warm_up', 'cool_down'];

        if (!in_array($type, $validTypes)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid video type. Must be: ' . implode(', ', $validTypes)
            ], 400);
        }

        try {
            $videos = VideoContent::with('mediaFile')
                ->byType($type)
                ->whereHas('mediaFile', function ($query) {
                    $query->where('is_active', true)
                          ->where('upload_status', 'ready');
                })
                ->orderBy('average_rating', 'desc')
                ->orderBy('view_count', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $videos->map(function ($video) {
                    return [
                        'video_id' => $video->video_id,
                        'title' => $video->video_title,
                        'description' => $video->video_description,
                        'duration' => $video->duration_formatted,
                        'type' => $video->video_type,
                        'instructor' => $video->instructor_name,
                        'difficulty' => $video->difficulty_level,
                        'view_count' => $video->view_count,
                        'rating' => $video->average_rating,
                        'thumbnail_url' => $video->mediaFile->thumbnail_url,
                        'streaming_url' => $video->mediaFile->streaming_url
                    ];
                })
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving videos by type',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'media_file_id' => 'required|integer|exists:media_files,media_file_id',
            'exercise_id' => 'nullable|integer',
            'video_title' => 'required|string|max:255',
            'video_description' => 'nullable|string',
            'video_type' => 'nullable|in:instruction,form_guide,demonstration,tips,warm_up,cool_down',
            'instructor_name' => 'nullable|string|max:100',
            'difficulty_level' => 'nullable|in:beginner,medium,expert',
            'is_featured' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $mediaFile = MediaFile::where('media_file_id', $request->media_file_id)
                ->where('file_type', 'video')
                ->first();

            if (!$mediaFile) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video media file not found'
                ], 404);
            }

            $videoContent = VideoContent::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Video content created successfully',
                'data' => $videoContent->load('mediaFile')
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Video content creation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getPersonalizedRecommendations(int $userId): JsonResponse
    {
        try {
            $authClient = new Client();
            $userResponse = $authClient->get(env('AUTH_SERVICE_URL') . '/auth/user-profile/' . $userId);
            $userData = json_decode($userResponse->getBody(), true);

            $mlClient = new Client();
            $mlResponse = $mlClient->get(env('ML_SERVICE_URL') . '/api/v1/content-recommendations/' . $userId);
            $mlRecommendations = json_decode($mlResponse->getBody(), true);

            $recommendedVideos = VideoContent::with('mediaFile')
                ->whereIn('exercise_id', $mlRecommendations['exercise_ids'] ?? [])
                ->where('difficulty_level', $userData['fitness_level'] ?? 'beginner')
                ->whereHas('mediaFile', function ($query) {
                    $query->where('is_active', true)
                          ->where('upload_status', 'ready');
                })
                ->orderBy('view_count', 'desc')
                ->limit(10)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $recommendedVideos->map(function ($video) {
                    return [
                        'video_id' => $video->video_id,
                        'title' => $video->video_title,
                        'description' => $video->video_description,
                        'thumbnail_url' => $video->mediaFile->thumbnail_url,
                        'streaming_url' => $video->mediaFile->streaming_url,
                        'duration' => $video->duration_formatted,
                        'difficulty' => $video->difficulty_level,
                        'type' => $video->video_type
                    ];
                })
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error getting personalized recommendations',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
