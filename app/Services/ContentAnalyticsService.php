<?php

namespace App\Services;

use App\Models\MediaFile;
use App\Models\VideoContent;
use App\Models\AudioContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ContentAnalyticsService
{
    public function trackContentUsage(int $mediaFileId, ?int $userId, string $event, array $metadata = []): void
    {
        $usage = [
            'media_file_id' => $mediaFileId,
            'user_id' => $userId,
            'event_type' => $event, // 'view', 'play', 'pause', 'complete', 'skip'
            'timestamp' => now(),
            'metadata' => $metadata, // duration, position, quality, etc.
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ];

        $this->storeAnalyticsEvent($usage);

        if ($event === 'view') {
            $this->incrementViewCount($mediaFileId);
        } elseif ($event === 'complete') {
            $this->trackCompletion($mediaFileId, $userId);
        }
    }

    public function generateContentPerformanceReport(int $dateRange = 30): array
    {
        $startDate = Carbon::now()->subDays($dateRange);

        $popularVideos = VideoContent::select([
                'video_content.*',
                'media_files.file_size_bytes',
                'media_files.original_file_name',
                'media_files.created_at as upload_date'
            ])
            ->join('media_files', 'video_content.media_file_id', '=', 'media_files.media_file_id')
            ->where('media_files.created_at', '>=', $startDate)
            ->orderBy('view_count', 'desc')
            ->limit(10)
            ->get();

        $engagementMetrics = $this->calculateEngagementMetrics($startDate);
        $qualityMetrics = $this->getQualityMetrics($startDate);
        $storageEfficiency = $this->getStorageEfficiency();
        $cdnPerformance = $this->getCDNPerformanceMetrics($startDate);

        return [
            'period' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => now()->toDateString(),
                'days' => $dateRange
            ],
            'popular_content' => $popularVideos->map(function ($video) {
                return [
                    'video_id' => $video->video_id,
                    'title' => $video->video_title,
                    'view_count' => $video->view_count,
                    'average_rating' => $video->average_rating,
                    'file_size' => $this->formatBytes($video->file_size_bytes),
                    'upload_date' => $video->upload_date,
                    'type' => $video->video_type,
                    'difficulty' => $video->difficulty_level
                ];
            }),
            'engagement' => $engagementMetrics,
            'quality_performance' => $qualityMetrics,
            'storage_efficiency' => $storageEfficiency,
            'cdn_performance' => $cdnPerformance
        ];
    }

    public function getPopularContent(int $limit = 10): array
    {
        $popularVideos = VideoContent::with('mediaFile')
            ->whereHas('mediaFile', function ($query) {
                $query->where('is_active', true)
                      ->where('upload_status', 'ready');
            })
            ->orderBy('view_count', 'desc')
            ->limit($limit)
            ->get();

        $popularAudio = AudioContent::with('mediaFile')
            ->whereHas('mediaFile', function ($query) {
                $query->where('is_active', true)
                      ->where('upload_status', 'ready');
            })
            ->orderBy('play_count', 'desc')
            ->limit($limit)
            ->get();

        return [
            'videos' => $popularVideos->map(function ($video) {
                return [
                    'video_id' => $video->video_id,
                    'title' => $video->video_title,
                    'view_count' => $video->view_count,
                    'rating' => $video->average_rating,
                    'duration' => $video->duration_formatted,
                    'file_size' => $video->mediaFile->file_size_human
                ];
            }),
            'audio' => $popularAudio->map(function ($audio) {
                return [
                    'audio_id' => $audio->audio_id,
                    'title' => $audio->audio_title,
                    'play_count' => $audio->play_count,
                    'duration' => $audio->duration_formatted,
                    'genre' => $audio->genre,
                    'file_size' => $audio->mediaFile->file_size_human
                ];
            })
        ];
    }

    public function getServiceHealthMetrics(): array
    {
        return [
            'storage_usage' => $this->getStorageUsage(),
            'streaming_performance' => $this->getStreamingMetrics(),
            'cdn_health' => $this->getCDNHealthStatus(),
            'processing_queue' => $this->getProcessingQueueStatus(),
            'error_rates' => $this->getErrorRates(),
            'active_content' => $this->getActiveContentStats(),
            'last_updated' => now()->toISOString()
        ];
    }

    public function getContentStatsByType(): array
    {
        return [
            'videos' => [
                'total' => VideoContent::count(),
                'by_difficulty' => VideoContent::select('difficulty_level', DB::raw('count(*) as count'))
                    ->groupBy('difficulty_level')
                    ->pluck('count', 'difficulty_level'),
                'by_type' => VideoContent::select('video_type', DB::raw('count(*) as count'))
                    ->groupBy('video_type')
                    ->pluck('count', 'video_type'),
                'total_views' => VideoContent::sum('view_count'),
                'average_rating' => VideoContent::avg('average_rating')
            ],
            'audio' => [
                'total' => AudioContent::count(),
                'by_type' => AudioContent::select('audio_type', DB::raw('count(*) as count'))
                    ->groupBy('audio_type')
                    ->pluck('count', 'audio_type'),
                'by_genre' => AudioContent::select('genre', DB::raw('count(*) as count'))
                    ->groupBy('genre')
                    ->pluck('count', 'genre'),
                'total_plays' => AudioContent::sum('play_count'),
                'playlist_eligible' => AudioContent::where('is_playlist_eligible', true)->count()
            ],
            'files' => [
                'total' => MediaFile::count(),
                'by_type' => MediaFile::select('file_type', DB::raw('count(*) as count'))
                    ->groupBy('file_type')
                    ->pluck('count', 'file_type'),
                'by_status' => MediaFile::select('upload_status', DB::raw('count(*) as count'))
                    ->groupBy('upload_status')
                    ->pluck('count', 'upload_status'),
                'total_size' => MediaFile::sum('file_size_bytes'),
                'active_files' => MediaFile::where('is_active', true)->count()
            ]
        ];
    }

    public function getUserEngagementReport(int $userId, int $days = 30): array
    {
        $startDate = Carbon::now()->subDays($days);

        // This would typically query a user_analytics table
        // For now, we'll return a structure showing what data would be available

        return [
            'user_id' => $userId,
            'period' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => now()->toDateString(),
                'days' => $days
            ],
            'video_engagement' => [
                'total_views' => 0, // Would be calculated from analytics events
                'unique_videos_watched' => 0,
                'total_watch_time' => 0,
                'completion_rate' => 0,
                'favorite_difficulty' => null,
                'favorite_type' => null
            ],
            'audio_engagement' => [
                'total_plays' => 0,
                'unique_audio_played' => 0,
                'total_listen_time' => 0,
                'favorite_genre' => null,
                'favorite_type' => null
            ],
            'most_watched_content' => [],
            'recent_activity' => []
        ];
    }

    private function storeAnalyticsEvent(array $usage): void
    {
        try {
            // In a real implementation, this would store in a dedicated analytics table
            // or send to an analytics service like Google Analytics, Mixpanel, etc.
            Log::info('Content usage tracked', $usage);

            // You could also store in a database table like:
            // DB::table('content_analytics')->insert($usage);

        } catch (\Exception $e) {
            Log::error('Failed to store analytics event', ['error' => $e->getMessage()]);
        }
    }

    private function incrementViewCount(int $mediaFileId): void
    {
        $mediaFile = MediaFile::find($mediaFileId);

        if ($mediaFile && $mediaFile->file_type === 'video') {
            VideoContent::where('media_file_id', $mediaFileId)->increment('view_count');
        } elseif ($mediaFile && $mediaFile->file_type === 'audio') {
            AudioContent::where('media_file_id', $mediaFileId)->increment('play_count');
        }
    }

    private function trackCompletion(int $mediaFileId, ?int $userId): void
    {
        // Track completion events for engagement metrics
        $completionData = [
            'media_file_id' => $mediaFileId,
            'user_id' => $userId,
            'completed_at' => now(),
            'completion_type' => 'full' // could be 'partial', 'skipped', etc.
        ];

        Log::info('Content completion tracked', $completionData);
    }

    private function calculateEngagementMetrics(Carbon $startDate): array
    {
        // These calculations would be based on stored analytics events
        return [
            'total_views' => VideoContent::where('created_at', '>=', $startDate)->sum('view_count'),
            'total_plays' => AudioContent::where('created_at', '>=', $startDate)->sum('play_count'),
            'average_session_duration' => 0, // Would calculate from events
            'completion_rate' => 0, // Would calculate from events
            'bounce_rate' => 0, // Would calculate from events
            'peak_usage_hours' => $this->identifyPeakUsageHours()
        ];
    }

    private function getQualityMetrics(Carbon $startDate): array
    {
        $videos = VideoContent::join('media_files', 'video_content.media_file_id', '=', 'media_files.media_file_id')
            ->where('media_files.created_at', '>=', $startDate)
            ->select('video_quality', DB::raw('count(*) as count'), DB::raw('sum(view_count) as total_views'))
            ->groupBy('video_quality')
            ->get();

        $audio = AudioContent::join('media_files', 'audio_content.media_file_id', '=', 'media_files.media_file_id')
            ->where('media_files.created_at', '>=', $startDate)
            ->select('audio_quality', DB::raw('count(*) as count'), DB::raw('sum(play_count) as total_plays'))
            ->groupBy('audio_quality')
            ->get();

        return [
            'video_quality_distribution' => $videos->pluck('count', 'video_quality'),
            'video_quality_views' => $videos->pluck('total_views', 'video_quality'),
            'audio_quality_distribution' => $audio->pluck('count', 'audio_quality'),
            'audio_quality_plays' => $audio->pluck('total_plays', 'audio_quality')
        ];
    }

    private function getStorageEfficiency(): array
    {
        $totalSize = MediaFile::where('is_active', true)->sum('file_size_bytes');
        $totalFiles = MediaFile::where('is_active', true)->count();

        return [
            'total_storage_used' => $this->formatBytes($totalSize),
            'total_storage_bytes' => $totalSize,
            'total_files' => $totalFiles,
            'average_file_size' => $totalFiles > 0 ? $this->formatBytes($totalSize / $totalFiles) : '0 B',
            'storage_by_type' => MediaFile::where('is_active', true)
                ->select('file_type', DB::raw('sum(file_size_bytes) as total_size'))
                ->groupBy('file_type')
                ->get()
                ->mapWithKeys(function ($item) {
                    return [$item->file_type => $this->formatBytes($item->total_size)];
                })
        ];
    }

    private function getStorageUsage(): array
    {
        $totalSize = MediaFile::where('is_active', true)->sum('file_size_bytes');
        $storageLimit = config('media.storage_limit_bytes', 100 * 1024 * 1024 * 1024); // 100GB default

        return [
            'used_bytes' => $totalSize,
            'used_formatted' => $this->formatBytes($totalSize),
            'available_bytes' => $storageLimit - $totalSize,
            'available_formatted' => $this->formatBytes($storageLimit - $totalSize),
            'usage_percentage' => $storageLimit > 0 ? round(($totalSize / $storageLimit) * 100, 2) : 0,
            'files_count' => MediaFile::where('is_active', true)->count()
        ];
    }

    private function getCDNPerformanceMetrics(Carbon $startDate): array
    {
        // This would typically integrate with your CDN provider's API
        return [
            'total_requests' => 0,
            'cache_hit_ratio' => 0,
            'average_response_time' => 0,
            'bandwidth_usage' => '0 GB',
            'error_rate' => 0,
            'top_requested_content' => []
        ];
    }

    private function getCDNHealthStatus(): array
    {
        $endpoints = config('media.cdn_endpoints', []);
        $healthStatus = [];

        foreach ($endpoints as $region => $endpoint) {
            try {
                $client = new \GuzzleHttp\Client();
                $response = $client->get($endpoint . '/health', ['timeout' => 5]);

                $healthStatus[$region] = [
                    'status' => 'healthy',
                    'response_time' => $response->getHeader('X-Response-Time')[0] ?? 'unknown',
                    'last_checked' => now()->toISOString()
                ];
            } catch (\Exception $e) {
                $healthStatus[$region] = [
                    'status' => 'unhealthy',
                    'error' => $e->getMessage(),
                    'last_checked' => now()->toISOString()
                ];
            }
        }

        return $healthStatus;
    }

    private function getStreamingMetrics(): array
    {
        return [
            'active_streams' => 0, // Would track from streaming events
            'peak_concurrent_streams' => 0,
            'average_bitrate' => '2.5 Mbps',
            'buffer_health' => 98.5, // percentage
            'stream_failures' => 0
        ];
    }

    private function getProcessingQueueStatus(): array
    {
        $uploadingFiles = MediaFile::where('upload_status', 'uploading')->count();
        $processingFiles = MediaFile::where('upload_status', 'processing')->count();
        $failedFiles = MediaFile::where('upload_status', 'failed')->count();

        return [
            'uploading' => $uploadingFiles,
            'processing' => $processingFiles,
            'failed' => $failedFiles,
            'queue_healthy' => $processingFiles < 10, // Example threshold
            'average_processing_time' => '45 seconds' // Would calculate from historical data
        ];
    }

    private function getErrorRates(): array
    {
        $total = MediaFile::count();
        $failed = MediaFile::where('upload_status', 'failed')->count();

        return [
            'upload_failure_rate' => $total > 0 ? round(($failed / $total) * 100, 2) : 0,
            'processing_failure_rate' => 0, // Would calculate from processing logs
            'streaming_error_rate' => 0, // Would calculate from streaming logs
            'total_errors_24h' => 0 // Would query from error logs
        ];
    }

    private function getActiveContentStats(): array
    {
        return [
            'total_active_files' => MediaFile::where('is_active', true)->count(),
            'ready_for_streaming' => MediaFile::where('upload_status', 'ready')->count(),
            'public_content' => MediaFile::where('is_public', true)->count(),
            'featured_videos' => VideoContent::where('is_featured', true)->count()
        ];
    }

    private function identifyPeakUsageHours(): array
    {
        // This would analyze usage patterns from stored analytics
        // For now, return example data
        return [
            'peak_hour' => 19, // 7 PM
            'peak_day' => 'Sunday',
            'usage_pattern' => 'evening_focused'
        ];
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}