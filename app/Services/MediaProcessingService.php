<?php

namespace App\Services;

use App\Models\MediaFile;
use App\Models\VideoContent;
use App\Models\AudioContent;
use App\Models\FileMetadata;
use App\Events\MediaProcessingCompleted;
use App\Events\MediaUploadFailed;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class MediaProcessingService
{
    public function processUploadedMedia(int $mediaFileId): void
    {
        $mediaFile = MediaFile::find($mediaFileId);

        if (!$mediaFile) {
            Log::error('Media file not found for processing', ['media_file_id' => $mediaFileId]);
            return;
        }

        try {
            $mediaFile->update(['upload_status' => 'processing']);

            if ($mediaFile->file_type === 'video') {
                $this->processVideo($mediaFile);
            } elseif ($mediaFile->file_type === 'audio') {
                $this->processAudio($mediaFile);
            }

            $mediaFile->update(['upload_status' => 'ready']);
            event(new MediaProcessingCompleted($mediaFile));

        } catch (\Exception $e) {
            $this->handleProcessingError($mediaFile, $e->getMessage());
        }
    }

    private function processVideo(MediaFile $mediaFile): void
    {
        $validation = $this->validateVideoFile($mediaFile);
        if (!$validation['valid']) {
            throw new \Exception('Video validation failed: ' . implode(', ', $validation['errors']));
        }

        $metadata = $this->extractVideoMetadata($mediaFile);
        $this->saveVideoMetadata($mediaFile->media_file_id, $metadata);

        $thumbnails = $this->generateVideoThumbnails($mediaFile);
        $mediaFile->update(['thumbnail_path' => $thumbnails['primary']]);

        $qualityVersions = $this->createQualityVersions($mediaFile);

        if ($mediaFile->cdn_url) {
            $cdnUrls = $this->uploadToCDN($mediaFile, $qualityVersions);
            $mediaFile->update(['cdn_url' => $cdnUrls['primary']]);
        }
    }

    private function processAudio(MediaFile $mediaFile): void
    {
        $validation = $this->validateAudioFile($mediaFile);
        if (!$validation['valid']) {
            throw new \Exception('Audio validation failed: ' . implode(', ', $validation['errors']));
        }

        $metadata = $this->extractAudioMetadata($mediaFile);
        $this->saveAudioMetadata($mediaFile->media_file_id, $metadata);

        $qualityVersions = $this->createAudioQualityVersions($mediaFile);

        if ($mediaFile->cdn_url) {
            $cdnUrls = $this->uploadToCDN($mediaFile, $qualityVersions);
            $mediaFile->update(['cdn_url' => $cdnUrls['primary']]);
        }
    }

    private function validateVideoFile(MediaFile $mediaFile): array
    {
        $errors = [];
        $filePath = Storage::path($mediaFile->file_path);

        if (!file_exists($filePath)) {
            $errors[] = 'File does not exist';
            return ['valid' => false, 'errors' => $errors];
        }

        if ($mediaFile->file_size_bytes > 500 * 1024 * 1024) { // 500MB limit
            $errors[] = 'File size exceeds 500MB limit';
        }

        $allowedTypes = ['video/mp4', 'video/avi', 'video/mov', 'video/wmv'];
        if (!in_array($mediaFile->mime_type, $allowedTypes)) {
            $errors[] = 'Unsupported video format';
        }

        try {
            $duration = $this->getVideoDuration($filePath);
            if ($duration > 3600) { // 1 hour limit
                $errors[] = 'Video duration exceeds 1 hour limit';
            }
        } catch (\Exception $e) {
            $errors[] = 'Unable to read video properties';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    private function validateAudioFile(MediaFile $mediaFile): array
    {
        $errors = [];
        $filePath = Storage::path($mediaFile->file_path);

        if (!file_exists($filePath)) {
            $errors[] = 'File does not exist';
            return ['valid' => false, 'errors' => $errors];
        }

        if ($mediaFile->file_size_bytes > 100 * 1024 * 1024) { // 100MB limit
            $errors[] = 'File size exceeds 100MB limit';
        }

        $allowedTypes = ['audio/mp3', 'audio/wav', 'audio/aac', 'audio/ogg'];
        if (!in_array($mediaFile->mime_type, $allowedTypes)) {
            $errors[] = 'Unsupported audio format';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    private function extractVideoMetadata(MediaFile $mediaFile): array
    {
        $filePath = Storage::path($mediaFile->file_path);
        $metadata = [];

        try {
            // Extract basic video information using FFprobe (if available)
            $command = "ffprobe -v quiet -print_format json -show_format -show_streams " . escapeshellarg($filePath);
            $output = shell_exec($command);

            if ($output) {
                $videoInfo = json_decode($output, true);

                if (isset($videoInfo['format'])) {
                    $metadata['duration'] = floatval($videoInfo['format']['duration'] ?? 0);
                    $metadata['bitrate'] = intval($videoInfo['format']['bit_rate'] ?? 0);
                    $metadata['format_name'] = $videoInfo['format']['format_name'] ?? '';
                }

                foreach ($videoInfo['streams'] ?? [] as $stream) {
                    if ($stream['codec_type'] === 'video') {
                        $metadata['width'] = intval($stream['width'] ?? 0);
                        $metadata['height'] = intval($stream['height'] ?? 0);
                        $metadata['codec'] = $stream['codec_name'] ?? '';
                        $metadata['fps'] = $this->calculateFrameRate($stream);
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to extract video metadata', ['error' => $e->getMessage()]);

            // Fallback to basic file info
            $metadata['file_size'] = $mediaFile->file_size_bytes;
            $metadata['mime_type'] = $mediaFile->mime_type;
        }

        return $metadata;
    }

    private function extractAudioMetadata(MediaFile $mediaFile): array
    {
        $filePath = Storage::path($mediaFile->file_path);
        $metadata = [];

        try {
            $command = "ffprobe -v quiet -print_format json -show_format -show_streams " . escapeshellarg($filePath);
            $output = shell_exec($command);

            if ($output) {
                $audioInfo = json_decode($output, true);

                if (isset($audioInfo['format'])) {
                    $metadata['duration'] = floatval($audioInfo['format']['duration'] ?? 0);
                    $metadata['bitrate'] = intval($audioInfo['format']['bit_rate'] ?? 0);
                }

                foreach ($audioInfo['streams'] ?? [] as $stream) {
                    if ($stream['codec_type'] === 'audio') {
                        $metadata['codec'] = $stream['codec_name'] ?? '';
                        $metadata['sample_rate'] = intval($stream['sample_rate'] ?? 0);
                        $metadata['channels'] = intval($stream['channels'] ?? 0);
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to extract audio metadata', ['error' => $e->getMessage()]);

            $metadata['file_size'] = $mediaFile->file_size_bytes;
            $metadata['mime_type'] = $mediaFile->mime_type;
        }

        return $metadata;
    }

    private function saveVideoMetadata(int $mediaFileId, array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            $type = $this->determineMetadataType($value);

            FileMetadata::updateOrCreate(
                [
                    'media_file_id' => $mediaFileId,
                    'metadata_key' => $key
                ],
                [
                    'metadata_value' => (string) $value,
                    'metadata_type' => $type
                ]
            );
        }
    }

    private function saveAudioMetadata(int $mediaFileId, array $metadata): void
    {
        $this->saveVideoMetadata($mediaFileId, $metadata); // Same implementation
    }

    private function generateVideoThumbnails(MediaFile $mediaFile): array
    {
        $filePath = Storage::path($mediaFile->file_path);
        $thumbnailDir = 'thumbnails/' . $mediaFile->media_file_id;

        try {
            $primaryThumbnail = $thumbnailDir . '/primary.jpg';
            $this->extractVideoFrame($filePath, 3, Storage::path($primaryThumbnail));

            $previewThumbnails = [];
            $videoDuration = $this->getVideoDuration($filePath);
            $intervalSeconds = max(1, $videoDuration / 10);

            for ($i = 0; $i < 10; $i++) {
                $timestamp = $i * $intervalSeconds;
                $thumbnailPath = $thumbnailDir . '/preview_' . $i . '.jpg';
                $this->extractVideoFrame($filePath, $timestamp, Storage::path($thumbnailPath));
                $previewThumbnails[] = $thumbnailPath;
            }

            return [
                'primary' => $primaryThumbnail,
                'previews' => $previewThumbnails
            ];

        } catch (\Exception $e) {
            Log::error('Failed to generate video thumbnails', ['error' => $e->getMessage()]);
            return ['primary' => null, 'previews' => []];
        }
    }

    private function createQualityVersions(MediaFile $mediaFile): array
    {
        $inputPath = Storage::path($mediaFile->file_path);
        $outputDir = 'processed/' . $mediaFile->media_file_id;

        $qualities = [
            '480p' => ['width' => 854, 'height' => 480, 'bitrate' => '1000k'],
            '720p' => ['width' => 1280, 'height' => 720, 'bitrate' => '2500k'],
            '1080p' => ['width' => 1920, 'height' => 1080, 'bitrate' => '5000k']
        ];

        $versions = [];

        foreach ($qualities as $quality => $settings) {
            try {
                $outputPath = $outputDir . '/' . $quality . '.mp4';
                $fullOutputPath = Storage::path($outputPath);

                // Ensure directory exists
                $directory = dirname($fullOutputPath);
                if (!is_dir($directory)) {
                    mkdir($directory, 0755, true);
                }

                $command = sprintf(
                    'ffmpeg -i %s -vf scale=%d:%d -b:v %s -c:v libx264 -c:a aac %s',
                    escapeshellarg($inputPath),
                    $settings['width'],
                    $settings['height'],
                    $settings['bitrate'],
                    escapeshellarg($fullOutputPath)
                );

                exec($command, $output, $returnCode);

                if ($returnCode === 0 && file_exists($fullOutputPath)) {
                    $versions[$quality] = $outputPath;
                }

            } catch (\Exception $e) {
                Log::warning("Failed to create {$quality} version", ['error' => $e->getMessage()]);
            }
        }

        return $versions;
    }

    private function createAudioQualityVersions(MediaFile $mediaFile): array
    {
        $inputPath = Storage::path($mediaFile->file_path);
        $outputDir = 'processed/' . $mediaFile->media_file_id;

        $qualities = [
            '128kbps' => ['bitrate' => '128k'],
            '256kbps' => ['bitrate' => '256k'],
            '320kbps' => ['bitrate' => '320k']
        ];

        $versions = [];

        foreach ($qualities as $quality => $settings) {
            try {
                $outputPath = $outputDir . '/' . $quality . '.mp3';
                $fullOutputPath = Storage::path($outputPath);

                $directory = dirname($fullOutputPath);
                if (!is_dir($directory)) {
                    mkdir($directory, 0755, true);
                }

                $command = sprintf(
                    'ffmpeg -i %s -b:a %s %s',
                    escapeshellarg($inputPath),
                    $settings['bitrate'],
                    escapeshellarg($fullOutputPath)
                );

                exec($command, $output, $returnCode);

                if ($returnCode === 0 && file_exists($fullOutputPath)) {
                    $versions[$quality] = $outputPath;
                }

            } catch (\Exception $e) {
                Log::warning("Failed to create {$quality} version", ['error' => $e->getMessage()]);
            }
        }

        return $versions;
    }

    private function uploadToCDN(MediaFile $mediaFile, array $qualityVersions): array
    {
        // Placeholder for CDN upload logic
        // In a real implementation, this would upload files to your CDN
        $cdnUrls = [];

        try {
            $baseUrl = env('CDN_BASE_URL', 'https://cdn.fitnease.com');
            $cdnUrls['primary'] = $baseUrl . '/' . $mediaFile->file_path;

            foreach ($qualityVersions as $quality => $path) {
                $cdnUrls[$quality] = $baseUrl . '/' . $path;
            }

        } catch (\Exception $e) {
            Log::error('CDN upload failed', ['error' => $e->getMessage()]);
        }

        return $cdnUrls;
    }

    private function extractVideoFrame(string $videoPath, float $timestamp, string $outputPath): void
    {
        $directory = dirname($outputPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $command = sprintf(
            'ffmpeg -i %s -ss %s -vframes 1 %s',
            escapeshellarg($videoPath),
            $timestamp,
            escapeshellarg($outputPath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception('Failed to extract video frame');
        }
    }

    private function getVideoDuration(string $videoPath): float
    {
        $command = "ffprobe -v quiet -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($videoPath);
        $duration = shell_exec($command);

        return floatval(trim($duration));
    }

    private function calculateFrameRate(array $stream): float
    {
        if (isset($stream['r_frame_rate'])) {
            $parts = explode('/', $stream['r_frame_rate']);
            if (count($parts) === 2 && $parts[1] > 0) {
                return floatval($parts[0]) / floatval($parts[1]);
            }
        }

        return 0.0;
    }

    private function determineMetadataType($value): string
    {
        if (is_int($value)) {
            return 'integer';
        }

        if (is_float($value)) {
            return 'decimal';
        }

        if (is_bool($value)) {
            return 'boolean';
        }

        if (is_array($value)) {
            return 'json';
        }

        return 'string';
    }

    private function handleProcessingError(MediaFile $mediaFile, string $error): void
    {
        $mediaFile->update(['upload_status' => 'failed']);

        Log::error('Media processing failed', [
            'media_file_id' => $mediaFile->media_file_id,
            'error' => $error,
            'file_type' => $mediaFile->file_type,
            'file_size' => $mediaFile->file_size_bytes
        ]);

        event(new MediaUploadFailed($mediaFile));

        $this->cleanupFailedProcessing($mediaFile);
    }

    private function cleanupFailedProcessing(MediaFile $mediaFile): void
    {
        try {
            if ($mediaFile->thumbnail_path && Storage::exists($mediaFile->thumbnail_path)) {
                Storage::delete($mediaFile->thumbnail_path);
            }

            $processedDir = 'processed/' . $mediaFile->media_file_id;
            if (Storage::exists($processedDir)) {
                Storage::deleteDirectory($processedDir);
            }

        } catch (\Exception $e) {
            Log::warning('Failed to cleanup processing files', ['error' => $e->getMessage()]);
        }
    }
}