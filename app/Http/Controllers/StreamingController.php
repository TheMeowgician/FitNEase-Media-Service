<?php

namespace App\Http\Controllers;

use App\Models\VideoContent;
use App\Models\AudioContent;
use App\Models\MediaFile;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class StreamingController extends Controller
{
    public function streamVideo(int $videoId, Request $request): Response
    {
        try {
            $video = VideoContent::with('mediaFile')->find($videoId);

            if (!$video || !$video->mediaFile) {
                return response('Video not found', 404);
            }

            if ($video->mediaFile->upload_status !== 'ready') {
                return response('Video not ready for streaming', 423);
            }

            $this->validateStreamingAccess($video->mediaFile, $request);

            $filePath = Storage::path($video->mediaFile->file_path);

            if (!file_exists($filePath)) {
                return response('Video file not found', 404);
            }

            $this->trackContentUsage($video->mediaFile->media_file_id, auth()->id(), 'play');

            return $this->streamFile($filePath, $video->mediaFile->mime_type, $request);

        } catch (\Exception $e) {
            return response('Streaming error: ' . $e->getMessage(), 500);
        }
    }

    public function streamAudio(int $audioId, Request $request): Response
    {
        try {
            $audio = AudioContent::with('mediaFile')->find($audioId);

            if (!$audio || !$audio->mediaFile) {
                return response('Audio not found', 404);
            }

            if ($audio->mediaFile->upload_status !== 'ready') {
                return response('Audio not ready for streaming', 423);
            }

            $this->validateStreamingAccess($audio->mediaFile, $request);

            $filePath = Storage::path($audio->mediaFile->file_path);

            if (!file_exists($filePath)) {
                return response('Audio file not found', 404);
            }

            $this->trackContentUsage($audio->mediaFile->media_file_id, auth()->id(), 'play');

            return $this->streamFile($filePath, $audio->mediaFile->mime_type, $request);

        } catch (\Exception $e) {
            return response('Streaming error: ' . $e->getMessage(), 500);
        }
    }

    public function generateStreamingManifest(int $videoId, Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $video = VideoContent::with('mediaFile')->find($videoId);

            if (!$video) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video not found'
                ], 404);
            }

            $userConnection = $request->input('connection', 'medium');
            $qualityVersions = $this->getVideoQualityVersions($video->media_file_id);
            $availableQualities = $this->filterQualitiesByConnection($qualityVersions, $userConnection);

            $manifestUrl = $this->generateHLSManifest($video, $availableQualities);

            return response()->json([
                'success' => true,
                'data' => [
                    'manifest_url' => $manifestUrl,
                    'available_qualities' => array_keys($availableQualities),
                    'recommended_quality' => $this->getRecommendedQuality($userConnection),
                    'expires_at' => now()->addHours(2)->toISOString()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Manifest generation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getStreamingToken(int $mediaFileId): \Illuminate\Http\JsonResponse
    {
        try {
            $mediaFile = MediaFile::find($mediaFileId);

            if (!$mediaFile) {
                return response()->json([
                    'success' => false,
                    'message' => 'Media file not found'
                ], 404);
            }

            $this->validateStreamingAccess($mediaFile, request());

            $token = JWT::encode([
                'media_file_id' => $mediaFileId,
                'user_id' => auth()->id(),
                'exp' => time() + (2 * 60 * 60),
                'iat' => time()
            ], env('STREAMING_SECRET', 'default-secret'), 'HS256');

            return response()->json([
                'success' => true,
                'data' => [
                    'streaming_token' => $token,
                    'expires_at' => now()->addHours(2)->toISOString(),
                    'streaming_url' => $this->buildStreamingUrl($mediaFile, $token)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token generation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function streamFile(string $filePath, string $mimeType, Request $request): Response
    {
        $fileSize = filesize($filePath);
        $start = 0;
        $end = $fileSize - 1;

        $headers = [
            'Content-Type' => $mimeType,
            'Accept-Ranges' => 'bytes',
            'Content-Length' => $fileSize,
        ];

        if ($request->hasHeader('Range')) {
            $range = $request->header('Range');

            if (preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
                $start = intval($matches[1]);
                $end = $matches[2] ? intval($matches[2]) : $end;
            }

            $length = $end - $start + 1;

            $headers['Content-Range'] = "bytes $start-$end/$fileSize";
            $headers['Content-Length'] = $length;

            $response = response()->stream(function () use ($filePath, $start, $length) {
                $handle = fopen($filePath, 'rb');
                fseek($handle, $start);
                echo fread($handle, $length);
                fclose($handle);
            }, 206, $headers);
        } else {
            $response = response()->stream(function () use ($filePath) {
                readfile($filePath);
            }, 200, $headers);
        }

        return $response;
    }

    private function validateStreamingAccess(MediaFile $mediaFile, Request $request): void
    {
        if (!$mediaFile->is_active) {
            throw new \Exception('Media file is not active');
        }

        if (!$mediaFile->is_public && !auth()->check()) {
            throw new \Exception('Authentication required');
        }

        $token = $request->input('token') ?? $request->bearerToken();

        if ($token) {
            try {
                $decoded = JWT::decode($token, new Key(env('STREAMING_SECRET', 'default-secret'), 'HS256'));

                if ($decoded->media_file_id !== $mediaFile->media_file_id) {
                    throw new \Exception('Invalid token for this media file');
                }

                if ($decoded->exp < time()) {
                    throw new \Exception('Token has expired');
                }
            } catch (\Exception $e) {
                throw new \Exception('Invalid streaming token: ' . $e->getMessage());
            }
        }

        if (!$mediaFile->is_public && $mediaFile->uploaded_by !== auth()->id()) {
            $hasPermission = $this->checkUserPermissions(auth()->id(), $mediaFile);

            if (!$hasPermission) {
                throw new \Exception('Insufficient permissions');
            }
        }
    }

    private function checkUserPermissions(int $userId, MediaFile $mediaFile): bool
    {
        if ($mediaFile->uploaded_by === $userId) {
            return true;
        }

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->get(env('AUTH_SERVICE_URL') . '/auth/user-subscription/' . $userId);
            $subscription = json_decode($response->getBody(), true);

            if ($subscription['has_premium'] && $mediaFile->entity_type === 'premium_content') {
                return true;
            }
        } catch (\Exception $e) {
            \Log::warning('Failed to check user permissions', ['error' => $e->getMessage()]);
        }

        return false;
    }

    private function trackContentUsage(int $mediaFileId, ?int $userId, string $event): void
    {
        try {
            $usage = [
                'media_file_id' => $mediaFileId,
                'user_id' => $userId,
                'event_type' => $event,
                'timestamp' => now(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent()
            ];

            \Log::info('Content usage tracked', $usage);

            if ($event === 'play') {
                $mediaFile = MediaFile::find($mediaFileId);

                if ($mediaFile->file_type === 'video') {
                    VideoContent::where('media_file_id', $mediaFileId)->increment('view_count');
                } elseif ($mediaFile->file_type === 'audio') {
                    AudioContent::where('media_file_id', $mediaFileId)->increment('play_count');
                }
            }

        } catch (\Exception $e) {
            \Log::error('Failed to track content usage', ['error' => $e->getMessage()]);
        }
    }

    private function getVideoQualityVersions(int $mediaFileId): array
    {
        $qualities = ['480p', '720p', '1080p'];
        $versions = [];

        foreach ($qualities as $quality) {
            $qualityPath = "processed/{$mediaFileId}/{$quality}.mp4";

            if (Storage::exists($qualityPath)) {
                $versions[$quality] = $qualityPath;
            }
        }

        return $versions;
    }

    private function filterQualitiesByConnection(array $qualities, string $connection): array
    {
        $connectionLimits = [
            'slow' => ['480p'],
            'medium' => ['480p', '720p'],
            'fast' => ['480p', '720p', '1080p']
        ];

        $allowedQualities = $connectionLimits[$connection] ?? ['480p'];

        return array_filter($qualities, function ($quality) use ($allowedQualities) {
            return in_array($quality, $allowedQualities);
        }, ARRAY_FILTER_USE_KEY);
    }

    private function getRecommendedQuality(string $connection): string
    {
        return match ($connection) {
            'slow' => '480p',
            'medium' => '720p',
            'fast' => '1080p',
            default => '720p'
        };
    }

    private function generateHLSManifest(VideoContent $video, array $availableQualities): string
    {
        $manifestPath = "manifests/{$video->media_file_id}/playlist.m3u8";

        $manifestContent = "#EXTM3U\n#EXT-X-VERSION:3\n\n";

        foreach ($availableQualities as $quality => $path) {
            $bandwidth = $this->getQualityBandwidth($quality);
            $resolution = $this->getQualityResolution($quality);

            $manifestContent .= "#EXT-X-STREAM-INF:BANDWIDTH={$bandwidth},RESOLUTION={$resolution}\n";
            $manifestContent .= "quality_{$quality}.m3u8\n";
        }

        Storage::put($manifestPath, $manifestContent);

        return Storage::url($manifestPath);
    }

    private function getQualityBandwidth(string $quality): int
    {
        return match ($quality) {
            '480p' => 1000000,
            '720p' => 2500000,
            '1080p' => 5000000,
            default => 2500000
        };
    }

    private function getQualityResolution(string $quality): string
    {
        return match ($quality) {
            '480p' => '854x480',
            '720p' => '1280x720',
            '1080p' => '1920x1080',
            default => '1280x720'
        };
    }

    private function buildStreamingUrl(MediaFile $mediaFile, string $token): string
    {
        if ($mediaFile->file_type === 'video') {
            return route('media.stream.video', ['videoId' => $mediaFile->videoContent->video_id]) . '?token=' . $token;
        }

        if ($mediaFile->file_type === 'audio') {
            return route('media.stream.audio', ['audioId' => $mediaFile->audioContent->audio_id]) . '?token=' . $token;
        }

        return '';
    }
}
