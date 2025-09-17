<?php

namespace App\Http\Middleware;

use App\Models\MediaFile;
use App\Models\VideoContent;
use App\Models\AudioContent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use GuzzleHttp\Client;

class ValidateContentAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $mediaFileId = $this->extractMediaFileId($request);

            if (!$mediaFileId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Media file ID is required'
                ], 400);
            }

            $mediaFile = MediaFile::find($mediaFileId);

            if (!$mediaFile) {
                return response()->json([
                    'success' => false,
                    'message' => 'Media file not found'
                ], 404);
            }

            $accessValidation = $this->validateContentAccess($mediaFile, auth()->id());

            if (!$accessValidation['access']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied',
                    'reason' => $accessValidation['reason']
                ], 403);
            }

            // Add media file to request for use in controller
            $request->merge(['validated_media_file' => $mediaFile]);

            // Log access attempt
            $this->logAccessAttempt($mediaFile, auth()->id(), true);

            return $next($request);

        } catch (\Exception $e) {
            Log::error('Content access validation failed', [
                'error' => $e->getMessage(),
                'request_path' => $request->path(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Access validation failed'
            ], 500);
        }
    }

    private function extractMediaFileId(Request $request): ?int
    {
        // Try to get media file ID from various sources
        if ($fileId = $request->route('fileId')) {
            return intval($fileId);
        }

        if ($mediaFileId = $request->route('mediaFileId')) {
            return intval($mediaFileId);
        }

        // For video/audio streaming, get media file ID from content record
        if ($videoId = $request->route('videoId')) {
            $video = VideoContent::find($videoId);
            return $video ? $video->media_file_id : null;
        }

        if ($audioId = $request->route('audioId')) {
            $audio = AudioContent::find($audioId);
            return $audio ? $audio->media_file_id : null;
        }

        // Try to get from request body
        if ($request->has('media_file_id')) {
            return intval($request->input('media_file_id'));
        }

        return null;
    }

    private function validateContentAccess(MediaFile $mediaFile, ?int $userId): array
    {
        // Check if media file is active
        if (!$mediaFile->is_active) {
            return ['access' => false, 'reason' => 'Content is not active'];
        }

        // Check if content is ready for access
        if ($mediaFile->upload_status !== 'ready') {
            return ['access' => false, 'reason' => 'Content is not ready'];
        }

        // Public content is accessible to everyone
        if ($mediaFile->is_public) {
            return ['access' => true, 'reason' => 'Public content'];
        }

        // Authentication required for private content
        if (!$userId) {
            return ['access' => false, 'reason' => 'Authentication required'];
        }

        // Content owner has full access
        if ($mediaFile->uploaded_by === $userId) {
            return ['access' => true, 'reason' => 'Content owner'];
        }

        // Check user permissions
        $hasPermission = $this->checkUserPermissions($userId, $mediaFile);

        return [
            'access' => $hasPermission,
            'reason' => $hasPermission ? 'Access granted' : 'Insufficient permissions'
        ];
    }

    private function checkUserPermissions(int $userId, MediaFile $mediaFile): bool
    {
        try {
            // Check if user has valid authentication
            if (!$this->validateUser($userId)) {
                return false;
            }

            // Get user subscription status
            $userSubscription = $this->getUserSubscriptionStatus($userId);

            // Check content-specific permissions
            if ($this->isPremiulmContent($mediaFile) && !$userSubscription['has_premium']) {
                return false;
            }

            // Check entity-specific permissions
            if ($mediaFile->entity_type === 'exercise') {
                return $this->hasExerciseAccess($userId, $mediaFile->entity_id);
            }

            if ($mediaFile->entity_type === 'workout') {
                return $this->hasWorkoutAccess($userId, $mediaFile->entity_id);
            }

            // Default to allowing access for authenticated users
            return true;

        } catch (\Exception $e) {
            Log::warning('Failed to check user permissions', [
                'user_id' => $userId,
                'media_file_id' => $mediaFile->media_file_id,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    private function validateUser(int $userId): bool
    {
        try {
            $client = new Client();
            $response = $client->get(env('AUTH_SERVICE_URL') . '/auth/validate/' . $userId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . request()->bearerToken(),
                    'Accept' => 'application/json'
                ],
                'timeout' => 5
            ]);

            return $response->getStatusCode() === 200;

        } catch (\Exception $e) {
            Log::warning('User validation failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    private function getUserSubscriptionStatus(int $userId): array
    {
        try {
            $client = new Client();
            $response = $client->get(env('AUTH_SERVICE_URL') . '/auth/user-subscription/' . $userId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . request()->bearerToken(),
                    'Accept' => 'application/json'
                ],
                'timeout' => 5
            ]);

            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody(), true);
            }

        } catch (\Exception $e) {
            Log::warning('Failed to get user subscription status', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }

        return ['has_premium' => false, 'subscription_type' => 'free'];
    }

    private function isPremiulmContent(MediaFile $mediaFile): bool
    {
        return in_array($mediaFile->entity_type, ['premium_content', 'premium_exercise', 'premium_workout']);
    }

    private function hasExerciseAccess(int $userId, ?int $exerciseId): bool
    {
        if (!$exerciseId) {
            return true;
        }

        try {
            $client = new Client();
            $response = $client->get(env('CONTENT_SERVICE_URL') . '/exercises/' . $exerciseId . '/access/' . $userId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . request()->bearerToken(),
                    'Accept' => 'application/json'
                ],
                'timeout' => 5
            ]);

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody(), true);
                return $data['has_access'] ?? false;
            }

        } catch (\Exception $e) {
            Log::warning('Failed to check exercise access', [
                'user_id' => $userId,
                'exercise_id' => $exerciseId,
                'error' => $e->getMessage()
            ]);
        }

        return false;
    }

    private function hasWorkoutAccess(int $userId, ?int $workoutId): bool
    {
        if (!$workoutId) {
            return true;
        }

        try {
            $client = new Client();
            $response = $client->get(env('CONTENT_SERVICE_URL') . '/workouts/' . $workoutId . '/access/' . $userId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . request()->bearerToken(),
                    'Accept' => 'application/json'
                ],
                'timeout' => 5
            ]);

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody(), true);
                return $data['has_access'] ?? false;
            }

        } catch (\Exception $e) {
            Log::warning('Failed to check workout access', [
                'user_id' => $userId,
                'workout_id' => $workoutId,
                'error' => $e->getMessage()
            ]);
        }

        return false;
    }

    private function logAccessAttempt(MediaFile $mediaFile, ?int $userId, bool $granted): void
    {
        try {
            Log::info('Content access attempt', [
                'media_file_id' => $mediaFile->media_file_id,
                'file_type' => $mediaFile->file_type,
                'user_id' => $userId,
                'access_granted' => $granted,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'timestamp' => now()->toISOString()
            ]);

            // Track analytics event
            if ($granted) {
                app('App\Services\ContentAnalyticsService')->trackContentUsage(
                    $mediaFile->media_file_id,
                    $userId,
                    'access_granted',
                    [
                        'access_method' => 'middleware_validation',
                        'content_type' => $mediaFile->file_type
                    ]
                );
            }

        } catch (\Exception $e) {
            Log::warning('Failed to log access attempt', [
                'media_file_id' => $mediaFile->media_file_id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
